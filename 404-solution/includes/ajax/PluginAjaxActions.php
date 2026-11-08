<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown when code asks for the hook name of an admin-ajax action that is not
 * in ABJ_404_Solution_PluginAjaxActions. It is a programming error caught at
 * boot, the same class of mistake ABJ_404_Solution_WPUtils::safeAddAction()
 * refuses for a duplicate tag: an endpoint registered without being listed
 * would have its killed requests reported as another plugin's WARN.
 */
class ABJ_404_Solution_UnlistedAjaxActionException extends LogicException {

    /** Stable machine-readable code for log and report grouping. */
    const CODE = 'unlisted_ajax_action';

    /** @var string */
    private $hint;

    public function __construct(string $action, string $hint, ?Throwable $previous = null) {
        parent::__construct('admin-ajax action "' . $action . '" is not in '
            . 'ABJ_404_Solution_PluginAjaxActions (' . self::CODE . ')', 0, $previous);
        $this->hint = $hint;
    }

    /** What to change so the registration is accepted. */
    public function getHint(): string {
        return $this->hint;
    }
}

/**
 * The one list of admin-ajax actions this plugin owns.
 *
 * Two consumers read it and must never disagree:
 *  - registration: every `wp_ajax_*` / `wp_ajax_nopriv_*` hook name the plugin
 *    adds comes from hook() / noprivHook(), which refuse an unlisted action;
 *  - failure severity: ABJ_404_Solution_AjaxFailureReporter treats a killed or
 *    timed-out request on a listed action as the plugin's own ERROR (named,
 *    with developer-report evidence), and any other action as a host killing
 *    someone else's worker (a WARN, bucketed as `other`).
 *
 * The reporter cannot ask WordPress which hooks are registered instead: it runs
 * from the census reaper on cron, front-end AJAX and other requests in which
 * the admin registrars never ran. A static list is the only answer that is the
 * same in every request.
 *
 * Dependency-free on purpose (no services, no WordPress calls) so the degraded
 * corrupt-install boot path can require this one file and still register its
 * support endpoints through it.
 *
 * PluginAjaxActionVocabularyRegistrationTest fails if any shipped file names a
 * `wp_ajax_` hook outside this class, if a registration names an unlisted
 * action, or if a listed action is registered nowhere.
 */
final class ABJ_404_Solution_PluginAjaxActions {

    /**
     * Every admin-ajax action the plugin registers, grouped by the code that
     * registers it. These strings are also the vocabulary the failure reporter
     * may write into log text, so each must stay `[A-Za-z0-9_]`.
     */
    const ACTIONS = array(
        // ABJ_404_Solution_AjaxAdminEndpointRegistrar
        'ajaxUpdatePaginationLinks',
        'ajaxRefreshStatsDashboard',
        'ajaxRefreshHealthBar',
        'ajaxRefreshAdminNonces',
        'abj404_run_lazy_backfill',
        'ajaxRunCanaryStep',
        // ABJ_404_Solution_WordPressHookRegistrar
        'echoViewLogsFor',
        'trashLink',
        'echoRedirectToPages',
        'updateOptions',
        'abj404_load_gsc_section',
        'abj404getTrendData',
        'abj404_crossPluginPreview',
        'abj404_gsc_oauth_callback',
        'abj404_gsc_revoke',
        'abj404_poll_suggestions',
        // ABJ_404_Solution_Ajax_EngineProfiles
        'abj404_engine_profiles_list',
        'abj404_engine_profiles_save',
        'abj404_engine_profiles_delete',
        // Settings, privacy and support endpoint classes (Ajax_*::init())
        'abj404_toggle_settings_mode',
        'abj404_restore_defaults',
        'abj404_privacy_export',
        'abj404_privacy_delete',
        'abj404_support_request',
        'abj404_support_request_preview',
        // ABJ_404_Solution_UninstallModal
        'abj404_save_uninstall_prefs',
        // ABJ_404_Solution_SetupWizard
        'abj404_dismiss_setup_wizard',
    );

    /** Owned actions also registered for logged-out visitors (`wp_ajax_nopriv_*`). */
    const NOPRIV_ACTIONS = array(
        'abj404_poll_suggestions',
    );

    /**
     * Whether an untrusted action string names one of the plugin's own
     * endpoints. Exact, case-sensitive match, as WordPress dispatches.
     *
     * @param mixed $action
     */
    public static function isOwned($action): bool {
        return is_string($action) && in_array($action, self::ACTIONS, true);
    }

    /**
     * The `wp_ajax_` hook name for an owned action.
     *
     * @param value-of<self::ACTIONS> $action
     * @throws ABJ_404_Solution_UnlistedAjaxActionException when the action is not listed.
     */
    public static function hook(string $action): string {
        if (!self::isOwned($action)) {
            throw new ABJ_404_Solution_UnlistedAjaxActionException($action,
                'add it to ABJ_404_Solution_PluginAjaxActions::ACTIONS so its failures are reported as the plugin\'s own');
        }
        return 'wp_ajax_' . $action;
    }

    /**
     * The `wp_ajax_nopriv_` hook name for an owned action open to visitors.
     *
     * @param value-of<self::NOPRIV_ACTIONS> $action
     * @throws ABJ_404_Solution_UnlistedAjaxActionException when the action is not listed as nopriv.
     */
    public static function noprivHook(string $action): string {
        if (!self::isOwned($action) || !in_array($action, self::NOPRIV_ACTIONS, true)) {
            throw new ABJ_404_Solution_UnlistedAjaxActionException($action,
                'add it to ABJ_404_Solution_PluginAjaxActions::NOPRIV_ACTIONS (and ACTIONS) before registering it for visitors');
        }
        return 'wp_ajax_nopriv_' . $action;
    }
}
