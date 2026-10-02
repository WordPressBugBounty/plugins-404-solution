<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attribution math for a request that PHP's time limit killed: where did its
 * wall time go, per component, in milliseconds that add up to the whole
 * request.
 *
 * Pure: the input is what ABJ_404_Solution_TimeLimitEvidence collected
 * (plugin include stamps, action starts and ends, per-callback intervals
 * already resolved to owners where callbacks were timed, CPU readings); the
 * output is a ABJ_404_Solution_TimeLimitRecord, which every surface renders
 * (recovery email, error-page sentence, PHP error-log line, admin record).
 *
 * Every millisecond lands in exactly one row:
 * - startup: request start to 404 Solution's own file loading. Proved by the
 *   stamps: the only thing "before 404 Solution loaded" may mean.
 * - one row per owner (plugin, must-use plugin, theme, core, drop-in, 404
 *   Solution): its plugin include time plus its timed callbacks; the theme
 *   also gets its functions.php load window.
 * - hook: an action whose callbacks were not timed one by one, as a total.
 * - main_query / output: windows of core code that run filters from several
 *   owners, which are not timed individually.
 * - phase: only when the recorder was off (mode per_phase).
 * - unattributed: everything else, always shown, never negative (clock
 *   disagreement between the wall and monotonic clocks is clamped).
 *
 * @phpstan-import-type Owner from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type Row from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type Phase from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type CallbackRow from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type DiedIn from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-type Interval array{owner: Owner|null, callback: string, ms: int, in_flight: bool}
 * @phpstan-type PhaseWindow array{startup_ms: int|null, elapsed_ms: int, cpu_boot_ms: int|null, cpu_now_ms: int|null}
 * @phpstan-type CpuSpan array{from_ms: int|null, to_ms: int|null}
 * @phpstan-type Share array{owner: Owner|null, ms: int}
 * @phpstan-type Hook array{start_ms: int, end_ms: int|null, cpu_ms: int|null, in_flight: bool, intervals: list<Interval>|null}
 */
final class ABJ_404_Solution_TimeLimitBreakdown {

    /**
     * How a record's time was measured. Owned here because this class
     * produces the mode; the reporter branches on it (whether to arm the
     * sampling window) and the renderer words its copy by it. The values are
     * persisted in stored records and never change.
     */
    const MODE_PER_CALLBACK = 'per_callback';
    const MODE_PER_ACTION = 'per_action';
    const MODE_MIXED = 'mixed';
    const MODE_PER_PHASE = 'per_phase';

    /** Callbacks at or above this many ms are listed individually. */
    const SLOW_CALLBACK_MS = 250;

    /** At most this many slow callbacks are kept. */
    const MAX_SLOWEST = 5;

    /** Actions after which the page or admin screen is being produced. */
    const ROUTE_HOOKS = array('template_redirect', 'admin_init', 'login_init');

    /**
     * @param array<string, mixed> $input keys wall_ms, elapsed_ms,
     *   cpu_boot_ms, cpu_now_ms, limit_s, recording, plugins, hooks
     *   (intervals null for an action not timed callback by callback),
     *   theme, fatal_owner, self_pipeline_start_ms, labels, recent_slow.
     * @return Record
     */
    public static function build(array $input): array {
        $elapsed = max(0, ABJ_404_Solution_TimeLimitRecord::intValue($input['elapsed_ms'] ?? 0));
        $wall = ABJ_404_Solution_TimeLimitRecord::nullableInt($input['wall_ms'] ?? null);
        $startup = $wall === null ? null : max(0, $wall - $elapsed);
        $total = $wall === null ? $elapsed : max($wall, $elapsed);
        $labels = self::labels($input['labels'] ?? null);
        $hooks = self::hooks($input['hooks'] ?? null);
        $recording = !empty($input['recording']);
        $fatal = ABJ_404_Solution_TimeLimitRecord::owner($input['fatal_owner'] ?? null) ?? array('kind' => 'other', 'key' => '');

        $buckets = self::pluginIncludeBuckets($input['plugins'] ?? null);
        $callbacks = self::callbackRows($hooks, $labels);
        $actions = $recording
            ? self::actionTotals($hooks, $elapsed,
                ABJ_404_Solution_TimeLimitRecord::nullableInt($input['self_pipeline_start_ms'] ?? null), $fatal)
            : array('rows' => self::phaseRows($hooks, $elapsed), 'owned' => array());
        $rows = $actions['rows'];
        foreach (array_merge($callbacks['owned'], $actions['owned']) as $share) {
            self::add($buckets, $share['owner'], $share['ms']);
        }
        self::addThemeLoad($buckets, $hooks, ABJ_404_Solution_TimeLimitRecord::owner($input['theme'] ?? null));
        $rows = self::completeRows(array_merge($rows, self::ownerRows($buckets, $labels),
            self::windowRows($hooks, $elapsed, $startup)), $total);

        $cpuBoot = ABJ_404_Solution_TimeLimitRecord::nullableInt($input['cpu_boot_ms'] ?? null);
        $cpuNow = ABJ_404_Solution_TimeLimitRecord::nullableInt($input['cpu_now_ms'] ?? null);
        return array(
            'v' => ABJ_404_Solution_TimeLimitRecord::VERSION,
            'kind' => 'time_limit',
            'mode' => self::mode($recording, $hooks),
            'limit_s' => ABJ_404_Solution_TimeLimitRecord::nullableInt($input['limit_s'] ?? null),
            'total_ms' => $total,
            'wall_ms' => $wall,
            'startup_ms' => $startup,
            'cpu_ms' => self::cpuDelta(array('from_ms' => $cpuBoot, 'to_ms' => $cpuNow)),
            'abj404_ms' => $buckets['self:404-solution'] ?? 0,
            'rows' => $rows,
            'phases' => self::phases($hooks, array('startup_ms' => $startup, 'elapsed_ms' => $elapsed,
                'cpu_boot_ms' => $cpuBoot, 'cpu_now_ms' => $cpuNow)),
            'slowest' => array_slice($callbacks['slowest'], 0, self::MAX_SLOWEST),
            'died_in' => $callbacks['died_in'] ?? array('hook' => self::innermostHook($hooks), 'kind' => $fatal['kind'],
                'key' => $fatal['key'], 'label' => self::label($fatal, $labels), 'callback' => null),
            'recent_slow' => ABJ_404_Solution_TimeLimitRecord::callbackList($input['recent_slow'] ?? null),
        );
    }

    /**
     * Largest first, then the unattributed remainder (never negative) last.
     *
     * @param list<Row> $rows
     * @param int $total
     * @return list<Row>
     */
    private static function completeRows(array $rows, int $total): array {
        usort($rows, static function (array $a, array $b): int {
            return $b['ms'] <=> $a['ms'];
        });
        $accounted = 0;
        foreach ($rows as $row) {
            $accounted += $row['ms'];
        }
        $rows[] = array('kind' => 'unattributed', 'key' => '', 'label' => '', 'ms' => max(0, $total - $accounted));
        return $rows;
    }

    /**
     * One row per owner bucket.
     *
     * @param array<string, int> $buckets
     * @param array<string, string> $labels
     * @return list<Row>
     */
    private static function ownerRows(array $buckets, array $labels): array {
        $rows = array();
        foreach ($buckets as $ownerKey => $ms) {
            $parts = explode(':', $ownerKey, 2);
            $key = $parts[1] ?? '';
            $rows[] = array('kind' => $parts[0], 'key' => $key,
                'label' => self::label(array('kind' => $parts[0], 'key' => $key), $labels), 'ms' => $ms);
        }
        return $rows;
    }

    /**
     * The theme's functions.php load: from setup_theme ending to
     * after_setup_theme starting (core loads the theme files in between).
     *
     * @param array<string, int> $buckets
     * @param array<string, Hook> $hooks
     * @param Owner|null $theme
     * @return void
     */
    private static function addThemeLoad(array &$buckets, array $hooks, ?array $theme): void {
        if ($theme !== null && isset($hooks['setup_theme'], $hooks['after_setup_theme'])
                && $hooks['setup_theme']['end_ms'] !== null) {
            self::add($buckets, $theme, $hooks['after_setup_theme']['start_ms'] - $hooks['setup_theme']['end_ms']);
        }
    }

    /**
     * Each plugin's include time: the gap between consecutive plugin_loaded
     * stamps (the first is measured from our own boot).
     *
     * @param mixed $plugins
     * @return array<string, int> owner key to ms
     */
    private static function pluginIncludeBuckets($plugins): array {
        $buckets = array();
        $prev = 0;
        foreach (is_array($plugins) ? $plugins : array() as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $end = ABJ_404_Solution_TimeLimitRecord::intValue($plugin['end_ms'] ?? 0);
            self::add($buckets, ABJ_404_Solution_TimeLimitRecord::owner($plugin['owner'] ?? null), $end - $prev);
            $prev = max($prev, $end);
        }
        return $buckets;
    }

    /**
     * Every timed callback interval as its owner's share of the request, plus
     * the slow ones and the one in flight at death. Reads only; the caller
     * adds the shares to the owner buckets.
     *
     * @param array<string, Hook> $hooks
     * @param array<string, string> $labels
     * @return array{slowest: list<CallbackRow>, died_in: DiedIn|null, owned: list<Share>}
     */
    private static function callbackRows(array $hooks, array $labels): array {
        $slowest = array();
        $owned = array();
        $diedIn = null;
        foreach ($hooks as $name => $hook) {
            foreach ($hook['intervals'] ?? array() as $interval) {
                $owned[] = array('owner' => $interval['owner'], 'ms' => $interval['ms']);
                $owner = $interval['owner'] ?? array('kind' => 'mixed', 'key' => '');
                $row = array('hook' => $name, 'kind' => $owner['kind'], 'key' => $owner['key'],
                    'label' => self::label($owner, $labels),
                    'callback' => $interval['callback'], 'ms' => $interval['ms'], 'in_flight' => $interval['in_flight'], 'at' => null);
                if ($interval['ms'] >= self::SLOW_CALLBACK_MS || $interval['in_flight']) {
                    $slowest[] = $row;
                }
                if ($interval['in_flight']) {
                    $diedIn = array('hook' => $name, 'kind' => $row['kind'], 'key' => $row['key'],
                        'label' => $row['label'], 'callback' => $row['callback']);
                }
            }
        }
        usort($slowest, static function (array $a, array $b): int {
            return $b['ms'] <=> $a['ms'];
        });
        return array('slowest' => $slowest, 'died_in' => $diedIn, 'owned' => $owned);
    }

    /**
     * Rows for actions whose callbacks were not timed one by one: the
     * action's span. When the request died inside such an action while 404
     * Solution's own 404 pipeline was running (the pipeline stamps its start
     * on the phase timeline), the pipeline's share moves to 404 Solution and
     * comes back in 'owned' for the caller to add to the owner buckets.
     *
     * @param array<string, Hook> $hooks
     * @param int $elapsed
     * @param int|null $pipelineStart
     * @param Owner $fatal
     * @return array{rows: list<Row>, owned: list<Share>}
     */
    private static function actionTotals(array $hooks, int $elapsed, ?int $pipelineStart, array $fatal): array {
        $rows = array();
        $owned = array();
        foreach ($hooks as $name => $hook) {
            $end = $hook['in_flight'] ? $elapsed : $hook['end_ms'];
            if ($hook['intervals'] !== null || $end === null) {
                continue;
            }
            $span = max(0, $end - $hook['start_ms']);
            if ($hook['in_flight'] && $pipelineStart !== null && $fatal['kind'] === 'self'
                    && $pipelineStart >= $hook['start_ms'] && $pipelineStart <= $end) {
                $owned[] = array('owner' => $fatal, 'ms' => $end - $pipelineStart);
                $span -= $end - $pipelineStart;
            }
            $rows[] = array('kind' => 'hook', 'key' => $name, 'label' => '', 'ms' => $span);
        }
        return array('rows' => $rows, 'owned' => $owned);
    }

    /**
     * With the recorder off, the phase windows are the only rows.
     *
     * @param array<string, Hook> $hooks
     * @param int $elapsed
     * @return list<Row>
     */
    private static function phaseRows(array $hooks, int $elapsed): array {
        $rows = array();
        foreach (self::phases($hooks, array('startup_ms' => null, 'elapsed_ms' => $elapsed,
                'cpu_boot_ms' => null, 'cpu_now_ms' => null)) as $phase) {
            if ($phase['ms'] > 0) {
                $rows[] = array('kind' => 'phase', 'key' => $phase['name'], 'label' => '', 'ms' => $phase['ms']);
            }
        }
        return $rows;
    }

    /**
     * The startup, main-query and output windows that have time in them.
     *
     * @param array<string, Hook> $hooks
     * @param int $elapsed
     * @param int|null $startup
     * @return list<Row>
     */
    private static function windowRows(array $hooks, int $elapsed, ?int $startup): array {
        $rows = array();
        $mainQuery = self::windowMs($hooks, 'wp_loaded', $hooks['wp']['start_ms'] ?? null);
        if ($mainQuery > 0) {
            $rows[] = array('kind' => 'main_query', 'key' => '', 'label' => '', 'ms' => $mainQuery);
        }
        foreach (self::ROUTE_HOOKS as $route) {
            if (isset($hooks[$route])) {
                $output = $hooks[$route]['in_flight'] ? 0 : self::windowMs($hooks, $route, $elapsed);
                if ($output > 0) {
                    $rows[] = array('kind' => 'output', 'key' => '', 'label' => '', 'ms' => $output);
                }
                break;
            }
        }
        if ($startup !== null) {
            $rows[] = array('kind' => 'startup', 'key' => '', 'label' => '', 'ms' => $startup);
        }
        return $rows;
    }

    /**
     * The time between $fromHook ending and $untilMs, minus the spans of
     * timed actions that started inside it (those have rows of their own).
     *
     * @param array<string, Hook> $hooks
     * @param string $fromHook
     * @param int|null $untilMs
     * @return int
     */
    private static function windowMs(array $hooks, string $fromHook, ?int $untilMs): int {
        $from = $hooks[$fromHook]['end_ms'] ?? null;
        if ($from === null || $untilMs === null || $untilMs <= $from) {
            return 0;
        }
        $window = $untilMs - $from;
        foreach ($hooks as $hook) {
            if ($hook['start_ms'] >= $from && $hook['start_ms'] < $untilMs) {
                $end = $hook['end_ms'] ?? $untilMs;
                $window -= max(0, min($end, $untilMs) - $hook['start_ms']);
            }
        }
        return max(0, $window);
    }

    /**
     * MODE_PER_CALLBACK: every timed action was timed callback by callback;
     * MODE_PER_ACTION: none was; MODE_MIXED: the request switched partway;
     * MODE_PER_PHASE: the recorder was off.
     *
     * @param bool $recording
     * @param array<string, Hook> $hooks
     * @return string
     */
    private static function mode(bool $recording, array $hooks): string {
        if (!$recording) {
            return self::MODE_PER_PHASE;
        }
        $detailed = 0;
        foreach ($hooks as $hook) {
            $detailed += $hook['intervals'] !== null ? 1 : 0;
        }
        if ($detailed === 0) {
            return self::MODE_PER_ACTION;
        }
        return $detailed === count($hooks) ? self::MODE_PER_CALLBACK : self::MODE_MIXED;
    }

    /**
     * Phase windows in firing order: startup, plugin loading (boot to the
     * first timed action), then each action's start to the next one's (the
     * last runs to death). CPU is the difference of the per-action readings.
     *
     * The four readings share one type (a nullable or plain int of milliseconds)
     * and mean different things, so they arrive keyed: swapping startup with a CPU
     * reading is a wrong key at the call site, not a type-valid slip.
     *
     * @param array<string, Hook> $hooks
     * @param PhaseWindow $window startup_ms (request start to plugin boot, null
     *   when the wall clock is unknown), elapsed_ms (plugin boot to death),
     *   cpu_boot_ms and cpu_now_ms (CPU readings at boot and at death).
     * @return list<Phase>
     */
    private static function phases(array $hooks, array $window): array {
        $startup = $window['startup_ms'];
        $elapsed = $window['elapsed_ms'];
        $cpuBoot = $window['cpu_boot_ms'];
        $cpuNow = $window['cpu_now_ms'];
        $phases = array();
        if ($startup !== null) {
            $phases[] = array('name' => 'startup', 'start_ms' => 0, 'ms' => $startup, 'cpu_ms' => null);
        }
        $offset = $startup ?? 0;
        uasort($hooks, static function (array $a, array $b): int {
            return $a['start_ms'] <=> $b['start_ms'];
        });
        $names = array_keys($hooks);
        $first = $names === array() ? null : $hooks[$names[0]];
        $phases[] = array('name' => 'plugins', 'start_ms' => $offset, 'ms' => max(0, $first['start_ms'] ?? $elapsed),
            'cpu_ms' => self::cpuDelta(array('from_ms' => $cpuBoot, 'to_ms' => $first === null ? $cpuNow : $first['cpu_ms'])));
        foreach ($names as $i => $name) {
            $next = isset($names[$i + 1]) ? $hooks[$names[$i + 1]] : null;
            $phases[] = array('name' => $name, 'start_ms' => $offset + $hooks[$name]['start_ms'],
                'ms' => max(0, ($next['start_ms'] ?? $elapsed) - $hooks[$name]['start_ms']),
                'cpu_ms' => self::cpuDelta(array('from_ms' => $hooks[$name]['cpu_ms'],
                    'to_ms' => $next === null ? $cpuNow : $next['cpu_ms'])));
        }
        return $phases;
    }

    /**
     * CPU spent between two readings, or null when either is unknown. The two
     * readings are the same kind of number, so they arrive keyed: a swapped
     * pair is a wrong key at the call site, not a delta silently clamped to 0.
     *
     * @param CpuSpan $span
     * @return int|null
     */
    private static function cpuDelta(array $span): ?int {
        return ($span['from_ms'] === null || $span['to_ms'] === null) ? null : max(0, $span['to_ms'] - $span['from_ms']);
    }

    /**
     * The innermost timed action still running at death, or null.
     *
     * @param array<string, Hook> $hooks
     * @return string|null
     */
    private static function innermostHook(array $hooks): ?string {
        $found = null;
        $latest = -1;
        foreach ($hooks as $name => $hook) {
            if ($hook['in_flight'] && $hook['start_ms'] > $latest) {
                $found = $name;
                $latest = $hook['start_ms'];
            }
        }
        return $found;
    }

    /**
     * Normalize the hooks input; malformed entries are dropped.
     *
     * @param mixed $raw
     * @return array<string, Hook>
     */
    private static function hooks($raw): array {
        $hooks = array();
        foreach (is_array($raw) ? $raw : array() as $name => $hook) {
            if (!is_string($name) || !is_array($hook) || !is_int($hook['start_ms'] ?? null)) {
                continue;
            }
            $intervals = null;
            if (is_array($hook['intervals'] ?? null)) {
                $intervals = array();
                foreach ($hook['intervals'] as $interval) {
                    if (is_array($interval)) {
                        $intervals[] = array('owner' => ABJ_404_Solution_TimeLimitRecord::owner($interval['owner'] ?? null),
                            'callback' => ABJ_404_Solution_TimeLimitRecord::stringValue($interval['callback'] ?? ''),
                            'ms' => max(0, ABJ_404_Solution_TimeLimitRecord::intValue($interval['ms'] ?? 0)),
                            'in_flight' => !empty($interval['in_flight']));
                    }
                }
            }
            $hooks[$name] = array('start_ms' => $hook['start_ms'],
                'end_ms' => ABJ_404_Solution_TimeLimitRecord::nullableInt($hook['end_ms'] ?? null),
                'cpu_ms' => ABJ_404_Solution_TimeLimitRecord::nullableInt($hook['cpu_ms'] ?? null),
                'in_flight' => !empty($hook['in_flight']), 'intervals' => $intervals);
        }
        return $hooks;
    }

    /**
     * Add ms to an owner's bucket. A null owner (an interval shared by
     * callbacks of different owners) adds nothing: its time stays in the
     * unattributed remainder.
     *
     * @param array<string, int> $buckets
     * @param Owner|null $owner
     * @param int $ms
     * @return void
     */
    private static function add(array &$buckets, ?array $owner, int $ms): void {
        if ($owner === null || $ms <= 0) {
            return;
        }
        $key = ABJ_404_Solution_TimeLimitRecord::ownerKey($owner);
        $buckets[$key] = ($buckets[$key] ?? 0) + $ms;
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    private static function labels($raw): array {
        $labels = array();
        foreach (is_array($raw) ? $raw : array() as $key => $label) {
            if (is_string($key) && is_string($label) && $label !== '') {
                $labels[$key] = $label;
            }
        }
        return $labels;
    }

    /**
     * The owner's display name, or its key when none was read.
     *
     * @param Owner $owner
     * @param array<string, string> $labels
     * @return string
     */
    private static function label(array $owner, array $labels): string {
        return $labels[ABJ_404_Solution_TimeLimitRecord::ownerKey($owner)] ?? $owner['key'];
    }
}
