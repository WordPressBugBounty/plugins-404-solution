<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs work that translates text only once WordPress allows translating.
 *
 * Why this exists: the plugin's database layer runs at plugins_loaded (the
 * same-site request census writes an option row on every admin request), so a
 * failed query there reaches code that persists an admin notice before
 * after_setup_theme. Calling __() at that moment is wrong twice over. Since
 * WordPress 6.7 the first such call loads the textdomain just in time and logs
 * "Translation loading for the 404-solution domain was triggered too early"
 * (wp-includes/l10n.php, _load_textdomain_just_in_time, which tests
 * `! doing_action( 'after_setup_theme' ) && ! did_action( 'after_setup_theme' )`),
 * and the text is translated before the request's user and locale are settled.
 *
 * A producer hands the whole job, translation included, to whenReady():
 *
 *     ABJ_404_Solution_DeferredTranslation::whenReady(function () use ($error) {
 *         $noticeState->setPluginDbNotice('read_only', __('...', '404-solution'), ...);
 *     });
 *
 * When translation is already allowed the work runs at once, so callers behave
 * exactly as before. Earlier than that it is queued and run, in order, when
 * after_setup_theme fires. Flags and cooldowns a producer sets OUTSIDE the
 * closure take effect immediately; only the translated text waits.
 *
 * A request can also end before after_setup_theme (another plugin's fatal or
 * exit). Its cooldown flags are already armed, so the next request will not
 * retry the failing write and would never raise the notice: dropping the job
 * would hide the problem for the whole cooldown window. So the queue is also
 * drained on `shutdown`. If translation is still not allowed there, the jobs
 * run with this plugin's text domain holding WordPress core's own null
 * Translations object (the one get_translations_for_domain() installs for a
 * domain with nothing to load). __() then returns its English argument and never
 * reaches _load_textdomain_just_in_time(), which is what logs the too-early
 * notice for any __() before after_setup_theme, whatever the locale. The notice
 * is persisted in English; that beats no notice, and the window is closed again
 * as soon as the jobs have run.
 *
 * The English literal lives only inside the closure, next to its single __()
 * call, so translation extraction sees every string exactly once, and a
 * producer needs no knowledge of which of the two paths ran it.
 */
final class ABJ_404_Solution_DeferredTranslation {

    /** @var string The hook after which WordPress itself permits translation. */
    const READY_HOOK = 'after_setup_theme';

    /** @var string The hook that ends every request, including one that died early. */
    const FALLBACK_HOOK = 'shutdown';

    /** @var string This plugin's text domain, the one __() calls in queued work use. */
    const TEXT_DOMAIN = '404-solution';

    /** @var array<int, Closure> Work waiting for READY_HOOK, in arrival order. */
    private static $pending = array();

    /** @var bool Whether the flush callback is registered for this request. */
    private static $flushArmed = false;

    private function __construct() {
    }

    /**
     * Run $work now if WordPress allows translating, otherwise when it first does.
     *
     * @param Closure $work The translating work. Its return value is ignored.
     * @return void
     */
    public static function whenReady(Closure $work): void {
        if (self::isReady()) {
            $work();
            return;
        }
        self::$pending[] = $work;
        if (!self::$flushArmed) {
            self::$flushArmed = true;
            add_action(self::READY_HOOK, array(self::class, 'flush'), PHP_INT_MAX, 0);
            add_action(self::FALLBACK_HOOK, array(self::class, 'flushAtShutdown'), PHP_INT_MAX, 0);
        }
    }

    /**
     * Whether a translation call is permitted right now. Mirrors the test
     * WordPress core applies, and is true wherever that test cannot apply
     * (no hook system at all), so unit-level callers run their work inline.
     *
     * @return bool
     */
    public static function isReady(): bool {
        if (!function_exists('did_action') || !function_exists('add_action')) {
            return true;
        }
        if (did_action(self::READY_HOOK) > 0) {
            return true;
        }
        return function_exists('doing_action') && doing_action(self::READY_HOOK);
    }

    /**
     * Run everything queued, in arrival order. Registered on READY_HOOK; public
     * only because WordPress calls it by name. One failing job does not strand
     * the rest: every job runs, then the first failure is rethrown.
     *
     * @return void
     */
    public static function flush(): void {
        self::drain(true);
    }

    /**
     * Drain whatever READY_HOOK did not get to, because the request is ending.
     * Registered on FALLBACK_HOOK; public only because WordPress calls it by
     * name. Translates when WordPress now allows it (after_setup_theme fired but
     * flush() never ran, for instance after an earlier callback died); otherwise
     * persists the English text rather than lose the notice.
     *
     * @return void
     */
    public static function flushAtShutdown(): void {
        self::drain(self::isReady());
    }

    /**
     * @param bool $translate False runs each job with translation switched off.
     * @return void
     */
    private static function drain(bool $translate): void {
        $queue = self::$pending;
        self::$pending = array();
        self::$flushArmed = false;
        $firstFailure = null;
        foreach ($queue as $work) {
            try {
                if ($translate) {
                    $work();
                } else {
                    self::runUntranslated($work);
                }
            } catch (Throwable $e) {
                if ($firstFailure === null) {
                    $firstFailure = $e;
                }
            }
        }
        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * Run $work with this plugin's text domain answering from core's null
     * Translations object, so __() returns its English argument without ever
     * reaching the just-in-time loader. A catalogue somebody already loaded is
     * left in place (then there is no too-early notice to avoid). Outside a
     * WordPress boot there is no such class and the work runs as it is.
     *
     * @param Closure $work
     * @return void
     */
    private static function runUntranslated(Closure $work): void {
        global $l10n;
        if (!class_exists('NOOP_Translations') || (is_array($l10n) && isset($l10n[self::TEXT_DOMAIN]))) {
            $work();
            return;
        }
        if (!is_array($l10n)) {
            $l10n = array();
        }
        $l10n[self::TEXT_DOMAIN] = new NOOP_Translations();
        try {
            $work();
        } finally {
            unset($l10n[self::TEXT_DOMAIN]);
        }
    }

    /** Test seam, and the request-scoped reset the harness calls by name. */
    public static function resetForTests(): void {
        self::$pending = array();
        self::$flushArmed = false;
    }
}
