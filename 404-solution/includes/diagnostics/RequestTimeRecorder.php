<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * In-memory record of where a request spends its time, so a request killed
 * by PHP's max_execution_time can say which component used the time.
 *
 * Why: when the time-limit timer fires inside 404 Solution's code, WordPress
 * core's recovery email, hosts and site owners blame 404 Solution, because the
 * fatal's file is ours. plmcb.fr (reports 483-492, 505, 506) showed 27+ s of a
 * 30 s budget already gone before our code started.
 *
 * Always on (every non-AJAX request), nothing but hrtime() reads and array
 * appends, one per event:
 * - Plugin include time: core fires plugin_loaded after each active plugin's
 *   main file is included. The gap to the previous stamp is that plugin's
 *   include time. Code that loads before this plugin (must-use plugins,
 *   drop-ins, network plugins, slugs sorting before "404-solution") cannot be
 *   seen and is accounted as one remainder.
 * - Per-action totals on TIMED_HOOKS: a PHP_INT_MIN head and a PHP_INT_MAX
 *   tail stamp each action's start and end. CPU per phase (one getrusage()
 *   at each head) is read only in detailed mode: a syscall per action is
 *   not "hrtime stamps and array writes".
 * Measured always-on cost: about 0.12 ms per request (minimum of 4000
 * interleaved runs against real WP_Hook, 882 callbacks, 30 plugins, all
 * timed actions firing); end to end, 60 interleaved pairs per URL on real
 * requests could not tell it from zero (deliverables/t_260924_131543_554).
 *
 * Per-callback detail ("detailed" mode) interleaves a tick after every
 * callback (ABJ_404_Solution_HookTickLayout). Measured cost: about 1.3 ms
 * per request on an ordinary site (deliverables/t_260924_131543_554), which
 * is why it is never always-on. It switches on:
 * - in the SAME request, at the first action head reached after the request
 *   has used ESCALATE_FRACTION of its time limit (at least
 *   ESCALATE_FLOOR_S). A request that slow is the one that may die; one
 *   comparison per head is all a normal request pays for this.
 * - on a sampled 1 in N requests while the armed window is open. A recorded
 *   time-limit fatal opens it (TimeLimitFatalStore::arm()); it lives in an
 *   autoloaded option read from the already-loaded alloptions array (never a
 *   query) and closes itself when it expires.
 * Only actions are interleaved: a tick in a filter would replace the value.
 *
 * Nothing is resolved while the request is healthy: which plugin owns a
 * callback is worked out from the live registry only after a fatal, or at
 * shutdown of a detailed request. Never throws.
 *
 * Extension point: return false from the `abj404_request_time_attribution`
 * filter (in a must-use plugin, since it is read while this plugin loads) to
 * switch the recorder off entirely.
 */
final class ABJ_404_Solution_RequestTimeRecorder {

    /** Actions timed, in firing order. Front end, admin and login each hit a subset. */
    const TIMED_HOOKS = array(
        'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded',
        'parse_request', 'send_headers', 'wp', 'template_redirect', 'wp_head', 'wp_footer',
        'login_init', 'admin_menu', 'admin_init', 'current_screen',
        'admin_enqueue_scripts', 'admin_notices',
    );

    /** Share of the time limit after which a request switches to detailed mode. */
    const ESCALATE_FRACTION = 0.1;

    /** Never switch to detailed mode before this many seconds. */
    const ESCALATE_FLOOR_S = 1.0;

    /** Autoloaded option holding the armed window: {"until": epoch s, "one_in": N}. */
    const ARMED_OPTION = 'abj404_time_attribution_armed';

    /**
     * Why a request switched to per-callback timing. Owned here because this
     * class produces the reason and other classes branch on it (see
     * ABJ_404_Solution_TimeLimitFatalRecorder::onDetailedTiming); the values
     * are persisted evidence, so they never change.
     */
    const REASON_SAMPLED = 'sampled';
    const REASON_ESCALATED = 'escalated';

    /** Registry key of the head callback. */
    const HEAD_ID = self::class . '::onHookStart';

    /** Registry key of the tail callback. */
    const TAIL_ID = self::class . '::onHookEnd';

    /** @var bool Whether register() armed the recorder for this request. */
    private static $recording = false;

    /** @var bool Whether heads interleave per-callback ticks now. */
    private static $detailed = false;

    /** @var string Why detailed mode is on: '' or one of the REASON_* constants. */
    private static $detailReason = '';

    /** @var int|null Absolute ns at which to escalate; null = not computed yet. */
    private static $escalateAtNs = null;

    /** @var int Monotonic ns when the recorder was armed (plugin include). */
    private static $bootNs = 0;

    /** @var array<int, array{path: string, ns: int}> plugin_loaded stamps, ns since boot. */
    private static $plugins = array();

    /** @var array<string, array{start_ns: int, cpu_ms: int|null, repeated: bool, detailed: bool}> */
    private static $hooks = array();

    /** @var array<string, array<int, int>> Absolute monotonic ns per tick, [0] is the head. */
    private static $ticks = array();

    /** @var array<string, bool> Actions whose ticks are being recorded. */
    private static $open = array();

    /** @var array{function: array{0: string, 1: string}, accepted_args: int} Shared tick entry. */
    private static $tickEntry = array('function' => array(self::class, 'tick'), 'accepted_args' => 0);

    /** @var callable|null Test seam for the monotonic clock (ns). */
    private static $monotonicSource = null;

    /** @var callable|null Called with the reason when detailed mode switches on. */
    private static $onDetailed = null;

    /**
     * Arm the recorder for this request. Call once, while this plugin's main
     * file is being included, so the first plugin_loaded seen is our own.
     * No-op for AJAX, when the filter opts out, or without the plugin API.
     *
     * @param callable|null $onDetailed called once with REASON_SAMPLED or
     *   REASON_ESCALATED when this request switches to per-callback timing, so
     *   the caller can hook its shutdown work only then.
     * @return void
     */
    public static function register(?callable $onDetailed = null): void {
        if (self::$recording || !function_exists('add_action')) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if (function_exists('apply_filters') && apply_filters('abj404_request_time_attribution', true) === false) {
            return;
        }
        self::$bootNs = self::nowNs();
        self::$recording = true;
        self::$onDetailed = $onDetailed;
        // One boundary for the whole batch: a boundary per add_action would
        // multiply its cost on every request for no extra evidence.
        self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            'plugin_loaded', static function (): void {
                add_action('plugin_loaded', array(self::class, 'onPluginLoaded'), PHP_INT_MIN, 1);
                foreach (self::TIMED_HOOKS as $hook) {
                    add_action($hook, array(self::class, 'onHookStart'), PHP_INT_MIN, 0);
                    add_action($hook, array(self::class, 'onHookEnd'), PHP_INT_MAX, 0);
                }
            });
        $window = ABJ_404_Solution_TimeLimitFatalStore::armedWindow(
            ABJ_404_Solution_ExactInteger::readOr($_SERVER['REQUEST_TIME'] ?? null, 0, 0));
        if ($window['state'] === ABJ_404_Solution_TimeLimitFatalStore::WINDOW_EXPIRED) {
            self::scheduleDisarm();
        } elseif ($window['state'] === ABJ_404_Solution_TimeLimitFatalStore::WINDOW_OPEN && intdiv(self::nowNs(), 1024) % $window['one_in'] === 0) {
            // The sample is drawn from the monotonic clock's low bits.
            self::switchToDetailed(self::REASON_SAMPLED);
        }
    }

    /**
     * Whether register() armed the recorder.
     *
     * @return bool
     */
    public static function isRecording(): bool {
        return self::$recording;
    }

    /**
     * plugin_loaded callback: stamp the plugin whose include just finished.
     *
     * @param mixed $pluginFile full path of the plugin's main file.
     * @return void
     */
    public static function onPluginLoaded($pluginFile = ''): void {
        self::$plugins[] = array(
            'path' => is_string($pluginFile) ? $pluginFile : '',
            'ns' => self::nowNs() - self::$bootNs,
        );
    }

    /**
     * PHP_INT_MIN head of every timed action: stamp the start, read CPU
     * once, escalate to detailed mode when the request is already slow, and
     * interleave ticks when detailed. An action that fires again is flagged
     * repeated and stops recording, so the first fire stays exact.
     *
     * @return void
     */
    public static function onHookStart(): void {
        $stack = $GLOBALS['wp_current_filter'] ?? null;
        if (!is_array($stack) || $stack === array()) {
            return;
        }
        $hook = $stack[count($stack) - 1];
        if (!is_string($hook)) {
            return;
        }
        if (isset(self::$hooks[$hook])) {
            self::$hooks[$hook]['repeated'] = true;
            unset(self::$open[$hook]);
            return;
        }
        $now = self::nowNs();
        if (!self::$detailed) {
            if (self::$escalateAtNs === null) {
                self::$escalateAtNs = self::computeEscalateAtNs();
            }
            if ($now >= self::$escalateAtNs) {
                self::switchToDetailed(self::REASON_ESCALATED);
            }
        }
        // CPU per action only when detailed: getrusage() is a syscall, and a
        // normal request pays for hrtime stamps and array writes only (CPU
        // for the whole request comes from the boot snapshot and the fatal).
        self::$hooks[$hook] = array('start_ns' => $now - self::$bootNs, 'cpu_ms' => self::$detailed ? self::cpuMs() : null,
            'repeated' => false, 'detailed' => self::$detailed);
        self::$ticks[$hook] = array($now);
        self::$open[$hook] = true;
        if (self::$detailed) {
            ABJ_404_Solution_HookTickLayout::interleave(self::registry($hook), self::HEAD_ID, self::$tickEntry);
        }
    }

    /**
     * The interleaved tick: append the time to the running action's list.
     * Runs once per callback on detailed actions, so it does nothing else.
     *
     * @return void
     */
    public static function tick(): void {
        // Core's running-hook stack is a global other code can empty or unset;
        // read it as defensively as onHookStart() does.
        $stack = $GLOBALS['wp_current_filter'] ?? null;
        if (!is_array($stack) || $stack === array()) {
            return;
        }
        $hook = $stack[count($stack) - 1];
        if (is_string($hook) && isset(self::$open[$hook])) {
            self::$ticks[$hook][] = self::$monotonicSource === null ? (int)hrtime(true) : self::nowNs();
        }
    }

    /**
     * PHP_INT_MAX tail of every timed action: its end. On a detailed action
     * it also closes the interval of any callback appended to an earlier
     * priority after interleaving (core appends after that priority's final
     * tick).
     *
     * @return void
     */
    public static function onHookEnd(): void {
        self::tick();
    }

    /**
     * Everything recorded, times in ns since the recorder was armed.
     *
     * @return array{recording: bool, detail_reason: string, plugins: array<int, array{path: string, ns: int}>,
     *   hooks: array<string, array{start_ns: int, cpu_ms: int|null, repeated: bool, detailed: bool}>}
     */
    public static function snapshot(): array {
        return array('recording' => self::$recording, 'detail_reason' => self::$detailReason,
            'plugins' => self::$plugins, 'hooks' => self::$hooks);
    }

    /**
     * When an action's last recorded tick fired, in ns since the recorder
     * was armed: its end (the PHP_INT_MAX tail) when it ran to completion;
     * null when it never started.
     *
     * @param string $hook
     * @return int|null
     */
    public static function lastTickNs(string $hook): ?int {
        $ticks = self::$ticks[$hook] ?? null;
        if ($ticks === null || $ticks === array()) {
            return null;
        }
        return $ticks[count($ticks) - 1] - self::$bootNs;
    }

    /**
     * Monotonic ns elapsed since the recorder was armed, or null when it is
     * not recording.
     *
     * @return int|null
     */
    public static function elapsedNs(): ?int {
        return self::$recording ? self::nowNs() - self::$bootNs : null;
    }

    /**
     * Pair an action's recorded ticks with the live registry entries between
     * them. Interval k holds the callbacks that ran between tick k and tick
     * k+1 (tick 0 is the head), with its duration in ns. When $inFlight, the
     * interval after the last recorded tick runs to $nowNs and is flagged: it
     * holds the callback that was running when the request died. On an
     * action that was not detailed the single interval holds every callback.
     *
     * Null when the pairing cannot be trusted (the action never ran, its
     * registry is gone, or it recorded more ticks than it now holds); callers
     * then fall back to the action's total. Empty intervals are omitted.
     *
     * @param string $hook
     * @param int $nowNs absolute monotonic ns (the clock nowNs() reads).
     * @param bool $inFlight whether the action was still running at death.
     * @return array<int, array{callbacks: array<int, mixed>, ns: int, in_flight: bool}>|null
     */
    public static function intervalsForHook(string $hook, int $nowNs, bool $inFlight): ?array {
        $ticks = self::$ticks[$hook] ?? null;
        $registry = self::registry($hook);
        if ($ticks === null || !is_object($registry) || !isset($registry->callbacks) || !is_array($registry->callbacks)) {
            return null;
        }
        $groups = ABJ_404_Solution_HookTickLayout::groupsBetweenTicks($registry->callbacks, self::HEAD_ID,
            self::TAIL_ID, (bool)(self::$hooks[$hook]['detailed'] ?? false));
        if ($groups === null || count($ticks) > count($groups)) {
            return null;
        }
        $repeated = self::$hooks[$hook]['repeated'] ?? false;
        $intervals = array();
        $last = count($ticks) - 1;
        for ($k = 0; $k <= $last; $k++) {
            if ($k < $last) {
                $ns = $ticks[$k + 1] - $ticks[$k];
                $flight = false;
            } elseif ($inFlight && !$repeated) {
                $ns = max(0, $nowNs - $ticks[$k]);
                $flight = true;
            } else {
                break;
            }
            if ($groups[$k] !== array()) {
                $intervals[] = array('callbacks' => $groups[$k], 'ns' => $ns, 'in_flight' => $flight);
            }
        }
        return $intervals;
    }

    /**
     * The monotonic clock intervalsForHook() compares against.
     *
     * @return int absolute ns
     */
    public static function nowNs(): int {
        if (self::$monotonicSource !== null) {
            $tick = call_user_func(self::$monotonicSource);
            if (is_int($tick) || is_float($tick)) {
                return (int)$tick;
            }
        }
        return (int)hrtime(true);
    }

    /**
     * Return to a fresh process: not recording, nothing recorded, real clock.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$recording = false;
        self::$detailed = false;
        self::$detailReason = '';
        self::$escalateAtNs = null;
        self::$bootNs = 0;
        self::$plugins = array();
        self::$hooks = array();
        self::$ticks = array();
        self::$open = array();
        self::$monotonicSource = null;
        self::$onDetailed = null;
    }

    /**
     * Drive the monotonic clock from a test double returning ns; null
     * restores the real clock.
     *
     * @param callable|null $source
     * @return void
     */
    public static function setMonotonicSourceForTests(?callable $source): void {
        self::$monotonicSource = $source;
    }

    /**
     * The boundary this recorder's own hook registrations run in. It is
     * inert on purpose: the recorder never runs on AJAX, the only requests
     * that carry a request id, so an empty id (which makes the tracer skip
     * every write) is all that is possible here. The bracket stays because
     * the diagnostics-wide guard (DecisiveRecordManifestTest) requires every
     * hook mutation in this directory to run inside traceBoundary(); it costs
     * one hash per registration batch and writes nothing.
     *
     * @return ABJ_404_Solution_HookInstrumentationLifecycleTracer
     */
    private static function lifecycle(): ABJ_404_Solution_HookInstrumentationLifecycleTracer {
        return new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'request_time_recorder');
    }

    /**
     * The action's WP_Hook from core's registry, or null.
     *
     * @param string $hook
     * @return mixed
     */
    private static function registry(string $hook) {
        $filters = $GLOBALS['wp_filter'] ?? null;
        return is_array($filters) ? ($filters[$hook] ?? null) : null;
    }

    /**
     * Turn per-callback timing on for the rest of this request.
     *
     * @param string $reason one of the REASON_* constants.
     * @return void
     */
    private static function switchToDetailed(string $reason): void {
        self::$detailed = true;
        self::$detailReason = $reason;
        if (self::$onDetailed !== null) {
            call_user_func(self::$onDetailed, $reason);
        }
    }

    /**
     * Close a lapsed armed window once, at the end of this request.
     *
     * @return void
     */
    private static function scheduleDisarm(): void {
        self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            'shutdown', static function (): void {
                add_action('shutdown', array('ABJ_404_Solution_TimeLimitFatalStore', 'disarmIfExpired'));
            });
    }

    /**
     * The absolute ns at which this request has used ESCALATE_FRACTION of
     * its time limit. Anchored on the wall time the error handler recorded
     * at boot (no extra clock read); PHP_INT_MAX when there is no limit or
     * no anchor.
     *
     * @return int
     */
    private static function computeEscalateAtNs(): int {
        $limit = (int)ini_get('max_execution_time');
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $bootWall = class_exists('ABJ_404_Solution_ErrorHandler', false)
            ? ABJ_404_Solution_ErrorHandler::bootResourceSnapshot()['wall'] : null;
        if ($limit <= 0 || !is_numeric($start) || $bootWall === null) {
            return PHP_INT_MAX;
        }
        $thresholdS = max(self::ESCALATE_FLOOR_S, self::ESCALATE_FRACTION * $limit);
        $sinceStartAtBootS = max(0.0, $bootWall - (float)$start);
        return self::$bootNs + (int)(($thresholdS - $sinceStartAtBootS) * 1000000000);
    }

    /**
     * Process user+system CPU in whole ms, or null when getrusage() is
     * unavailable. Read once per timed action in detailed mode only, never
     * per callback.
     *
     * @return int|null
     */
    private static function cpuMs(): ?int {
        return ABJ_404_Solution_CpuUsage::totalMs(ABJ_404_Solution_PhpRuntimeCapabilityAdapter::resourceUsage());
    }
}
