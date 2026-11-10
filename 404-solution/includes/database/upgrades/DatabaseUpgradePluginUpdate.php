<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseUpgradePluginUpdate extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Number of redirect ids repaired per statement. Keeps each UPDATE's id list
     * short; it matches the denorm maintenance chunk size.
     */
    const ESCAPED_DESTINATION_REPAIR_CHUNK_SIZE = 1000;

    /**
     * Set while decodeEscapedExternalDestinations() was stopped by a failing
     * database before it finished; the next plugin upgrade runs it again.
     */
    const ESCAPED_DESTINATION_REPAIR_PENDING_OPTION = 'abj404_escaped_destination_repair_pending';

    /** Whether an earlier escaped-destination repair stopped before it finished. */
    static function escapedExternalDestinationRepairPending(): bool {
        return ABJ_404_Solution_OptionShapeRead::scalar(self::ESCAPED_DESTINATION_REPAIR_PENDING_OPTION, '') === '1';
    }

    /**
     * Resolve the plugin-update metadata repository. Test subclasses can
     * preload `$this->pluginUpdateRepo` via reflection; production paths
     * read it from the service container; bare instantiation contexts
     * (no container) build a fresh repo from the carrier deps.
     *
     * @return ABJ_404_Solution_PluginUpdateMetadataRepository
     */
    private function resolvePluginUpdateRepo(): ABJ_404_Solution_PluginUpdateMetadataRepository {
        if ($this->pluginUpdateRepo instanceof ABJ_404_Solution_PluginUpdateMetadataRepository) {
            return $this->pluginUpdateRepo;
        }
        if (class_exists('ABJ_404_Solution_ServiceContainer')
                && ABJ_404_Solution_ServiceContainer::getInstance()->has('plugin_update_metadata_repository')) {
            $repo = ABJ_404_Solution_ServiceContainer::getInstance()->get('plugin_update_metadata_repository');
            if ($repo instanceof ABJ_404_Solution_PluginUpdateMetadataRepository) {
                $this->pluginUpdateRepo = $repo;
                return $repo;
            }
        }
        $dbCore = $this->dbCore instanceof ABJ_404_Solution_DatabaseCore
            ? $this->dbCore
            : abj_service('db_core');
        $fresh = new ABJ_404_Solution_PluginUpdateMetadataRepository(
            $dbCore, $this->f, $this->logger
        );
        $this->pluginUpdateRepo = $fresh;
        return $fresh;
    }


    /**
     * Migrate existing redirects from absolute paths to relative paths.
     * This is a one-time migration for upgrading from versions prior to 2.37.0.
     * Fixes Issue #24: Redirects now survive WordPress subdirectory changes.
     *
     * Uses a single atomic SQL UPDATE statement - no locks or transactions needed.
     *
     * @return array<string, mixed> Migration results with counts
     */
    function migrateURLsToRelativePaths() {
        global $wpdb;

        $abj404logging = abj_service('logging');

        // Get current WordPress subdirectory
        $homeURL = get_home_url();
        $urlPath = parse_url($homeURL, PHP_URL_PATH);

        if ($urlPath === false || $urlPath === null) {
            $urlPath = '';
        }

        $decodedPath = rawurldecode(rtrim($urlPath, '/'));
        $subdirectory = preg_replace('/[\x00-\x1F\x7F]/', '', $decodedPath);

        $results = array(
            'redirects_updated' => 0,
            'subdirectory' => $subdirectory,
            'errors' => array()
        );

        // Skip if WordPress is at domain root (no subdirectory)
        if (empty($subdirectory) || $subdirectory === '/') {
            $abj404logging->debugMessage("No subdirectory detected. Migration skipped.");
            return $results;
        }

        $startTime = abj_clock()->nowFloat();
        $redirectsTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_redirects');

        $abj404logging->infoMessage("Migrating redirects table to relative paths...");

        // Single SQL UPDATE - atomic at database level, no transaction needed
        // Uses CHAR_LENGTH() for UTF-8 multibyte character safety
        $subdirectoryWithSlash = $subdirectory . '/';

        // canonical_url stays in lockstep with url — same CASE expression
        // applied to both columns so the captured-page JOIN keeps matching
        // logs_hits.requested_url after the path rewrite. Wrapped in
        // CONCAT('/', TRIM(BOTH '/' FROM ...)) to preserve the canonical form
        // (no leading-slash duplication, no trailing slash). The CASE WHEN
        // canonical_url IS NOT NULL guard avoids overwriting NULL rows
        // (post-upgrade, pre-backfill) which the daily backfill will fill.
        $canonicalCase = "CASE
                 WHEN url = %s OR url = %s THEN '/'
                 WHEN url LIKE %s THEN CONCAT('/', SUBSTRING(url, CHAR_LENGTH(%s) + 1))
                 ELSE url
             END";

        // DAO-bypass-approved: One-shot path-relativization migration; $wpdb->prepare is the only safe way to bind 11 ordered placeholders for the multi-line CASE expression; result is fed to the approved $wpdb->query below.
        $updateQuery = $wpdb->prepare(
            "UPDATE {$redirectsTable}
             SET url = " . $canonicalCase . ",
                 canonical_url = CASE
                     WHEN canonical_url IS NULL THEN NULL
                     ELSE CONCAT('/', TRIM(BOTH '/' FROM (" . $canonicalCase . ")))
                 END
             WHERE url = %s OR url = %s OR url LIKE %s",
            $subdirectory,                                  // url CASE: exact match /blog
            $subdirectoryWithSlash,                         // url CASE: with slash /blog/
            $wpdb->esc_like($subdirectoryWithSlash) . '%', // url CASE: with path /blog/*
            $subdirectoryWithSlash,                         // url SUBSTRING length
            $subdirectory,                                  // canonical CASE: exact match /blog
            $subdirectoryWithSlash,                         // canonical CASE: with slash /blog/
            $wpdb->esc_like($subdirectoryWithSlash) . '%', // canonical CASE: with path /blog/*
            $subdirectoryWithSlash,                         // canonical SUBSTRING length
            $subdirectory,                                  // WHERE: exact match
            $subdirectoryWithSlash,                         // WHERE: with slash
            $wpdb->esc_like($subdirectoryWithSlash) . '%'  // WHERE: with path
        );

        // DAO-bypass-approved: One-shot path-relativization migration; already prepared (multi-line CASE with prefix args), wpdb->query for rows-affected return; tightly mocked in DatabaseMigrationTest
        $updateResult = ABJ_404_Solution_DatabaseDriverCall::query($wpdb, $updateQuery);

        // Check for errors
        if ($updateResult === false) {
            $results['errors'][] = "Failed to update redirects: " . $wpdb->last_error;
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($wpdb->last_error ?? '')) {
                $abj404logging->errorMessage("Migration failed: " . $wpdb->last_error);
            }
        } else {
            $results['redirects_updated'] = $updateResult;

            $duration = abj_clock()->nowFloat() - $startTime;
            $abj404logging->infoMessage(sprintf(
                "Migrated %d redirects in %.4f seconds.",
                $results['redirects_updated'],
                $duration
            ));

            // Note: Log entries are intentionally NOT migrated for performance.
            // Historical logs with absolute paths are display-only and don't affect functionality.

            // Mark migration as complete
            update_option('abj404_migrated_to_relative_paths', '1');
            update_option('abj404_migration_results', $results);
            $abj404logging->infoMessage("Migration to relative paths completed successfully.");
        }

        return $results;
    }

    /**
     * Repair external destinations that the admin Add/Edit form stored
     * HTML-escaped (report 753).
     *
     * Those forms validated the typed URL with esc_url(), whose default 'display'
     * context rewrites "&" to "&#038;" and "'" to "&#039;". The value was stored
     * in final_dest and sent as the Location header, so the browser read the
     * rest of the URL after the first entity as a fragment and the target never
     * received any query parameter after the first. Saving through
     * the form again corrupted a redirect that older versions had stored
     * correctly. The save path now stores what was typed; this restores the rows
     * the old path wrote.
     *
     * Touches only final_dest of external-destination rows (type 4) that contain
     * one of the two entities esc_url() emits, so a row without them is never
     * written. Idempotent: a repaired row no longer matches. A run that a failing
     * database stops (the SELECT or any chunk) sets the pending marker, and the
     * next plugin upgrade runs the repair again to finish it. The derived display
     * columns (dest_for_view, dest_sort_key) are recomputed through the denorm
     * maintenance service, the one place that maps an external destination to its
     * display value. The redirects table is never dropped or truncated.
     *
     * @return int Number of external redirects whose final_dest was decoded.
     */
    function decodeEscapedExternalDestinations(): int {
        $redirects = '{wp_abj404_redirects}';
        $external = (int)ABJ404_TYPE_EXTERNAL;
        $hasEntity = "(LOCATE('&#038;', final_dest) > 0 OR LOCATE('&#039;', final_dest) > 0)";

        $found = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirects . " WHERE type = " . $external . " AND " . $hasEntity
        );
        if (($found['query_succeeded'] ?? false) !== true) {
            // queryAndGetResults() already logged the error. Not knowing which
            // rows need it is not "nothing to repair": retry on the next upgrade.
            $this->markEscapedDestinationRepairPending(true);
            return 0;
        }
        $ids = array();
        $rows = isset($found['rows']) && is_array($found['rows']) ? $found['rows'] : array();
        foreach ($rows as $row) {
            $id = is_array($row) && isset($row['id']) && is_scalar($row['id']) ? (int)$row['id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (empty($ids)) {
            $this->markEscapedDestinationRepairPending(false);
            return 0;
        }

        $repaired = 0;
        $maintenance = new ABJ_404_Solution_RedirectsDenormMaintenanceService($this->dbCore, $this->logger);
        foreach (array_chunk($ids, self::ESCAPED_DESTINATION_REPAIR_CHUNK_SIZE) as $chunk) {
            // The "''''" below is one SQL single quote.
            $result = $this->dbCore->queryAndGetResults(
                "UPDATE " . $redirects .
                " SET final_dest = REPLACE(REPLACE(final_dest, '&#038;', '&'), '&#039;', '''')" .
                " WHERE id IN (" . implode(',', $chunk) . ") AND type = " . $external . " AND " . $hasEntity
            );
            if (($result['query_succeeded'] ?? false) !== true) {
                // queryAndGetResults() already logged the error and classified an
                // infrastructure failure. The rows left still carry the entity, so
                // the next upgrade's run finds and finishes them.
                $this->logger->warn(sprintf(
                    'Could not decode escaped external redirect destinations after %d of %d row(s); ' .
                    'the next plugin update retries the rest: %s',
                    $repaired,
                    count($ids),
                    is_string($result['last_error'] ?? null) ? $result['last_error'] : '(no error text)'
                ));
                $this->markEscapedDestinationRepairPending(true);
                return $repaired;
            }
            $repaired += ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0);
            // Per chunk, so a later chunk's failure (read-only, disk full) does not
            // skip the display columns of a chunk already written. These columns
            // are a display and sort cache: when the recompute itself cannot write,
            // the service logs why and its nightly reconcile (Step 3d) recomputes
            // them, so the result does not decide whether this repair is pending.
            $maintenance->recomputeByRedirectIds($chunk);
        }

        $this->logger->infoMessage(sprintf(
            'Decoded the HTML-escaped query string of %d external redirect destination(s) (report 753).',
            $repaired
        ));
        $this->markEscapedDestinationRepairPending(false);
        return $repaired;
    }

    /** Record whether the repair still has rows to finish; written only when the state changes. */
    private function markEscapedDestinationRepairPending(bool $pending): void {
        if ($pending === self::escapedExternalDestinationRepairPending()) {
            return;
        }
        if ($pending) {
            // @cache-write-audit: opt-out - stores a retry marker for an unfinished upgrade step, not a query result
            update_option(self::ESCAPED_DESTINATION_REPAIR_PENDING_OPTION, '1', false);
            return;
        }
        delete_option(self::ESCAPED_DESTINATION_REPAIR_PENDING_OPTION);
    }


    /** @return void */
    function updatePluginCheck() {

        $pluginInfo = $this->resolvePluginUpdateRepo()->getLatestPluginVersion();

        $shouldUpdate = $this->shouldUpdate($pluginInfo);
        
        if ($shouldUpdate) {
            $this->doUpdatePlugin($pluginInfo);
        }
    }
    
    /**
     * @param array<string, mixed> $pluginInfo
     * @return void
     */
    function doUpdatePlugin($pluginInfo) {

        $targetVersion = isset($pluginInfo['version']) && is_scalar($pluginInfo['version'])
            ? (string)$pluginInfo['version']
            : '';
        $this->logger->infoMessage("Attempting update to " . $targetVersion);
        
        // do the update.
        if (!class_exists('WP_Upgrader')) {
        	$this->logger->infoMessage("Including WP_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->infoMessage("Including Plugin_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        if (!function_exists('show_message')) {
        	$this->logger->infoMessage("Including misc.php for update.");
        	require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->warn("There was an issue including the Plugin_Upgrader class.");
        	return;
        }
        if (!function_exists('show_message')) {
        	$this->logger->warn("There was an issue including the misc.php class.");
        	return;
        }
        
        $this->logger->infoMessage("Includes for update complete. Updating... ");
        
        ob_start();
        $upgrader = new Plugin_Upgrader();
        $upret = $upgrader->upgrade(ABJ404_SOLUTION_BASENAME);
        if ($upret) {
            $this->logger->infoMessage("Plugin successfully upgraded to " . $targetVersion);
        }
        $output = "";
	        if (@ob_get_contents()) {
	            $outputValue = @ob_get_contents();
	            $output = is_string($outputValue) ? $outputValue : '';
	            @ob_end_clean();
	        }
        if ($this->f->strlen(trim($output)) > 0) {
            $this->logger->infoMessage("Upgrade output: " . $output);
        }
        
        $activateResult = activate_plugin(ABJ404_NAME);
        if ($activateResult instanceof WP_Error) {
            $this->logger->errorMessage("Plugin activation error " .
                json_encode($activateResult->get_error_codes()) . ": " . json_encode($activateResult->get_error_messages()));
            
        } else {
            $this->logger->infoMessage("Successfully reactivated plugin after upgrade to version " .
                $targetVersion);
        }        
    }
    
    /**
     * @param array<string, mixed> $pluginInfo
     * @return bool
     */
    function shouldUpdate($pluginInfo) {


        $options = abj_service('options_repository')->getOptions(true);
        $latestVersion = isset($pluginInfo['version']) && is_string($pluginInfo['version']) ? $pluginInfo['version'] : '';

        if (ABJ404_VERSION == $latestVersion) {
            $this->logger->debugMessage("The latest plugin version is already installed (" .
                    ABJ404_VERSION . ").");
            return false;
        }

        // don't overwrite development versions.
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return false;
        }
        
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, array('127.0.0.1', '::1', 'localhost'))) {
            $this->logger->infoMessage("Update narrowly avoided on localhost.");
            return false;
        }        
        
        // 1.12.0 becomes array("1", "12", "0")
        $myVersionArray = explode(".", ABJ404_VERSION);
        $latestVersionArray = explode(".", $latestVersion);

        // check the latest date to see if it's been long enough to update.
        $lastUpdated = isset($pluginInfo['last_updated']) && is_string($pluginInfo['last_updated']) ? $pluginInfo['last_updated'] : '';
        $lastReleaseDate = new DateTime($lastUpdated);
        $todayDate = new DateTime('@' . abj_clock()->now());
        $dateInterval = $lastReleaseDate->diff($todayDate);
        $daysDifference = $dateInterval->days;
        
        // if there's a new minor version then update.
        // only update if it was released at least 3 days ago.
        if ($myVersionArray[0] == $latestVersionArray[0] && 
        	$myVersionArray[1] == $latestVersionArray[1] && 
        	intval($myVersionArray[2]) < intval($latestVersionArray[2]) &&
        	$daysDifference >= 3) {
        		
            $this->logger->infoMessage("A new minor version is available (" . 
                    $latestVersion . "), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        // An unreadable stored wait falls back to the shipped default, never to
        // 0: the settings page defines 0 as "update immediately", so a corrupt
        // value would otherwise switch this safety delay off.
        $minDaysDifference = ABJ_404_Solution_ExactInteger::readOr(
            $options['days_wait_before_major_update'] ?? null,
            0,
            ABJ_404_Solution_ExactInteger::readOr(
                ABJ_404_Solution_PluginLogicDefaults::defaults()['days_wait_before_major_update'] ?? null,
                0,
                PHP_INT_MAX
            )
        );
        if ($daysDifference >= $minDaysDifference) {
            $this->logger->infoMessage("The latest major version is old enough for updating automatically (" . 
                    $minDaysDifference . "days minimum, version " . $latestVersion . " is " . $daysDifference . 
                    " days old), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        return false;
    }

}
