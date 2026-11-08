<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers WordPress lifecycle, admin, AJAX, and async suggestion hooks.
 */
class ABJ_404_Solution_WordPressHookRegistrar {

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    public static function registerAll(array $callbacks): void {
        self::registerLifecycleHooks();
        // A request WordPress dispatched as cron retires any recorded stall,
        // in every context (wp-cron.php is not an admin request).
        add_action('init', array('ABJ_404_Solution_CronDispatchStallEvidence', 'observeRequest'), 0, 0);
        self::registerAdminHooks($callbacks);
        self::registerAsyncSuggestionHooks($callbacks);
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }

    /** Register the lazy permalink-option listener in every WordPress context. */
    public static function registerPermalinkCacheHooks(): void {
        // Retain this newer entry for loaders already linked before an update.
        ABJ_404_Solution_PermalinkCache::init();
    }

    /**
     * Lazy WordPress boundary for permalink-option changes in every context.
     * @param string $option
     * @param mixed $oldStructure
     * @param mixed $newStructure
     */
    public static function dispatchPermalinkCacheChange($option, $oldStructure, $newStructure): void {
        if ($option === 'permalink_structure') {
            abj_service('permalink_cache')->permalinkStructureChanged($option, $oldStructure, $newStructure);
        }
    }

    /** @return void */
    private static function registerLifecycleHooks(): void {
        register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogicLifecycle::runOnPluginDeactivation');
        register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogicLifecycle::runOnPluginActivation');

        if (is_multisite()) {
            foreach (self::siteLifecycleHooks(function_exists('wp_initialize_site')) as $hook) {
                if ($hook['type'] === 'filter') {
                    add_filter($hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args']);
                } else {
                    add_action($hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args']);
                }
            }
        }
    }

    /**
     * The hooks through which creating and deleting a site on a network
     * reach the plugin, for the generation of WordPress that is running.
     *
     * WordPress 5.1 replaced wpmu_new_blog and delete_blog with
     * wp_initialize_site and wp_uninitialize_site, but still fires the old
     * pair through do_action_deprecated() whenever anything listens. Listening
     * to both would activate every new site twice and log a deprecation
     * notice on every site created or deleted, so exactly one generation is
     * registered. From 5.1 the deleted site's plugin tables join core's own
     * drop through wpmu_drop_tables; its options and cron go with its
     * options table.
     *
     * @param bool $wordPressInitializesSites Whether wp_initialize_site() exists (WordPress 5.1+).
     * @return array<int,array{type:string,hook:string,callback:callable-string,priority:int,accepted_args:int}>
     */
    public static function siteLifecycleHooks(bool $wordPressInitializesSites): array {
        if ($wordPressInitializesSites) {
            return array(
                array('type' => 'action', 'hook' => 'wp_initialize_site',
                    'callback' => 'ABJ_404_Solution_PluginLogicLifecycle::activateNewSiteModern', 'priority' => 10, 'accepted_args' => 2),
                array('type' => 'filter', 'hook' => 'wpmu_drop_tables',
                    'callback' => 'ABJ_404_Solution_PluginLogicLifecycle::addPluginTablesToDrop', 'priority' => 10, 'accepted_args' => 2),
            );
        }
        return array(
            array('type' => 'action', 'hook' => 'wpmu_new_blog',
                'callback' => 'ABJ_404_Solution_PluginLogicLifecycle::activateNewSite', 'priority' => 10, 'accepted_args' => 6),
            array('type' => 'action', 'hook' => 'delete_blog',
                'callback' => 'ABJ_404_Solution_PluginLogicLifecycle::deleteBlogData', 'priority' => 10, 'accepted_args' => 2),
        );
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    private static function registerAdminHooks(array $callbacks): void {
        if (!is_admin()) {
            return;
        }

        add_filter("plugin_action_links_" . ABJ404_NAME,
            self::callback($callbacks, 'settings_link'));
        add_filter('plugin_row_meta',
            self::callback($callbacks, 'plugin_row_meta'), 10, 2);
        add_action('admin_notices',
            self::callback($callbacks, 'review_notice'));
        add_action('admin_init',
            self::callback($callbacks, 'review_redirects'));
        // Ordinary admin readiness is the fallback admission boundary. The
        // n-gram component performs the request-kind and plugin-admin checks
        // before it can register post-response work.
        self::registerNGramFallbackReadiness();
        add_action('admin_init', array(__CLASS__, 'armMinLogIdCronFallback'), PHP_INT_MAX);
        add_action('admin_init', array(__CLASS__, 'armAdminTableRepairCronFallback'), PHP_INT_MAX);
        add_action('admin_init', array(__CLASS__, 'armLegacyPageRecoveryCronFallback'), PHP_INT_MAX);
        add_action('admin_init', array(__CLASS__, 'armCollationRepairCronFallback'), PHP_INT_MAX);
        add_action('admin_init', array(__CLASS__, 'armStatusCountsCronFallback'), PHP_INT_MAX);
        add_action('admin_menu',
            self::callback($callbacks, 'settings_page'));
        add_action('admin_enqueue_scripts',
            self::callback($callbacks, 'admin_assets'), 11);
        add_action('admin_enqueue_scripts',
            self::callback($callbacks, 'plugins_page_assets'), 11);
        add_action('admin_head',
            self::callback($callbacks, 'admin_theme_css'), 1);
        // Grants delegated plugin admins (plugin_admin_users) manage_options on
        // the plugin's own screens only. Registered here, at boot, because
        // PluginLogic is resolved from the DI container on a normal load.
        add_filter('user_has_cap',
            'ABJ_404_Solution_PluginAdminAccessPolicy::wpUserHasCapFilter', 10, 4);

        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('echoViewLogsFor'), self::callback($callbacks, 'ajax_view_logs'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('trashLink'), self::callback($callbacks, 'ajax_trash_link'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('echoRedirectToPages'), self::callback($callbacks, 'ajax_redirect_to_pages'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('updateOptions'), self::callback($callbacks, 'ajax_update_options'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_load_gsc_section'), self::callback($callbacks, 'ajax_load_gsc_section'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404getTrendData'), self::callback($callbacks, 'ajax_trend_data'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_crossPluginPreview'), self::callback($callbacks, 'ajax_cross_plugin_preview'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_gsc_oauth_callback'), self::callback($callbacks, 'ajax_gsc_oauth_callback'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_gsc_revoke'), self::callback($callbacks, 'ajax_gsc_revoke'));

        ABJ_404_Solution_Ajax_EngineProfiles::registerActions();
        ABJ_404_Solution_Ajax_SettingsModeToggle::init();
        ABJ_404_Solution_Ajax_RestoreDefaults::init();
        ABJ_404_Solution_Ajax_PrivacyExport::init();
        ABJ_404_Solution_Ajax_PrivacyDelete::init();
        ABJ_404_Solution_Ajax_SupportRequest::init();
        ABJ_404_Solution_Ajax_SupportRequestPreview::init();
        ABJ_404_Solution_UninstallModal::init();
        ABJ_404_Solution_SetupWizard::init();
        if (class_exists('ABJ_404_Solution_Privacy')) {
            ABJ_404_Solution_Privacy::init();
        }
    }

    /** @return void */
    public static function armNGramCronFallback(): void {
        try {
            $upgrades = abj_service('database_upgrades');
            $upgrades->components()->nGramUpgrade()->armNGramCronFallback();
        } catch (Throwable $e) {
            abj404_logRuntimeWarning('Admin ngram fallback admission failed', $e);
        }
    }

    /** Register the ordinary admin-page readiness boundary for n-gram recovery. */
    public static function registerNGramFallbackReadiness(): void {
        add_action('admin_init', array(__CLASS__, 'armNGramCronFallback'), PHP_INT_MAX);
    }

    /** Independent admission: a busy n-gram chain cannot strand log migration. */
    public static function armMinLogIdCronFallback(): void {
        try {
            abj_service('database_upgrades')->components()->addedColumnBackfillUpgrade()->armMinLogIdCronFallback();
        } catch (Throwable $error) {
            abj404_logRuntimeWarning('Admin min_log_id fallback admission failed', $error);
        }
    }

    /** Independent admission: the queued admin-table repair (denorm backfill). */
    public static function armAdminTableRepairCronFallback(): void {
        try {
            abj_service('database_upgrades')->components()->selfHealUpgrade()->armAdminTableRepairCronFallback();
        } catch (Throwable $error) {
            abj404_logRuntimeWarning('Admin table repair fallback admission failed', $error);
        }
    }

    /** Independent admission: the queued legacy generated-page recovery. */
    public static function armLegacyPageRecoveryCronFallback(): void {
        try {
            (new ABJ_404_Solution_LegacySuggestionPageRecovery())->armAdminContinuation();
        } catch (Throwable $error) {
            abj404_logRuntimeWarning('Admin legacy page recovery fallback admission failed', $error);
        }
    }

    /** Independent admission: the queued plugin-table collation repair. */
    public static function armCollationRepairCronFallback(): void {
        try {
            (new ABJ_404_Solution_QueuedCollationRepair(
                abj_service('database_upgrades')->components()->collationDriftUpgrade()))->arm();
        } catch (Throwable $error) {
            abj404_logRuntimeWarning('Admin collation repair fallback admission failed', $error);
        }
    }

    /** Independent admission: the queued per-scope status-count refreshes. */
    public static function armStatusCountsCronFallback(): void {
        try {
            abj_service('view_read_service')->armStatusCountsAdminContinuations();
        } catch (Throwable $error) {
            abj404_logRuntimeWarning('Admin status-count refresh fallback admission failed', $error);
        }
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    private static function registerAsyncSuggestionHooks(array $callbacks): void {
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_poll_suggestions'), self::callback($callbacks, 'ajax_poll_suggestions'));
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::noprivHook('abj404_poll_suggestions'), self::callback($callbacks, 'ajax_poll_suggestions'));
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return callable-string
     */
    private static function callback(array $callbacks, string $key): string {
        return $callbacks[$key];
    }
}
