<?php


if (!defined('ABSPATH')) {
    exit;
}

// Standalone teardown loads only its inert collaborators through the same
// classmap as normal boot. Never initialize Loader.php or the service container.
require_once __DIR__ . '/../root-boot/Autoloader.php';
require_once __DIR__ . '/../core/PhpErrorLogFallback.php';
require_once __DIR__ . '/../services/OptionShapeRead.php';
abj404_autoloader('ABJ_404_Solution_OptionCacheInvalidator');
abj404_autoloader('ABJ_404_Solution_ExactInteger');
abj404_autoloader('ABJ_404_Solution_StorageOptionContracts');
abj404_autoloader('ABJ_404_Solution_SystemClock');
abj404_autoloader('ABJ_404_Solution_ScheduledEventInspector');
abj404_autoloader('ABJ_404_Solution_CronWriteOutcome');
abj404_autoloader('ABJ_404_Solution_CronScheduler');
abj404_autoloader('ABJ_404_Solution_DatabaseDriverCall');

/**
 * Handles plugin uninstallation
 * Standalone entry; shared teardown contracts require no plugin container or DAO.
 *
 * @since 2.36.11
 */
class ABJ_404_Solution_Uninstaller {

    /** Where the uninstall modal saves its choices (StorageOptionContracts::OPTION_UNINSTALL_PREFERENCES). */
    const PREFERENCES_OPTION = 'abj404_uninstall_preferences';

    /**
     * Every table the plugin creates, by suffix after {prefix}, mapped to the
     * modal choice that removes it. Admin-authored rules go with redirects,
     * captured traffic with logs, and anything rebuilt from those with cache.
     * Tables under `always` hold no data worth keeping. A table missing here
     * survives a partial uninstall, so UninstallTableCategoryCoverageTest
     * fails when a shipped DDL creates one this map does not name.
     */
    const TABLE_CATEGORIES = array(
        'abj404_redirects' => 'delete_redirects',
        'abj404_redirect_intents' => 'delete_redirects',
        'abj404_redirect_conditions' => 'delete_redirects',
        'abj404_engine_profiles' => 'delete_redirects',
        'abj404_logsv2' => 'delete_logs',
        'abj404_logs' => 'delete_logs',
        'abj404_lookup' => 'delete_logs',
        'abj404_permalink_cache' => 'delete_cache',
        'abj404_ngram_cache' => 'delete_cache',
        'abj404_spelling_cache' => 'delete_cache',
        'abj404_view_cache' => 'delete_cache',
        'abj404_logs_hits' => 'delete_cache',
        'abj404_logs_hits_temp' => 'always',
        'abj404_logs_hits_preagg' => 'always',
        'abj404_stats_dimensions' => 'delete_cache',
        'abj404_stats_buckets' => 'delete_cache',
    );

    /**
     * Options the plugin writes outside its abj404 namespace.
     * admin_notification_last_sent is EmailDigest's public compatibility copy.
     */
    const UNPREFIXED_OPTIONS = array('admin_notification_last_sent');

    /**
     * What uninstall.php runs: remove the plugin from every site it may have
     * run on, then from the network.
     *
     * WordPress runs uninstall.php once, from wherever the plugin was deleted,
     * and only after it was deactivated everywhere, so whether it was ever
     * network-active is no longer knowable and the current site is not the only
     * one with data. On a network every site is cleaned under its own table
     * prefix with the choices made for it: its own saved preferences (saved
     * when it was deactivated on that site), else the network's (saved when it
     * was network-deactivated), else the keep-your-data defaults.
     *
     * @return void
     */
    public static function uninstallPlugin(): void {
        if (!is_multisite()) {
            self::uninstall(self::resolvePreferences(ABJ_404_Solution_OptionShapeRead::arrayValue(self::PREFERENCES_OPTION), false));
            return;
        }

        $sitePageSize = 100;
        $siteOffset = 0;
        do {
            $siteIds = get_sites(array(
                'fields' => 'ids',
                'number' => $sitePageSize,
                'offset' => $siteOffset,
                'orderby' => 'id',
                'order' => 'ASC',
            ));
            if (!is_array($siteIds) || $siteIds === array()) {
                break;
            }
            foreach ($siteIds as $blogId) {
                switch_to_blog((int) $blogId);
                try {
                    $site = get_site((int) $blogId);
                    $networkId = ABJ_404_Solution_ExactInteger::read($site->site_id ?? null, 1);
                    // Switching blogs does not switch the current network. Never
                    // pass an unknown/zero ID: get_network_option would then read
                    // the deleting administrator's network instead of this site's.
                    $networkPreferences = $networkId === null ? false
                        : ABJ_404_Solution_OptionShapeRead::networkArrayValue(self::PREFERENCES_OPTION, false, $networkId);
                    self::uninstall(self::resolvePreferences(ABJ_404_Solution_OptionShapeRead::arrayValue(self::PREFERENCES_OPTION), $networkPreferences));
                } finally {
                    restore_current_blog();
                }
            }
            $siteOffset += count($siteIds);
        } while (count($siteIds) === $sitePageSize);
        self::deleteNetworkOptions();
    }

    /**
     * The first saved preference array wins; with none, keep redirects and
     * logs (the user may reinstall) and drop the rebuildable cache.
     *
     * @param mixed $sitePreferences    The site's saved option, or false.
     * @param mixed $networkPreferences The network's saved option, or false.
     * @return array<string, mixed>
     */
    private static function resolvePreferences($sitePreferences, $networkPreferences): array {
        $preferences = is_array($sitePreferences) ? $sitePreferences
            : (is_array($networkPreferences) ? $networkPreferences : array(
                'delete_redirects' => false,
                'delete_logs' => false,
                'delete_cache' => true,
                'send_feedback' => false,
                'uninstall_reason' => '',
                'feedback_email' => '',
                'feedback_details' => '',
            ));
        return ABJ_404_Solution_StorageOptionContracts::normalizeForRead(self::PREFERENCES_OPTION, $preferences);
    }

    /**
     * Main uninstall method
     * Processes deletion based on user preferences
     *
     * @param array<string, mixed> $preferences User's uninstall preferences from modal
     * @return void
     */
    public static function uninstall(array $preferences): void {
        global $wpdb;

        /** @var array<string, mixed> $preferences */

        // 1. Delete the system page while plugin tables are still available.
        // wp_delete_post() fires plugin callbacks that read those tables;
        // dropping them first lets the normal self-healing path recreate every
        // table during the uninstall request.
        $deleteAnyData = ($preferences['delete_redirects'] ?? false)
            || ($preferences['delete_logs'] ?? false)
            || ($preferences['delete_cache'] ?? false);
        if ($deleteAnyData) {
            self::deleteSystemPage();
        }

        // 2. Delete database tables based on user preferences
        $droppedEveryTable = self::deleteTables($wpdb, $preferences);

        // 3. Delete all WordPress options. The schema high-water mark
        // (ABJ_404_Solution_SchemaHighWaterMark::OPTION, spelled out because
        // uninstall runs without the autoloader) records the newest build that
        // shaped these tables. It goes only with the tables: a kept table may
        // still hold a newer build's columns, and without the mark an older
        // build installed next would drop them.
        self::deleteAllOptions($droppedEveryTable ? array() : array('abj404_schema_high_water'));

        // 4. Clean up scheduled cron jobs
        self::cleanupCronJobs();

        // Feedback is sent at deactivate-time by UninstallModal (the modal AJAX
        // handler dispatches before WordPress deletes the plugin). WordPress
        // enforces deactivate-before-delete, so any feedback the user opted into
        // has already been queued/sent by the time uninstall.php runs. Sending
        // again here from the persisted preferences would be a double-send.
    }

    /**
     * Delete the auto-created system page (if it exists).
     * Finds it via post meta _abj404_system_page = 1.
     *
     * @return void
     */
    private static function deleteSystemPage(): void {
        // Guard: get_posts may not exist during standalone uninstall (no autoloader).
        if (!function_exists('get_posts')) {
            return;
        }

        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'meta_key'       => '_abj404_system_page',
            'meta_value'     => '1',
            'posts_per_page' => 5,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        if (is_array($pages)) {
            foreach ($pages as $pageId) {
                wp_delete_post((int) $pageId, true);
            }
        }
    }

    /**
     * Delete database tables based on user preferences
     *
     * @param object $wpdb        WordPress database object (wpdb or compatible)
     * @param array<string, mixed> $preferences User preferences
     * @return bool True when every plugin table was dropped (all three deletion
     *              preferences on), false when any category was kept.
     */
    private static function deleteTables(object $wpdb, array $preferences): bool {
        // Use wpdb prefix directly - Uninstaller must be standalone (no autoloader)
        /** @var \wpdb $wpdb */
        $prefix = strtolower($wpdb->prefix);

        $deleteRedirects = $preferences['delete_redirects'] ?? false;
        $deleteLogs      = $preferences['delete_logs']      ?? false;
        $deleteCache     = $preferences['delete_cache']     ?? false;

        // When ALL data-deletion options are selected, use SHOW TABLES for dynamic
        // discovery — the DB is the source of truth for which plugin tables exist.
        // This ensures future tables are cleaned up even if this list isn't updated.
        if ($deleteRedirects && $deleteLogs && $deleteCache) {
            // DAO-bypass-approved: Plugin-table discovery during uninstall — no DAO autoloader
            $allTables = ABJ_404_Solution_DatabaseDriverCall::getResults($wpdb,
                // DAO-bypass-approved: Plugin-table discovery during uninstall — no DAO autoloader
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix . 'abj404_') . '%'),
                ARRAY_N
            );
            if (is_array($allTables)) {
                foreach ($allTables as $row) {
                    if (is_string($row[0])) {
                        self::deleteTable($row[0]);
                    }
                }
            }
            return true;
        }

        // Partial deletion: each table goes with the choice that owns it.
        foreach (self::TABLE_CATEGORIES as $suffix => $category) {
            if ($category === 'always' || !empty($preferences[$category])) {
                self::deleteTable($prefix . $suffix);
            }
        }
        return false;
    }

    /**
     * Safely delete a database table.
     *
     * Defense-in-depth: refuses any name that is not shaped like a plugin
     * table ({prefix}abj404_{suffix}). Today the only callers are the
     * partial-deletion paths in deleteTables() using {prefix} + 'abj404_*'
     * constants and a 'SHOW TABLES LIKE ...abj404_%' discovery loop, so an
     * unsafe value cannot reach here. The shape check inside the sink keeps
     * that property robust against future maintenance routing a user-derived
     * value into the same private method, without relying on esc_sql() (which
     * does not escape backticks and so cannot protect an identifier context
     * on its own).
     *
     * @param string $table_name Full table name with prefix
     * @return void
     */
    private static function deleteTable(string $table_name): void {
        global $wpdb;

        // CRON GUARD: Uninstaller.php is loaded only by uninstall.php which
        // WordPress invokes during operator-driven plugin removal, never via
        // cron. Refuse cron context as a structural backstop so
        // CronReachableDestructiveSqlLintTest can prove the DROP TABLE below
        // is unreachable from any cron tick.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }

        // q992: refuse anything outside the {prefix}abj404_{suffix} shape.
        if (!preg_match('/^[a-z0-9_]+abj404_[a-z0-9_]+$/i', $table_name)) {
            return;
        }

        // DAO-bypass-approved: DDL drop during uninstall. No DAO autoloader available.
        ABJ_404_Solution_DatabaseDriverCall::query($wpdb, "DROP TABLE IF EXISTS `$table_name`");
    }

    /**
     * Delete every plugin option and transient of the current site: the
     * abj404 namespace (settings, run markers, request-census rows such as
     * abj404inflight* and abj404reqdone*, lock keys) plus UNPREFIXED_OPTIONS.
     * A sweep rather than a list, because a list goes stale the day a new
     * option ships and every name it misses outlives the plugin.
     *
     * @param array<int, string> $keep Option names to leave in place.
     * @return void
     */
    public static function deleteAllOptions(array $keep = array()): void {
        global $wpdb;

        $optionsTable = $wpdb->options ?? (($wpdb->prefix ?? 'wp_') . 'options');
        $optionPageSize = 100;
        $lastOptionName = '';
        do {
            // DAO-bypass-approved: wp_options cleanup during uninstall, no DAO autoloader
            $names = ABJ_404_Solution_DatabaseDriverCall::getCol($wpdb,
                // DAO-bypass-approved: wp_options cleanup during uninstall, no DAO autoloader
                $wpdb->prepare(
                    "SELECT option_name FROM {$optionsTable} WHERE option_name > %s AND (option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s) ORDER BY option_name ASC LIMIT %d",
                    $lastOptionName,
                    $wpdb->esc_like('abj404') . '%',
                    $wpdb->esc_like('_transient_abj404') . '%',
                    $wpdb->esc_like('_transient_timeout_abj404') . '%',
                    $optionPageSize
                )
            );
            $names = array_values(array_filter(is_array($names) ? $names : array(), 'is_string'));
            if ($names === array()) {
                break;
            }
            foreach (array_diff($names, $keep) as $name) {
                // delete_option() rather than a DELETE so the object cache forgets it too.
                delete_option($name);
            }
            // Advance from the fetched page, not from successful deletions: a
            // refused delete must not make the next page repeat forever.
            $lastOptionName = (string) end($names);
        }
        while (count($names) === $optionPageSize);
        foreach (array_diff(self::UNPREFIXED_OPTIONS, $keep) as $name) {
            // delete_option() rather than a DELETE so the object cache forgets it too.
            delete_option($name);
        }
    }

    /**
     * Delete the plugin's network options and site transients, for every
     * network on the install, once all sites are clean.
     *
     * @return void
     */
    private static function deleteNetworkOptions(): void {
        global $wpdb;

        $sitemetaTable = $wpdb->sitemeta ?? (($wpdb->base_prefix ?? 'wp_') . 'sitemeta');
        $metaPageSize = 100;
        $lastMetaId = 0;
        do {
            // DAO-bypass-approved: Multisite sitemeta cleanup during uninstall, no DAO autoloader
            $rows = ABJ_404_Solution_DatabaseDriverCall::getResults($wpdb,
                // DAO-bypass-approved: Multisite sitemeta cleanup during uninstall, no DAO autoloader
                $wpdb->prepare(
                    "SELECT meta_id, site_id, meta_key FROM {$sitemetaTable} WHERE meta_id > %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s) ORDER BY meta_id ASC LIMIT %d",
                    $lastMetaId,
                    $wpdb->esc_like('abj404') . '%',
                    $wpdb->esc_like('_site_transient_abj404') . '%',
                    $wpdb->esc_like('_site_transient_timeout_abj404') . '%',
                    $metaPageSize
                ),
                ARRAY_A
            );
            if (!is_array($rows) || $rows === array()) {
                break;
            }
            $rows = array_values(array_filter($rows, 'is_array'));
            if ($rows === array()) {
                break;
            }
            $pageLastMetaId = $lastMetaId;
            foreach ($rows as $row) {
                $metaId = ABJ_404_Solution_ExactInteger::read($row['meta_id'] ?? null, 1);
                if ($metaId !== null && $metaId > $pageLastMetaId) {
                    $pageLastMetaId = $metaId;
                }
                if (!is_string($row['meta_key'] ?? null)) {
                    continue;
                }
                $networkId = ABJ_404_Solution_ExactInteger::read($row['site_id'] ?? null, 1);
                if ($networkId !== null) {
                    delete_network_option($networkId, $row['meta_key']);
                }
            }
            if ($pageLastMetaId <= $lastMetaId) {
                break;
            }
            $lastMetaId = $pageLastMetaId;
        } while (count($rows) === $metaPageSize);
    }

    /**
     * Clean up all scheduled cron jobs
     * @return void
     */
    public static function cleanupCronJobs(): void {
        // The standalone dependency loads above include the shared registry
        // and verified bulk removal, without booting the plugin lifecycle.
        $scheduler = new ABJ_404_Solution_CronScheduler(new ABJ_404_Solution_SystemClock());
        $scheduler->clearRegisteredHooks(array_merge(
            ABJ_404_Solution_CronScheduler::registeredHooks(),
            array('abj404_updatePermalinkCache', 'abj404_cleanupCron')
        ));
    }

    /**
     * Get list of all tables created by this plugin
     *
     * @return array<int, string> Array of table names (without prefix)
     */
    public static function getTableNames(): array {
        return array_keys(self::TABLE_CATEGORIES);
    }
}
