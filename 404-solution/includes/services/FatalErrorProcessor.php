<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../diagnostics/CrashBeaconStore.php';

/**
 * Orchestrates shutdown-time fatal-error handling.
 */
class ABJ_404_Solution_FatalErrorProcessor {

    /** @var ABJ_404_Solution_ErrorTypeClassifier */
    private $classifier;

    /** @var ABJ_404_Solution_ErrorDiagnosticsReporter */
    private $diagnostics;

    /** @var ABJ_404_Solution_AdminFatalErrorResponder */
    private $adminResponder;

    /** @var ABJ_404_Solution_AjaxFatalErrorResponder */
    private $ajaxResponder;

    /** @var callable */
    private $resourceUsageProvider;

    /**
     * @param ABJ_404_Solution_ErrorTypeClassifier|null $classifier
     * @param ABJ_404_Solution_ErrorDiagnosticsReporter|null $diagnostics
     * @param ABJ_404_Solution_AdminFatalErrorResponder|null $adminResponder
     * @param ABJ_404_Solution_AjaxFatalErrorResponder|null $ajaxResponder
     * @param callable|null $resourceUsageProvider returns getrusage()-shaped array or null; injectable for tests
     */
    public function __construct($classifier = null, $diagnostics = null, $adminResponder = null, $ajaxResponder = null, $resourceUsageProvider = null) {
        $this->classifier = $classifier !== null ? $classifier : new ABJ_404_Solution_ErrorTypeClassifier();
        $this->diagnostics = $diagnostics !== null ? $diagnostics : new ABJ_404_Solution_ErrorDiagnosticsReporter();
        $this->adminResponder = $adminResponder !== null ? $adminResponder : new ABJ_404_Solution_AdminFatalErrorResponder();
        $this->ajaxResponder = $ajaxResponder !== null ? $ajaxResponder : new ABJ_404_Solution_AjaxFatalErrorResponder($this->diagnostics);
        $this->resourceUsageProvider = is_callable($resourceUsageProvider) ? $resourceUsageProvider : array('ABJ_404_Solution_PhpRuntimeCapabilityAdapter', 'resourceUsage');
    }

    /**
     * @param array<string,mixed>|null $lasterror
     * @return bool
     */
    public function process($lasterror): bool {
        if (!$this->isProcessableFatal($lasterror) || !is_array($lasterror)) {
            return false;
        }

        $lasterror = $this->truncateLargeMessage($lasterror);
        $ctx = $this->currentAjaxContext();

        // Crash beacon: for a plugin-scope fatal (including OOM), release the
        // memory reserve on ALL requests (previously admin-only, which is why a
        // front-end OOM had no headroom) and write a tiny breadcrumb file FIRST,
        // before any heavier handling below, so the post-mortem survives even if
        // a later step in this handler fatals again. Self-contained and best
        // effort; never throws.
        if ($this->isPluginScopeFatal($lasterror)) {
            ABJ_404_Solution_ErrorHandler::releaseReservedMemory();
            $this->captureCrashBeacon($lasterror);
            // A PHP time-limit fatal in our files: put the per-component
            // breakdown in the PHP error log and on the plugin's admin
            // screen, so the component that spent the time is named where
            // the blame lands (c305). No-op for every other fatal.
            if (class_exists('ABJ_404_Solution_TimeLimitFatalReporter', false)) {
                $timeLimitBreakdown = ABJ_404_Solution_TimeLimitFatalReporter::breakdownFor($lasterror);
                if ($timeLimitBreakdown !== null) {
                    ABJ_404_Solution_TimeLimitFatalRecorder::record($timeLimitBreakdown);
                }
            }
        }

        $isPluginAdminPage = $this->adminResponder->isPluginAdminPageRequest();
        if ($isPluginAdminPage) {
            ABJ_404_Solution_ErrorHandler::releaseReservedMemory();
            $this->adminResponder->stashAdminFatal($lasterror);
        }

        if ($this->ajaxResponder->isAjaxContext($ctx)) {
            return $this->ajaxResponder->process($lasterror, is_array($ctx) ? $ctx : array());
        }

        $this->logDefaultFatal($lasterror, $isPluginAdminPage);

        if ($isPluginAdminPage) {
            $this->adminResponder->renderAdminFatalFallback($lasterror);
        }

        return false;
    }

    /**
     * @param mixed $lasterror
     * @return bool
     */
    private function isProcessableFatal($lasterror): bool {
        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) ||
            !array_key_exists('file', $lasterror)) {
            return false;
        }

        $errorType = $lasterror['type'];
        return $this->classifier->isFatalType(is_int($errorType) ? $errorType : (is_scalar($errorType) ? (int)$errorType : 0));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function currentAjaxContext() {
        if (!isset($GLOBALS['abj404_ajax_context']) || !is_array($GLOBALS['abj404_ajax_context'])) {
            return null;
        }

        $context = array();
        foreach ($GLOBALS['abj404_ajax_context'] as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }
        return $context;
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return void
     */
    private function logDefaultFatal(array $lasterror, bool $isPluginAdminPage): void {
        try {
            $errno = $lasterror['type'];
            $isPluginScopeFatal = $this->isPluginScopeFatal($lasterror);

            if (!$isPluginScopeFatal && !$isPluginAdminPage) {
                return;
            }

            $extraInfo = "(none)";
            $ctxDebugInfo = abj_service('request_context')->debug_info;
            if ($ctxDebugInfo !== '') {
                $extraInfo = stripcslashes(wp_kses_post((string)json_encode($ctxDebugInfo)));
            }
            $elapsedFragment = '';
            $anchor = ABJ_404_Solution_MatchingTimeBudget::resolveAnchor();
            if ($anchor !== null) {
                $elapsedFragment = ', request_elapsed_s: ' . number_format(abj_clock()->nowFloat() - $anchor, 3, '.', '');
            }
            $resourceFragment = $this->buildResourceFragment();
            $contextPrefix = $isPluginScopeFatal
                ? 'ABJ404-SOLUTION Fatal error handler: '
                : 'ABJ404-SOLUTION Fatal error handler (plugin admin page, foreign scope): ';

            $errmsg = $contextPrefix .
                stripcslashes(wp_kses_post((string)json_encode($lasterror))) .
                ", \nAdditional info: " . $extraInfo . ", mbstring: " .
                (extension_loaded('mbstring') ? 'true' : 'false') . $elapsedFragment . $resourceFragment;

            $abj404logging = abj_service('logging');
            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                        $whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist'])
                            ? $GLOBALS['abj404_whitelist'] : array();
                        if (in_array($serverName, $whitelist, true)) {
                            $abj404logging->debugMessage($errmsg);
                        }
                        break;

                    default:
                        $abj404logging->errorMessage($errmsg);
                        break;
                }
            } else {
                echo $errmsg;
            }
        } catch (Throwable $ex) {
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'error handler itself failed (code ' . $ex->getCode() . '): ' . $ex->getMessage()
            );
        }
    }

    /**
     * Split getrusage() output into user/sys CPU seconds, or null unless the
     * input is an array with all four numeric utime/stime fields.
     *
     * @param mixed $u
     * @return array{0: float, 1: float}|null
     */
    private static function cpuPair($u): ?array {
        if (!is_array($u)
            || !isset($u['ru_utime.tv_sec'], $u['ru_utime.tv_usec'], $u['ru_stime.tv_sec'], $u['ru_stime.tv_usec'])
            || !is_numeric($u['ru_utime.tv_sec']) || !is_numeric($u['ru_utime.tv_usec'])
            || !is_numeric($u['ru_stime.tv_sec']) || !is_numeric($u['ru_stime.tv_usec'])) {
            return null;
        }
        $user = (float)$u['ru_utime.tv_sec'] + ((float)$u['ru_utime.tv_usec'] / 1000000.0);
        $sys = (float)$u['ru_stime.tv_sec'] + ((float)$u['ru_stime.tv_usec'] / 1000000.0);
        return array($user, $sys);
    }

    /**
     * CPU usage plus the running WordPress hook stack, for fatal diagnostics.
     * process_cpu_s is cumulative for the worker process (getrusage() counts
     * the whole process, and one mod_php/FPM/LiteSpeed worker serves many
     * requests); cpu_since_plugin_boot_s is this request's CPU after the
     * plugin loaded, with page-fault and context-switch deltas and the
     * plugin-boot offset. Any fragment may be absent (getrusage() disabled,
     * no boot snapshot, no anchor, no hooks on the stack).
     *
     * @return string
     */
    private function buildResourceFragment(): string {
        $fragment = '';
        $usage = call_user_func($this->resourceUsageProvider);
        $now = self::cpuPair($usage);
        if ($now !== null) {
            $fragment .= ', process_cpu_s: ' . number_format($now[0] + $now[1], 3, '.', '') . ' (user ' . number_format($now[0], 3, '.', '') . ', sys ' . number_format($now[1], 3, '.', '') . ')';
            $snap = ABJ_404_Solution_ErrorHandler::bootResourceSnapshot();
            $boot = self::cpuPair($snap['usage']);
            if ($boot !== null) {
                $du = $now[0] - $boot[0];
                $ds = $now[1] - $boot[1];
                $fragment .= ', cpu_since_plugin_boot_s: ' . number_format($du + $ds, 3, '.', '') . ' (user ' . number_format($du, 3, '.', '') . ', sys ' . number_format($ds, 3, '.', '') . ')';
                foreach (array('ru_minflt', 'ru_majflt', 'ru_nivcsw', 'ru_nvcsw') as $key) {
                    if (is_array($usage) && is_array($snap['usage']) && isset($usage[$key], $snap['usage'][$key]) && is_numeric($usage[$key]) && is_numeric($snap['usage'][$key])) {
                        $fragment .= ', ' . substr($key, 3) . '_since_boot: ' . (string)(int)($usage[$key] - $snap['usage'][$key]);
                    }
                }
            }
        }
        $snap = ABJ_404_Solution_ErrorHandler::bootResourceSnapshot();
        $anchor = ABJ_404_Solution_MatchingTimeBudget::resolveAnchor();
        if ($snap['wall'] !== null && $anchor !== null) {
            $fragment .= ', plugin_boot_at_s: ' . number_format($snap['wall'] - $anchor, 3, '.', '');
        }
        if (isset($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])
            && $GLOBALS['wp_current_filter'] !== array()) {
            $names = array();
            foreach ($GLOBALS['wp_current_filter'] as $hook) {
                if (is_string($hook)) {
                    $names[] = $hook;
                }
            }
            $names = array_slice($names, -10);
            $hooks = array();
            foreach ($names as $hook) {
                $cleaned = preg_replace('/[^A-Za-z0-9_\-\/.:]/', '', $hook);
                if (is_string($cleaned) && $cleaned !== '') {
                    $hooks[] = $cleaned;
                }
            }
            if ($hooks !== array()) {
                $fragment .= ', wp_hooks: ' . implode('>', $hooks);
            }
        }
        return $fragment . self::buildTimelineFragment();
    }

    /**
     * The request's phase timeline (every stamp in the order reached, as ms
     * since plugin boot, then the plugin's own query count and time), so a
     * max_execution_time fatal says which window spent the time instead of
     * only the line the timer landed on (plmcb.fr reports 505/506). Stamp
     * names are the timeline's bounded [A-Za-z0-9_:] vocabulary. '' before
     * plugin boot or when the timeline class is not loaded; never autoloads
     * from inside the fatal handler.
     */
    private static function buildTimelineFragment(): string {
        if (!class_exists('ABJ_404_Solution_RequestPhaseTimeline', false)
            || ABJ_404_Solution_RequestPhaseTimeline::encode() === '') {
            return '';
        }
        $timeline = ABJ_404_Solution_RequestPhaseTimeline::toArray();
        $stamps = $timeline['t'];
        asort($stamps);
        $parts = array();
        foreach ($stamps as $name => $ms) {
            $parts[] = $name . '=' . $ms;
        }
        return ', phase_ms_since_boot: ' . implode('>', $parts)
            . ', plugin_queries: ' . $timeline['db']['n'] . ' (' . $timeline['db']['ms'] . ' ms)'
            . self::buildTimeBreakdownFragment();
    }

    /**
     * The per-component time breakdown of a time-limit fatal (the same line
     * the PHP error log gets), so the developer report names the component
     * that spent the time too. '' for every other fatal, or when the
     * reporter never loaded.
     *
     * @return string
     */
    private static function buildTimeBreakdownFragment(): string {
        if (!class_exists('ABJ_404_Solution_TimeLimitFatalReporter', false)) {
            return '';
        }
        $breakdown = ABJ_404_Solution_TimeLimitFatalReporter::breakdownFor(error_get_last());
        return $breakdown === null ? ''
            : ', time_breakdown: ' . ABJ_404_Solution_TimeLimitReportRenderer::logLine($breakdown);
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return array<string,mixed>
     */
    private function truncateLargeMessage(array $lasterror): array {
        if (isset($lasterror['message']) && is_string($lasterror['message'])
            && strlen($lasterror['message']) > 8192) {
            $lasterror['message'] = substr($lasterror['message'], 0, 8192)
                . '... (truncated; original length ' . strlen($lasterror['message']) . ' bytes)';
        }
        return $lasterror;
    }

    /** @return string */
    private function pluginFolder(): string {
        $slashPos = strpos(ABJ404_NAME, '/');
        $pluginFolder = substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : strlen(ABJ404_NAME)));
        return is_string($pluginFolder) ? $pluginFolder : (string)ABJ404_NAME;
    }

    /**
     * Whether the fatal occurred in one of this plugin's files. Pure string
     * containment on the existing plugin-folder marker, so it is safe to call
     * during an OOM shutdown.
     *
     * @param array<string,mixed> $lasterror
     * @return bool
     */
    private function isPluginScopeFatal(array $lasterror): bool {
        $errfile = isset($lasterror['file']) && is_string($lasterror['file']) ? $lasterror['file'] : '';
        if ($errfile === '') {
            return false;
        }
        return strpos($errfile, $this->pluginFolder()) !== false;
    }

    /**
     * Write a crash beacon for a plugin-scope fatal using ONLY the path
     * precomputed at healthy boot (ABJ_404_Solution_ErrorHandler::precomputeCrashBeaconPath)
     * plus primitives. No wp_upload_dir()/options/container/clock calls here:
     * this runs in the fatal handler where memory may be exhausted, so it must
     * not re-enter the failure class it is reporting. Best effort; never throws.
     *
     * @param array<string,mixed> $lasterror
     * @return void
     */
    private function captureCrashBeacon(array $lasterror): void {
        try {
            // Guarantee headroom for the post-OOM beacon write. Raising
            // memory_limit inside the shutdown handler is honored by PHP even
            // after a memory-exhaustion fatal, so the tiny json_encode/fwrite
            // below cannot itself fail for want of memory (the exact case the
            // beacon exists to capture). ONLY ever raise (current limit + a
            // small fixed margin); never lower, and leave an unlimited (-1) or
            // unparseable limit untouched -- so this can never shrink a healthy
            // request's budget. Bounded (not unlimited) so a constrained or
            // shared host is never pushed into an OS-level OOM kill. Complements
            // the released memory reserve.
            $currentLimitBytes = $this->currentMemoryLimitBytes();
            if ($currentLimitBytes > 0) {
                ABJ_404_Solution_PhpRuntimeCapabilityAdapter::setIni(array(
                    'directive' => 'memory_limit',
                    'value' => (string) ($currentLimitBytes + (8 * 1024 * 1024)),
                ));
            }

            $path = isset($GLOBALS['abj404_crash_beacon_path']) && is_string($GLOBALS['abj404_crash_beacon_path'])
                ? $GLOBALS['abj404_crash_beacon_path'] : '';
            if ($path === '') {
                return;
            }
            $version = defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : '';
            $pluginRoot = defined('ABJ404_PATH') ? (string)ABJ404_PATH : '';
            // Clock service for the informational capture timestamp. Safe during
            // shutdown: the clock has no settings/logging/uploads dependencies
            // (the re-entry classes this capture avoids) and is near-certainly
            // already resolved this request; the surrounding try/catch makes the
            // whole capture best-effort if it is somehow unavailable.
            $now = abj_clock()->now();
            $beacon = ABJ_404_Solution_CrashBeacon::fromLastError($lasterror, $version, $pluginRoot, $now);
            $store = new ABJ_404_Solution_CrashBeaconStore($path);
            $store->recordIfAbsent($beacon);
        } catch (\Throwable $e) {
            abj404_logPhpFallback('crash-beacon-capture', 'capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Current PHP memory_limit in bytes: -1 for unlimited, 0 when unset or
     * unparseable, otherwise the positive byte count. Minimal and
     * dependency-free (no WordPress helpers) so it is safe to call from inside
     * the fatal handler after a memory-exhaustion fatal.
     *
     * @return int
     */
    private function currentMemoryLimitBytes(): int {
        $raw = trim((string) @ini_get('memory_limit'));
        if ($raw === '') {
            return 0;
        }
        if ($raw === '-1') {
            return -1;
        }
        $value = (int) $raw;
        switch (strtoupper(substr($raw, -1))) {
            case 'G': $value *= 1024 * 1024 * 1024; break;
            case 'M': $value *= 1024 * 1024; break;
            case 'K': $value *= 1024; break;
        }
        return $value > 0 ? $value : 0;
    }
}
