<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects, at the moment a request has died of PHP's time limit, the live
 * facts ABJ_404_Solution_TimeLimitBreakdown needs: the recorder's plugin and
 * hook data paired with the live hook registry, each callback's owner, the
 * wall and CPU clocks, the limit, and display names for the owners involved.
 *
 * Runs only from the shutdown path of a fatal (the recovery-email filter,
 * the error-page filter, the fatal processor), never on a healthy request.
 * Reads memory, the clocks, getrusage(), plugin/theme headers of the owners
 * involved, and nothing else. Never throws: an unavailable piece becomes
 * null in the input, and the breakdown says less instead of saying
 * something wrong.
 *
 * @phpstan-import-type Owner from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type CallbackRow from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-type Fatal array{message: string, file: string, line: int, limit_s: int}
 */
final class ABJ_404_Solution_TimeLimitEvidence {

    /** Pattern of PHP's time-limit fatal; group 1 is the limit in seconds. */
    const MESSAGE_PATTERN = '/^Maximum execution time of (\d+) seconds? exceeded/';

    /**
     * error_get_last()'s record as a Fatal when it is a PHP time-limit fatal,
     * else null. The one place that reads the raw record: everything after
     * this takes the Fatal, whose limit, file and line are already typed.
     *
     * @param mixed $error
     * @return Fatal|null
     */
    public static function parseFatal($error): ?array {
        if (!is_array($error) || ($error['type'] ?? null) !== E_ERROR
                || !isset($error['message']) || !is_string($error['message'])
                || preg_match(self::MESSAGE_PATTERN, $error['message'], $match) !== 1) {
            return null;
        }
        return array(
            'message' => $error['message'],
            'file' => isset($error['file']) && is_string($error['file']) ? $error['file'] : '',
            'line' => isset($error['line']) && is_int($error['line']) ? $error['line'] : 0,
            'limit_s' => (int)$match[1],
        );
    }

    /**
     * The breakdown input for the request that is dying now.
     *
     * @param Fatal $fatal from parseFatal().
     * @param ABJ_404_Solution_CodeOwnerResolver $resolver
     * @return array<string, mixed>
     */
    public static function collect(array $fatal, ABJ_404_Solution_CodeOwnerResolver $resolver): array {
        $nowAbsNs = ABJ_404_Solution_RequestTimeRecorder::nowNs();
        $recorderElapsed = ABJ_404_Solution_RequestTimeRecorder::elapsedNs();
        $recording = $recorderElapsed !== null;
        $elapsedMs = $recording ? intdiv($recorderElapsed, 1000000)
            : (ABJ_404_Solution_RequestPhaseTimeline::elapsedSinceBootMs() ?? 0);
        $stack = isset($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])
            ? $GLOBALS['wp_current_filter'] : array();

        $labelsNeeded = array();
        $plugins = array();
        $hooks = array();
        if ($recording) {
            $snapshot = ABJ_404_Solution_RequestTimeRecorder::snapshot();
            foreach ($snapshot['plugins'] as $plugin) {
                $owner = $resolver->ownerOfFile($plugin['path']);
                $labelsNeeded[] = $owner;
                $plugins[] = array('owner' => $owner, 'end_ms' => intdiv($plugin['ns'], 1000000));
            }
            foreach ($snapshot['hooks'] as $name => $hook) {
                $inFlight = in_array($name, $stack, true);
                $lastTick = ABJ_404_Solution_RequestTimeRecorder::lastTickNs($name);
                $hooks[$name] = array(
                    'start_ms' => intdiv($hook['start_ns'], 1000000),
                    'end_ms' => ($inFlight || $lastTick === null) ? null : intdiv($lastTick, 1000000),
                    'cpu_ms' => $hook['cpu_ms'],
                    'in_flight' => $inFlight,
                    'intervals' => $hook['detailed']
                        ? self::intervals($name, $nowAbsNs, $inFlight, $resolver, $labelsNeeded) : null,
                );
            }
        } else {
            $hooks = self::hooksFromPhaseTimeline($stack);
        }

        $theme = null;
        if (function_exists('get_stylesheet')) {
            $theme = array('kind' => 'theme', 'key' => (string)get_stylesheet());
            $labelsNeeded[] = $theme;
        }
        $fatalOwner = $resolver->ownerOfFile($fatal['file']);
        $labelsNeeded[] = $fatalOwner;
        $timeline = ABJ_404_Solution_RequestPhaseTimeline::toArray();

        return array(
            'wall_ms' => self::wallMs(),
            'elapsed_ms' => $elapsedMs,
            'cpu_boot_ms' => ABJ_404_Solution_CpuUsage::totalMs(ABJ_404_Solution_ErrorHandler::bootResourceSnapshot()['usage']),
            'cpu_now_ms' => ABJ_404_Solution_CpuUsage::totalMs(ABJ_404_Solution_PhpRuntimeCapabilityAdapter::resourceUsage()),
            'limit_s' => $fatal['limit_s'],
            'recording' => $recording,
            'plugins' => $plugins,
            'hooks' => $hooks,
            'theme' => $theme,
            'fatal_owner' => $fatalOwner,
            'self_pipeline_start_ms' => $timeline['t']['abj404:404'] ?? null,
            'labels' => self::labels($labelsNeeded),
            'recent_slow' => self::recentSlowCallbacks(),
        );
    }

    /**
     * Callbacks at or over the slow threshold on this request's detailed
     * actions (none in flight), with owners and display names: what a timed
     * request that did not die leaves behind for the admin screen.
     *
     * @param ABJ_404_Solution_CodeOwnerResolver $resolver
     * @return list<CallbackRow>
     */
    public static function slowCallbacks(ABJ_404_Solution_CodeOwnerResolver $resolver): array {
        $nowAbsNs = ABJ_404_Solution_RequestTimeRecorder::nowNs();
        $found = array();
        $owners = array();
        foreach (ABJ_404_Solution_RequestTimeRecorder::snapshot()['hooks'] as $name => $hook) {
            if (!$hook['detailed']) {
                continue;
            }
            foreach (self::intervals($name, $nowAbsNs, false, $resolver, $owners) ?? array() as $interval) {
                $owner = $interval['owner'];
                if ($owner !== null && $interval['ms'] >= ABJ_404_Solution_TimeLimitBreakdown::SLOW_CALLBACK_MS) {
                    $found[] = array('hook' => $name, 'kind' => $owner['kind'], 'key' => $owner['key'], 'label' => '',
                        'callback' => $interval['callback'], 'ms' => $interval['ms'], 'in_flight' => false, 'at' => null);
                }
            }
        }
        $labels = self::labels($owners);
        foreach ($found as $i => $row) {
            $found[$i]['label'] = $labels[ABJ_404_Solution_TimeLimitRecord::ownerKey($row)] ?? $row['key'];
        }
        return $found;
    }

    /**
     * Slow callbacks the armed window or an escalated request found in the
     * last day, newest first, for the email and the admin screen.
     *
     * @return list<CallbackRow>
     */
    private static function recentSlowCallbacks(): array {
        $cutoff = abj_clock()->now() - 86400;
        $recent = array();
        foreach (array_reverse(ABJ_404_Solution_TimeLimitFatalStore::readSlowCallbacks()) as $entry) {
            if (($entry['at'] ?? 0) >= $cutoff) {
                $recent[] = $entry;
            }
        }
        return array_slice($recent, 0, 3);
    }

    /**
     * One hook's recorded intervals with owners resolved. An interval whose
     * callbacks have different owners gets a null owner (unattributed).
     *
     * @param string $hook
     * @param int $nowAbsNs
     * @param bool $inFlight
     * @param ABJ_404_Solution_CodeOwnerResolver $resolver
     * @param list<Owner> $labelsNeeded
     * @return list<array{owner: Owner|null, callback: string, ms: int, in_flight: bool}>|null
     */
    private static function intervals(string $hook, int $nowAbsNs, bool $inFlight,
            ABJ_404_Solution_CodeOwnerResolver $resolver, array &$labelsNeeded): ?array {
        $raw = ABJ_404_Solution_RequestTimeRecorder::intervalsForHook($hook, $nowAbsNs, $inFlight);
        if ($raw === null) {
            return null;
        }
        $intervals = array();
        foreach ($raw as $interval) {
            $owner = null;
            $names = array();
            foreach ($interval['callbacks'] as $callback) {
                $candidate = $resolver->ownerOfCallable($callback);
                $names[] = $resolver->describeCallable($callback);
                if ($owner === null) {
                    $owner = $candidate;
                } elseif ($owner !== $candidate) {
                    $owner = false;
                }
            }
            if (is_array($owner)) {
                $labelsNeeded[] = $owner;
            }
            $intervals[] = array(
                'owner' => is_array($owner) ? $owner : null,
                'callback' => implode(' + ', array_slice($names, 0, 3)),
                'ms' => intdiv($interval['ns'], 1000000),
                'in_flight' => $interval['in_flight'],
            );
        }
        return $intervals;
    }

    /**
     * Hook starts from the always-on phase timeline (wp:<hook> stamps), for
     * a request the recorder did not run on. No intervals, no ends.
     *
     * @param array<int, mixed> $stack
     * @return array<string, array<string, mixed>>
     */
    private static function hooksFromPhaseTimeline(array $stack): array {
        $hooks = array();
        $timeline = ABJ_404_Solution_RequestPhaseTimeline::toArray();
        foreach ($timeline['t'] as $name => $ms) {
            if (strpos($name, 'wp:') !== 0) {
                continue;
            }
            $hook = substr($name, 3);
            $hooks[$hook] = array('start_ms' => $ms, 'end_ms' => null, 'cpu_ms' => null,
                'in_flight' => in_array($hook, $stack, true), 'intervals' => null);
        }
        return $hooks;
    }

    /**
     * Request start (REQUEST_TIME_FLOAT, recorded by the SAPI) to now, in
     * ms, or null when the SAPI gave no start time.
     *
     * @return int|null
     */
    private static function wallMs(): ?int {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (!is_numeric($start)) {
            return null;
        }
        return (int)round((abj_clock()->nowFloat() - (float)$start) * 1000);
    }

    /**
     * Display names for the owners involved: plugin and theme header names,
     * file names otherwise. Only the owners in this breakdown are read.
     *
     * @param list<Owner> $owners
     * @return array<string, string>
     */
    private static function labels(array $owners): array {
        $labels = array();
        $activePlugins = null;
        foreach ($owners as $owner) {
            $key = ABJ_404_Solution_TimeLimitRecord::ownerKey($owner);
            if (isset($labels[$key])) {
                continue;
            }
            $label = '';
            if ($owner['kind'] === 'plugin') {
                if ($activePlugins === null) {
                    $activePlugins = function_exists('get_option') ? get_option('active_plugins', array()) : array();
                    $activePlugins = is_array($activePlugins) ? $activePlugins : array();
                }
                $label = self::pluginName($owner['key'], $activePlugins);
            } elseif ($owner['kind'] === 'theme' && function_exists('wp_get_theme')) {
                $theme = wp_get_theme($owner['key']);
                $name = is_object($theme) ? $theme->get('Name') : '';
                $label = is_string($name) ? $name : '';
            }
            $labels[$key] = $label !== '' ? $label : $owner['key'];
        }
        return $labels;
    }

    /**
     * A plugin's "Plugin Name" header, found through the active plugin whose
     * basename sits under $slug; '' when unknown.
     *
     * @param string $slug
     * @param array<mixed, mixed> $activePlugins
     * @return string
     */
    private static function pluginName(string $slug, array $activePlugins): string {
        if (!defined('WP_PLUGIN_DIR') || !function_exists('get_file_data')) {
            return '';
        }
        foreach ($activePlugins as $basename) {
            if (!is_string($basename) || ($basename !== $slug && strpos($basename, $slug . '/') !== 0)) {
                continue;
            }
            $data = get_file_data(WP_PLUGIN_DIR . '/' . $basename, array('Name' => 'Plugin Name'));
            return isset($data['Name']) && is_string($data['Name']) ? $data['Name'] : '';
        }
        return '';
    }
}
