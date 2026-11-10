<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../policies/ErrorTypeClassifier.php';
require_once __DIR__ . '/../services/ErrorDiagnosticsReporter.php';
require_once __DIR__ . '/../services/AdminFatalErrorResponder.php';
require_once __DIR__ . '/../services/AjaxFatalErrorResponder.php';
require_once __DIR__ . '/../services/FatalErrorProcessor.php';
require_once __DIR__ . '/../diagnostics/CrashBeaconStore.php';

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {

    /** Keep a reference to the original error handler so we can use it later.
     * @var callable|null
     */
    static $originalErrorHandler = null;

    /**
     * Reserved memory released during fatal shutdown handling so OOM errors can still render fallback output.
     *
     * @var string|null
     */
    private static $reservedMemory = null;

    /**
     * Process resource usage snapshot taken when the plugin boots.
     *
     * @var array<string,mixed>|null
     */
    private static $bootResourceUsage = null;

    /**
     * Wall-clock time (epoch seconds) when the plugin booted.
     *
     * @var float|null
     */
    private static $bootWallTime = null;

    /** @var array<string, mixed>|null Original fatal before WordPress teardown mutates PHP's last error. */
    private static $shutdownFatal = null;

    /** Setup.
     * @return void
     */
    static function init(): void {
        self::$shutdownFatal = null;
        // store the original error handler.
        self::$originalErrorHandler = set_error_handler(function(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool { return false; });
        restore_error_handler();

        // set to the user defined error handler
        set_error_handler("ABJ_404_Solution_ErrorHandler::NormalErrorHandler");
        if (self::$reservedMemory === null) {
            // Keep a small memory reserve so shutdown handling can render a fallback page on memory exhaustion.
            self::$reservedMemory = str_repeat('R', 262144);
        }
        self::precomputeCrashBeaconPath();
        self::precomputeCrashBeaconRequestContext();
        // REST_REQUEST is defined after the earliest plugin bootstrap. Refresh
        // the primitive snapshot at the REST lifecycle boundary before handlers
        // can fail, so REST fatals are not mislabeled as front-end requests.
        if (function_exists('add_action')) {
            add_action('rest_api_init', static function (): void {
                self::precomputeCrashBeaconRequestContext();
            }, PHP_INT_MIN, 0);
        }
        if (self::$bootWallTime === null) {
            try {
                self::recordBootResourceSnapshot(ABJ_404_Solution_PhpRuntimeCapabilityAdapter::resourceUsage(), abj_clock()->nowFloat());
            } catch (\Throwable $e) {
                abj404_logPhpFallback('boot-resource-snapshot', 'boot resource snapshot failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            }
        }
        // WordPress's shutdown action runs before our raw PHP callback. Its
        // diagnostics and other plugins may warn or call error_clear_last().
        // Preserve only the original fatal here; all processing stays below.
        add_filter('wp_php_error_message', array(__CLASS__, 'rememberWordPressFatal'), PHP_INT_MIN, 2);
        add_action('shutdown', array(__CLASS__, 'rememberFatalAtShutdown'), PHP_INT_MIN, 0);
        add_action('shutdown', array(__CLASS__, 'retireShutdownCallbacksWithoutRuntime'), PHP_INT_MIN, 0);
        // PHP shutdown registrations cannot be removed. During plugin
        // uninstall or direct CLI deletion, this callback may run after the
        // plugin directory is gone, so fatal processing must stay inert and
        // avoid constructing collaborators that would autoload deleted files.
        register_shutdown_function('ABJ_404_Solution_ErrorHandler::FatalErrorHandler');
    }

    /**
     * Whether the plugin runtime is still present for shutdown handling.
     *
     * The uninstall marker identifies this plugin's own teardown. Without it,
     * a direct active-plugin deletion is detected by checking this class file
     * before any service or autoloader work can occur.
     *
     * @return bool
     */
    private static function isRuntimeAvailable(): bool {
        if (defined('WP_UNINSTALL_PLUGIN') && defined('ABJ404_NAME')
            && WP_UNINSTALL_PLUGIN === ABJ404_NAME) {
            return false;
        }
        clearstatcache(true, __FILE__);
        return is_file(__FILE__);
    }

    /**
     * Drop every WordPress shutdown callback defined in this plugin's files
     * once its runtime is gone (its own uninstall, or `wp plugin delete` of
     * the active plugin).
     *
     * The callbacks are registered in memory while the files are deleted by
     * the same request, so each one that reaches a class not yet autoloaded
     * (a repository, the logger in its own catch block) fatals with exit 255
     * after WordPress reported success. Retiring them here, from the one
     * callback that is already loaded and runs first, covers every current
     * and future shutdown action without a guard in each. Priorities above
     * PHP_INT_MIN only: callbacks at this priority are being iterated now.
     * Nothing happens while the runtime is present.
     */
    public static function retireShutdownCallbacksWithoutRuntime(): void {
        if (self::isRuntimeAvailable()) {
            return;
        }
        $wpFilter = $GLOBALS['wp_filter'] ?? null;
        $hook = is_array($wpFilter) ? ($wpFilter['shutdown'] ?? null) : null;
        if (!is_object($hook) || !isset($hook->callbacks) || !is_array($hook->callbacks)) {
            return;
        }
        $pluginRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        foreach ($hook->callbacks as $priority => $entries) {
            if ($priority <= PHP_INT_MIN || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                // WordPress builds every entry as array('function' => ..., 'accepted_args' => ...);
                // a shape it does not produce is not ours to retire.
                $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
                if (is_callable($callback) && self::isDefinedUnder($callback, $pluginRoot)) {
                    remove_action('shutdown', $callback, $priority);
                }
            }
        }
    }

    /**
     * Whether a callable's code lives in a file under the given directory.
     * A callable with no inspectable source file (a built-in function) is
     * treated as not ours and left alone.
     *
     * @param callable $callback A WordPress hook callback.
     * @param string $directory Directory path ending in a separator.
     * @return bool
     */
    private static function isDefinedUnder(callable $callback, string $directory): bool {
        $file = (new ReflectionFunction(Closure::fromCallable($callback)))->getFileName();
        return is_string($file) && strncmp($file, $directory, strlen($directory)) === 0;
    }

    /** Try to capture PHP errors.
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return boolean
     */
    static function NormalErrorHandler($errno, $errstr, $errfile, $errline) {
        // Respect PHP's `@` error-suppression operator. Up to PHP 7.x the @
        // operator zeroed error_reporting() for the duration of the call;
        // PHP 8.0+ instead leaves error_reporting() non-zero but masks out
        // the specific level being silenced. A custom handler that ignores
        // this state still escalates intentionally-suppressed warnings to
        // error-level logging, defeating the suppression.
        // Concrete case: @opcache_invalidate() at PluginLogic.php:1036 on
        // hosts with opcache.restrict_api set produces an E_WARNING. The
        // legacy `error_reporting() === 0` check matched on PHP 7.x but
        // not on PHP 8.0+ (where the @ leaves the mask non-zero), so 24
        // such ERROR entries reached the email reporter in the May 10
        // debug zip, including 2 from current 4.1.17 sites on PHP 8.3.
        // The bitwise form covers both eras: on PHP 7.x error_reporting()
        // is 0 and `& $errno` is 0; on PHP 8.0+ the specific level bit is
        // cleared and `& $errno` is also 0. Returning false here lets
        // PHP's default handler honour the suppression.
        if ((error_reporting() & $errno) === 0 || !self::isRuntimeAvailable()) {
            return false;
        }

        $abj404logging = abj_service('logging');
        $f = abj_service('functions');
        $errorTypeClassifier = new ABJ_404_Solution_ErrorTypeClassifier();
        $onlyAWarning = false;

        try {
            // if the error file does not contain the name of our plugin then we ignore it.
            $slashPos = $f->strpos(ABJ404_NAME, '/');
            $pluginFolder = $f->substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : null));
            if ($f->strpos($errfile, $pluginFolder) === false) {
                // let the normal error handler handle it.

                // this would display the error for other plugins but show @author user
                // stacktrace from this plugin.
//              // try calling the original error handler.
//              if (is_callable(self::$originalErrorHandler)) {
//                  return call_user_func_array(self::$originalErrorHandler,
//                      array($errno, $errstr, $errfile, $errline));
//              }
                return false;

            } else {
                // for our own plugin errors make sure we see them.
                if ($GLOBALS['abj404_display_errors']) {
                    error_reporting(E_ALL);
                    ABJ_404_Solution_PhpRuntimeCapabilityAdapter::setIni(
                array('directive' => 'display_errors', 'value' => '1'));
                }
            }

            // Recoverable host/infrastructure warnings (headers-already-sent,
            // open_basedir restriction, ...) are E_WARNINGs the plugin can only
            // degrade past, never fix -- the host itself forbids the operation.
            // Downgrade them below error level so getLatestErrorLine() does not
            // count them and the plugin does not phone home a type='error'
            // report about a server-side condition only the site owner can
            // resolve. The allowlist lives in the classifier so a future
            // host-only warning is added in one place, not as another special
            // case here (ErrorTypeClassifier::isRecoverableHostWarning: report
            // 104 headers-already-sent, report 149 open_basedir).
            $onlyAWarning = $errorTypeClassifier->isRecoverableHostWarning($errno, $errstr);

            $extraInfo = "(none)";
            $ctxDebugInfo = abj_service('request_context')->debug_info;
            if ($ctxDebugInfo !== '') {
                $extraInfo = stripcslashes(wp_kses_post((string)json_encode($ctxDebugInfo)));
            }
            $errmsg = "ABJ404-SOLUTION Normal error handler error: errno: " .
                        wp_kses_post((string)json_encode($errno)) . ", errstr: " . wp_kses_post((string)json_encode($errstr)) .
                        ", \nerrfile: " . stripcslashes(wp_kses_post((string)json_encode($errfile))) .
                        ", \nerrline: " . wp_kses_post((string)json_encode($errline)) .
                        ', \nAdditional info: ' . $extraInfo . ", mbstring: " .
                    (extension_loaded('mbstring') ? 'true' : 'false');

            if ($abj404logging != null) {
                if ($errno === E_NOTICE) {
                    $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                    $whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist'])
                        ? $GLOBALS['abj404_whitelist'] : array();
                    if (in_array($serverName, $whitelist, true)) {
                        $e = new Exception;
                        $abj404logging->debugMessage($errmsg . ', Trace:' . $e->getTraceAsString());
                    }
                } elseif ($onlyAWarning) {
                    $abj404logging->debugMessage($errmsg);
                } else {
                    $abj404logging->errorMessage($errmsg);
                }
            } else {
                echo $errmsg;
            }
        } catch (Throwable $ex) {
            // Last-resort breadcrumb: the inner logging path itself failed,
            // so we can't go through $abj404logging. Match the pattern used
            // by AdminRuntimeErrorNotice::reportAdminRuntimeError() and
            // SuggestionComputeJob::handleComputationCrash(). Widening from
            // Exception to Throwable is intentional because Error types are
            // exactly the case the outer handler exists for.
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'error handler itself failed (code ' . $ex->getCode() . '): ' . $ex->getMessage()
            );
        }

        // show all warnings and errors.
        if ($GLOBALS['abj404_display_errors']) {
            error_reporting(E_ALL);
            ABJ_404_Solution_PhpRuntimeCapabilityAdapter::setIni(
                array('directive' => 'display_errors', 'value' => '1'));
        }
        // let the original error handler handle it.
        return false;
    }

    /** @return bool */
    static function FatalErrorHandler(): bool {
        $lasterror = self::$shutdownFatal ?? error_get_last();
        self::$shutdownFatal = null;
        return self::processFatalError($lasterror);
    }

    /**
     * @param mixed $message
     * @param mixed $error
     * @return mixed
     */
    public static function rememberWordPressFatal($message, $error = null) {
        // Core passes the original error even if recovery/template work has
        // already changed error_get_last(). Leave its response untouched.
        self::rememberFatal($error);
        return $message;
    }

    /** Fallback for core's fatal handler being disabled or unavailable. */
    public static function rememberFatalAtShutdown(): void {
        self::rememberFatal(error_get_last());
    }

    /** @param mixed $error Snapshot only: no I/O, services, logging, or response work. */
    private static function rememberFatal($error): void {
        if (self::$shutdownFatal !== null || !is_array($error)
            || !is_int($error['type'] ?? null)
            || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR,
                E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)
            || !is_string($error['file'] ?? null) || $error['file'] === '') {
            return;
        }
        self::releaseReservedMemory();
        self::$shutdownFatal = array(
            'type' => $error['type'],
            'file' => substr($error['file'], 0, 4096),
            'line' => is_int($error['line'] ?? null) ? $error['line'] : 0,
            'message' => is_string($error['message'] ?? null) ? substr($error['message'], 0, 8192) : '',
        );
    }

    /**
     * Release the OOM reserve before fatal fallback rendering.
     *
     * @return void
     */
    public static function releaseReservedMemory(): void {
        self::$reservedMemory = null;
    }

    /**
     * Record a snapshot of process resource usage and wall time taken when the
     * plugin boots, so fatal diagnostics can report per-request deltas on
     * long-lived workers. Passing null clears the snapshot (tests).
     *
     * @param array<string,mixed>|null $usage
     * @param float|null $wallTime
     * @return void
     */
    public static function recordBootResourceSnapshot(?array $usage, ?float $wallTime): void {
        self::$bootResourceUsage = $usage;
        self::$bootWallTime = $wallTime;
    }

    /**
     * The boot-time resource snapshot recorded by recordBootResourceSnapshot().
     *
     * @return array{usage: array<string,mixed>|null, wall: float|null}
     */
    public static function bootResourceSnapshot(): array {
        return array('usage' => self::$bootResourceUsage, 'wall' => self::$bootWallTime);
    }

    /**
     * Resolve the crash-beacon file path while the request is HEALTHY and cache
     * it in a primitive global, so the fatal handler can write the beacon using
     * only that string (the fatal handler must NOT call wp_upload_dir()/options/
     * the service container during an OOM shutdown; doing so risks re-entering
     * the exact failure class it is trying to report). Ensures the directory
     * exists now, while there is memory to do so.
     *
     * @return void
     */
    private static function precomputeCrashBeaconPath(): void {
        try {
            if (!function_exists('abj404_getUploadsDir')) {
                return;
            }
            $dir = abj404_getUploadsDir();
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            }
            $GLOBALS['abj404_crash_beacon_path'] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR
                . ABJ_404_Solution_CrashBeaconStore::FILE_NAME;
        } catch (\Throwable $e) {
            // Precompute is best-effort: if it fails the fatal handler simply
            // skips beacon capture (it never resolves the path itself).
            abj404_logPhpFallback('crash-beacon-precompute', $e->getMessage());
        }
    }

    /**
     * Cache request identity while memory is healthy. Fatal shutdown reads only
     * these primitive globals and does not inspect WordPress request state.
     *
     * @return void
     */
    private static function precomputeCrashBeaconRequestContext(): void {
        try {
            $kind = ABJ_404_Solution_RequestKind::current();
            $GLOBALS['abj404_crash_beacon_request_kind'] = $kind;
            $GLOBALS['abj404_crash_beacon_request_action'] = $kind === ABJ_404_Solution_RequestKind::KIND_ADMIN_AJAX
                ? ABJ_404_Solution_RequestKind::currentAjaxAction() : null;
        } catch (\Throwable $e) {
            // Request identity is additive diagnostics. A missing identity
            // must never prevent the fatal handler from registering the crash.
            $GLOBALS['abj404_crash_beacon_request_kind'] = null;
            $GLOBALS['abj404_crash_beacon_request_action'] = null;
            $message = 'crash-beacon request context unavailable: ' . $e->getMessage();
            if (function_exists('abj404_logPhpFallback')) {
                abj404_logPhpFallback('crash-beacon-precompute', $message);
            } else {
                ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog('404 Solution: ' . $message);
            }
        }
    }

    /**
     * Process a fatal error (shutdown handler).
     * Public for unit tests (allows injecting a fake last error).
     *
     * @param array<string, mixed>|null $lasterror
     * @return bool
     */
    public static function processFatalError($lasterror): bool {
        if (!self::isRuntimeAvailable()) {
            return false;
        }
        $processor = new ABJ_404_Solution_FatalErrorProcessor();
        return $processor->process($lasterror);
    }
}
