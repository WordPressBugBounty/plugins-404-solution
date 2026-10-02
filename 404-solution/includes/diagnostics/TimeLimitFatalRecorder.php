<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps what a time-limit fatal leaves behind: one line in the PHP error log
 * and the stored record the plugin's admin screen shows, plus the slow
 * callbacks a sampled request finds.
 *
 * Persistence only: it is handed a finished breakdown. Whether a fatal is
 * ours and what its breakdown says come from
 * ABJ_404_Solution_TimeLimitFatalReporter::breakdownFor(); how the email and
 * the error page word it is ABJ_404_Solution_TimeLimitRecoveryEmail and the
 * reporter's filters. Runs from this plugin's fatal handler and from the
 * shutdown step of a timed request, never on a healthy request.
 *
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 */
final class ABJ_404_Solution_TimeLimitFatalRecorder {

    /** @var callable|null Test seam for the error-log writer. */
    private static $errorLogWriter = null;

    /**
     * From this plugin's fatal handler: write the error-log line and store
     * the admin record for a time-limit fatal's breakdown.
     *
     * @param Record $breakdown
     * @return void
     */
    public static function record(array $breakdown): void {
        self::writeErrorLog(ABJ_404_Solution_TimeLimitReportRenderer::logLine($breakdown));
        try {
            $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $path = parse_url($uri, PHP_URL_PATH);
            $now = abj_clock()->now();
            ABJ_404_Solution_TimeLimitFatalStore::record($breakdown, is_string($path) ? $path : '', $now);
            // A request that was not timed callback by callback cannot name
            // the slow callback: time callbacks on a sample of the next
            // requests so the admin screen (and the next email) can.
            if (($breakdown['mode'] ?? '') !== ABJ_404_Solution_TimeLimitBreakdown::MODE_PER_CALLBACK) {
                ABJ_404_Solution_TimeLimitFatalStore::arm($now);
            }
        } catch (Throwable $e) {
            abj404_logPhpFallback('fatal-handler-fallback',
                'time-limit record could not be stored (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    /**
     * The request recorder's callback when a request switches to
     * per-callback timing: hook the shutdown step that keeps its slow
     * callbacks. Normal requests never reach this, so they register nothing.
     *
     * @param string $reason one of ABJ_404_Solution_RequestTimeRecorder::REASON_*.
     * @return void
     */
    public static function onDetailedTiming(string $reason): void {
        // Only the armed window's sampled requests store what they find: an
        // escalated request is merely slow, and a request that does not die
        // writes nothing outside the window an incident opened.
        if ($reason === ABJ_404_Solution_RequestTimeRecorder::REASON_SAMPLED && function_exists('add_action')) {
            self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
                'shutdown', static function (): void {
                    add_action('shutdown', array(self::class, 'recordSlowCallbacksAtShutdown'), PHP_INT_MAX, 0);
                });
        }
    }

    /**
     * shutdown callback of a timed request: store callbacks over the slow
     * threshold. A request that died of the time limit is left to
     * record(), which stores its whole breakdown.
     *
     * @return void
     */
    public static function recordSlowCallbacksAtShutdown(): void {
        $error = error_get_last();
        if (is_array($error) && strpos($error['message'], 'Maximum execution time of') === 0) {
            return;
        }
        try {
            $slow = ABJ_404_Solution_TimeLimitEvidence::slowCallbacks(ABJ_404_Solution_CodeOwnerResolver::forCurrentSite());
            ABJ_404_Solution_TimeLimitFatalStore::recordSlowCallbacks($slow, abj_clock()->now());
        } catch (Throwable $e) {
            abj404_logPhpFallback('fatal-handler-fallback',
                'slow-callback record failed (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    /**
     * Restore the real error-log writer.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$errorLogWriter = null;
    }

    /**
     * Capture error-log lines instead of writing them.
     *
     * @param callable|null $writer receives the line.
     * @return void
     */
    public static function setErrorLogWriterForTests(?callable $writer): void {
        self::$errorLogWriter = $writer;
    }

    /**
     * The boundary this class's hook registration runs in. Inert on purpose:
     * no request id exists when it is used, so the empty id makes the tracer
     * skip every write. The bracket stays because the diagnostics-wide guard
     * (DecisiveRecordManifestTest) requires every hook mutation in this
     * directory to run inside traceBoundary().
     *
     * @return ABJ_404_Solution_HookInstrumentationLifecycleTracer
     */
    private static function lifecycle(): ABJ_404_Solution_HookInstrumentationLifecycleTracer {
        return new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'time_limit_fatal_recorder');
    }

    /**
     * @param string $line
     * @return void
     */
    private static function writeErrorLog(string $line): void {
        if (self::$errorLogWriter !== null) {
            call_user_func(self::$errorLogWriter, $line);
            return;
        }
        ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog($line);
    }
}
