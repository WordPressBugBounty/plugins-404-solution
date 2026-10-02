<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a fatal is a time limit inside 404 Solution's own files and
 * hooks the WordPress surfaces that then correct the blame, at the moment of
 * failure:
 * - WordPress core's recovery-mode email (filter recovery_mode_email; and
 *   is_protected_endpoint, so core sends it for a front-end request too).
 *   The email is worded by ABJ_404_Solution_TimeLimitRecoveryEmail.
 * - The critical-error page (filter wp_php_error_message): one sentence,
 *   only for viewers who already see details there (manage_options, or
 *   WP_DEBUG with WP_DEBUG_DISPLAY). Anonymous visitors see core's message.
 * - The PHP error log line and the stored admin record are written by
 *   ABJ_404_Solution_TimeLimitFatalRecorder, from the breakdown computed here.
 *
 * Core runs its fatal handler (and so both filters) before this plugin's
 * shutdown handler, so the breakdown is computed at the first of these that
 * runs and reused, keyed to the same fatal.
 *
 * Only fatals whose file is 404 Solution's are touched: those are the ones
 * core attributes to us. Core sends the email (rate-limited to one a day);
 * this class never sends mail of its own. It only widens which endpoints
 * core emails about, for this one kind of fatal.
 *
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 */
final class ABJ_404_Solution_TimeLimitFatalReporter {

    /** @var Record|null Breakdown computed for this request's fatal. */
    private static $breakdown = null;

    /** @var string|null Identity of the fatal the breakdown belongs to. */
    private static $breakdownFor = null;

    /** @var bool Whether register() already ran. */
    private static $registered = false;

    /**
     * Hook the three core filters. Three add_filter calls; nothing else runs
     * until a fatal. Call once at plugin boot.
     *
     * @return void
     */
    public static function register(): void {
        if (self::$registered || !function_exists('add_filter')) {
            return;
        }
        self::$registered = true;
        self::lifecycle()->traceBoundary(ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            'recovery_mode_email', static function (): void {
                add_filter('recovery_mode_email', array(self::class, 'filterRecoveryEmail'), 10, 2);
                add_filter('wp_php_error_message', array(self::class, 'filterErrorPageMessage'), 10, 2);
                add_filter('is_protected_endpoint', array(self::class, 'filterProtectedEndpoint'), 10, 1);
            });
    }

    /**
     * The boundary this class's own hook registrations run in. Inert on
     * purpose: it registers at plugin boot on every request, before any
     * request id exists, so the empty id makes the tracer skip every write.
     * The bracket stays because the diagnostics-wide guard
     * (DecisiveRecordManifestTest) requires every hook mutation in this
     * directory to run inside traceBoundary().
     *
     * @return ABJ_404_Solution_HookInstrumentationLifecycleTracer
     */
    private static function lifecycle(): ABJ_404_Solution_HookInstrumentationLifecycleTracer {
        return new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'time_limit_fatal_reporter');
    }

    /**
     * is_protected_endpoint filter. Core sends its recovery email only for a
     * fatal on a protected endpoint (wp-admin, wp-login.php, a few AJAX
     * actions), and core consults this filter only while it handles a
     * fatal. A time-limit fatal in our files on a front-end request (the
     * common case: bot 404s on a slow site) would otherwise never reach the
     * site owner, so the request counts as protected for that fatal only.
     * Core's own one-email-a-day rate limit still applies. Any other fatal
     * leaves core's decision as it was.
     *
     * @param mixed $isProtected core's (or an earlier filter's) decision.
     * @param array<string, mixed>|null $error the fatal; error_get_last() when null.
     * @return mixed
     */
    public static function filterProtectedEndpoint($isProtected, ?array $error = null) {
        if ($isProtected === true) {
            return true;
        }
        return self::breakdownFor($error ?? error_get_last()) !== null ? true : $isProtected;
    }

    /**
     * recovery_mode_email filter.
     *
     * @param mixed $email core's array: to, subject, message, headers, attachments.
     * @param mixed $url the recovery-mode URL (unused; already in the message).
     * @param array<string, mixed>|null $error the fatal; error_get_last() when null.
     * @return mixed
     */
    public static function filterRecoveryEmail($email, $url = '', ?array $error = null) {
        $breakdown = self::breakdownFor($error ?? error_get_last());
        if ($breakdown === null || !is_array($email)) {
            return $email;
        }
        return ABJ_404_Solution_TimeLimitRecoveryEmail::compose($email, $breakdown);
    }

    /**
     * wp_php_error_message filter.
     *
     * @param mixed $message core's HTML message.
     * @param mixed $error error_get_last() as core passes it.
     * @return mixed
     */
    public static function filterErrorPageMessage($message, $error = null) {
        if (!is_string($message) || !self::viewerSeesDetails()) {
            return $message;
        }
        $breakdown = self::breakdownFor(is_array($error) ? $error : error_get_last());
        if ($breakdown === null) {
            return $message;
        }
        return $message . strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitParagraph.html'),
            array('{text}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::errorPageSentence($breakdown))));
    }

    /**
     * The breakdown for a time-limit fatal in our files, or null. Computed
     * once per fatal.
     *
     * @param mixed $error
     * @return Record|null
     */
    public static function breakdownFor($error): ?array {
        // Cheap prefix test first, so an out-of-memory fatal (the other
        // fatal this handler sees most) never autoloads the attribution
        // classes while memory is exhausted. Past it the raw record is
        // parsed once; everything below takes the parsed Fatal.
        if (!is_array($error) || !is_string($error['message'] ?? null)
                || strpos($error['message'], 'Maximum execution time of') !== 0) {
            return null;
        }
        $fatal = ABJ_404_Solution_TimeLimitEvidence::parseFatal($error);
        if ($fatal === null) {
            return null;
        }
        $resolver = ABJ_404_Solution_CodeOwnerResolver::forCurrentSite();
        if ($resolver->ownerOfFile($fatal['file'])['kind'] !== 'self') {
            return null;
        }
        $identity = $fatal['file'] . ':' . $fatal['line'] . ':' . $fatal['message'];
        if (self::$breakdown !== null && (self::$breakdownFor === $identity || self::$breakdownFor === '*')) {
            return self::$breakdown;
        }
        try {
            self::$breakdown = ABJ_404_Solution_TimeLimitBreakdown::build(
                ABJ_404_Solution_TimeLimitEvidence::collect($fatal, $resolver));
            self::$breakdownFor = $identity;
        } catch (Throwable $e) {
            abj404_logPhpFallback('fatal-handler-fallback',
                'time-limit breakdown failed (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage());
            return null;
        }
        return self::$breakdown;
    }

    /**
     * Forget this request's breakdown.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$breakdown = null;
        self::$breakdownFor = null;
        self::$registered = false;
    }

    /**
     * Use a canned breakdown for any time-limit fatal in our files.
     *
     * @param array<string, mixed> $breakdown
     * @return void
     */
    public static function setBreakdownForTests(array $breakdown): void {
        self::$breakdown = ABJ_404_Solution_TimeLimitRecord::normalize($breakdown);
        self::$breakdownFor = '*';
    }

    /**
     * Whether this viewer may see the sentence: everyone when the site
     * displays PHP errors anyway (WP_DEBUG with WP_DEBUG_DISPLAY), otherwise
     * only whoever the plugin's admin access policy lets into its own admin
     * screen, the same people the sentence points to.
     *
     * @return bool
     */
    private static function viewerSeesDetails(): bool {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
            return true;
        }
        return function_exists('abj404_current_user_is_plugin_admin') && abj404_current_user_is_plugin_admin();
    }
}
