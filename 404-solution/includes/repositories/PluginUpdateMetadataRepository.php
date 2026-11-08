<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin self-maintenance metadata: wordpress.org version lookup, version-gap
 * heuristic for error email throttling, and the legacy Redirectioner import.
 *
 * Extracted from the DataAccess monolith to keep DataAccess.php under the
 * file size limit (FileSizeLimitsTest::MAX_LINES). Methods on the DAO remain
 * as thin delegates so existing call sites and test doubles continue to work.
 */
class ABJ_404_Solution_PluginUpdateMetadataRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null
    ) {
        $this->dbCore = $dbCore;
        // Keep the historical dependency slot validated for callers that still
        // provide it; import policy now belongs to CrossPluginImporter.
        if ($functions !== null && !is_object($functions)) {
            throw new InvalidArgumentException('Plugin update functions dependency must be an object.');
        }
        $this->functions = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /** @return array{version: string, last_updated: string|null} */
    public function getLatestPluginVersion(): array {
        // Cache version info to avoid repeated slow wordpress.org API calls.
        $cacheKey = 'abj404_latest_plugin_version_info';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['version'])) {
                /** @var array{version: string, last_updated: string|null} $cached */
                return $cached;
            }
        }

        if (!function_exists('plugins_api')) {
            $pluginInstallPath = ABSPATH . 'wp-admin/includes/plugin-install.php';
            if (is_readable($pluginInstallPath)) {
                require_once($pluginInstallPath);
            }
        }
        if (!function_exists('plugins_api')) {
            $this->logger->infoMessage("I couldn't find the plugins_api function to check for the latest version.");
            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        $pluginSlug = dirname(ABJ404_NAME);

        $args = array(
            'slug' => $pluginSlug,
            'fields' => array(
                'version' => true,
                'last_updated' => true,
            )
        );

        $call_api = plugins_api('plugin_information', $args);

        if (is_wp_error($call_api)) {
            $api_error = $call_api->get_error_message();
            $this->logger->infoMessage("There was an API issue checking the latest plugin version ("
                    . $api_error . ")");

            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        /** @var object $call_api */
        $apiVersion = property_exists($call_api, 'version') ? (string)$call_api->version : ABJ404_VERSION;
        $apiLastUpdated = property_exists($call_api, 'last_updated') ? (string)$call_api->last_updated : null;
        $result = array('version' => $apiVersion, 'last_updated' => $apiLastUpdated);
        if (function_exists('set_transient')) {
            $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            // allow-cache-empty: $result always carries a version string (fallback to ABJ404_VERSION when plugins_api omits it); is_wp_error early-returns above
            set_transient($cacheKey, $result, $ttl);
        }
        return $result;
    }

    /**
     * Decide whether the error file should be emailed based on the gap between
     * the installed plugin version and the latest published version. Accepts
     * the version info as an argument so the DAO can route the lookup through
     * its own (potentially mocked) `getLatestPluginVersion()`.
     *
     * @param array{version: string, last_updated: string|null} $pluginInfo
     * @return bool
     */
    public function shouldEmailErrorFileFor(array $pluginInfo): bool {
        $latestVersion = $pluginInfo['version'];
        $currentVersion = ABJ404_VERSION;
        if ($latestVersion == $currentVersion) {
            return true;
        }

        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " .
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " .
                     $latestVersion . ").");
            return true;
        }

        $currentArray = explode(".", $currentVersion);
        $latestArray = explode(".", $latestVersion);

        if (count($currentArray) != 3 || count($latestArray) != 3) {
            $this->logger->errorMessage("Issue parsing version numbers. " .
                    $currentVersion . ' / ' . $latestVersion);

        } else if ($currentArray[0] == $latestArray[0] && $currentArray[1] == $latestArray[1]) {
            $difference = absint(absint($latestArray[2]) - absint($currentArray[2]));

            if ($difference <= 1) {
                return true;
            }
        }

        return (ABJ404_VERSION == $pluginInfo['version']);
    }

    /** @return array<string, mixed> */
    public function importDataFromPluginRedirectioner(): array {
        global $wpdb;

        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $tableStatus = $this->dbCore->tableNameResolver()->tableExistenceStatus($oldTable);
        if ($tableStatus === ABJ_404_Solution_DatabaseTableNameResolver::TABLE_ABSENT) {
            /* translators: %s is the legacy Redirectioner source table name. */
            $message = sprintf(
                __('[ABJ404-IMPORT-SOURCE-NOT-FOUND] Legacy Redirectioner source table "%s" was not found; no records were imported. Confirm that the legacy redirects are present in this WordPress installation before retrying the import.', '404-solution'),
                $oldTable
            );
            $this->logger->warn($message);
            return array('rows_affected' => 0, 'last_error' => '', 'errors' => array($message), 'issues_omitted' => 0);
        }
        $rowsAffected = 0;
        $lastID = 0;
        $issueSummary = new ABJ_404_Solution_ImportIssueSummary();
        $lastError = '';
        $batchSize = 250;

        // The old importer used one INSERT...SELECT. Read a bounded id-keyset
        // page, then admit each row through the production writer so cycle
        // checks, duplicate policy, caches, and denorm maintenance are shared.
        while (true) {
            $priorID = $lastID;
            $query = "SELECT id AS id, url, status, type, final_dest, code, disabled, timestamp " .
                "FROM `" . $oldTable . "` WHERE id > %d ORDER BY id ASC LIMIT " . $batchSize;
            $page = $this->dbCore->queryAndGetResults($query, array('query_params' => array($lastID)));
            try {
                $rows = ABJ_404_Solution_ForeignSourceQueryGateway::requireSourceRows($page);
            } catch (ABJ_404_Solution_ImportSourceReadException $e) {
                $lastError = $e->getMessage();
                break;
            }
            if (empty($rows)) {
                break;
            }

            $typedRows = array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $typedRows[] = $row;
                    $rowID = $this->legacyRowID($row);
                    $lastID = max($lastID, $rowID);
                }
            }
            if ($lastID <= $priorID) {
                $lastError = 'Legacy Redirectioner import stopped because source IDs did not advance past ' .
                    $priorID . '; no further rows were imported.';
                $this->logger->warn($lastError);
                break;
            }
            // Bootstrap owns the import policy. Supply this reader's own
            // dependencies so a custom/injected database is never replaced
            // with the globally registered connection during row admission.
            $importRows = abj_service('redirectioner_row_importer');
            if (!is_callable($importRows)) {
                $resolvedType = is_object($importRows) ? get_class($importRows) : gettype($importRows);
                $lastError = 'Legacy Redirectioner import refused: redirectioner_row_importer resolved to ' .
                    $resolvedType . ', expected callable; no further rows were imported.';
                $this->logger->warn($lastError);
                break;
            }
            $importResult = $importRows($typedRows, array(
                'core' => $this->dbCore, 'functions' => $this->functions, 'logging' => $this->logger,
            ));
            $rowsAffected += ABJ_404_Solution_ExactInteger::readOr(
                $importResult['imported'] ?? null, 0, 0
            );
            $pageSummary = $this->legacyPageIssueSummary($importResult);
            $issueSummary = new ABJ_404_Solution_ImportIssueSummary(
                array_merge($issueSummary->details(), $pageSummary->details()),
                $issueSummary->omittedCount() + $pageSummary->omittedCount()
            );

            if (count($rows) < $batchSize) {
                break;
            }
        }

        $result = array(
            'rows_affected' => $rowsAffected,
            'last_error' => $lastError,
            'errors' => $issueSummary->messages(),
            'issues_omitted' => $issueSummary->omittedCount(),
        );
        $this->logger->infoMessage("Importing redirectioner import result: " .
            wp_kses_post((string)json_encode($result)));
        return $result;
    }

    /**
     * Normalize one row-import callback's diagnostic payload, including older
     * callbacks that return only issues, before combining bounded summaries.
     * @param array<string, mixed> $result
     */
    private function legacyPageIssueSummary(array $result): ABJ_404_Solution_ImportIssueSummary {
        $raw = $result['issue_details'] ?? null;
        if (!is_array($raw)) {
            $raw = $result['issues'] ?? array();
        }
        $details = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : array();
        $omitted = ABJ_404_Solution_ExactInteger::readOr($result['issues_omitted'] ?? null, 0, 0);
        return new ABJ_404_Solution_ImportIssueSummary($details, $omitted);
    }

    /** @param array<mixed, mixed> $row */
    private function legacyRowID(array $row): int {
        foreach ($row as $key => $value) {
            if (strtolower((string)$key) === 'id') {
                return ABJ_404_Solution_ExactInteger::readOr($value, 1, 0);
            }
        }
        return 0;
    }
}
