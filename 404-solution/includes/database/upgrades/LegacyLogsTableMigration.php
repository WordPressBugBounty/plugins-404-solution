<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies the pre-1.8.0 log table into the current log schema and retires the
 * old table after rows have moved. Upgrade version and cron admission belong
 * to the caller; this class owns the legacy storage operation.
 */
final class ABJ_404_Solution_LegacyLogsTableMigration {

    /** @var object */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Bind the query connection and migration logger.
     *
     * @param object $dbCore Database query and table-name resolver service.
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(object $dbCore, ABJ_404_Solution_Logging $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Migrate existing legacy logs, retaining the old table when no rows move.
     *
     * @return void
     */
    public function migrate(): void {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }
        $query = "SHOW TABLES LIKE '{wp_abj404_logs}'";
        $dbCore = $this->dbCore;
        if (!$dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface
            || !$dbCore instanceof ABJ_404_Solution_DatabaseCoreInterface) {
            throw new \RuntimeException('PluginLogicVersionUpgrader requires database query and table-name resolver methods.');
        }

        $result = $dbCore->queryAndGetResults($query);
        $rows = isset($result['rows']) ? $result['rows'] : array();

        $filteredRows = is_array($rows) ? array_filter($rows) : array();
        if (empty($filteredRows)) {
            return;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../../sql/migrateToNewLogsTable.sql');
        $query = $dbCore->doTableNameReplacements($query);
        $result = $dbCore->queryAndGetResults($query);

        $rowsAffected = ABJ_404_Solution_ExactInteger::readOr(
            $result['rows_affected'] ?? null,
            0,
            0
        );
        // The caller only admits database versions before 1.8.0 here, so the
        // version gate that previously wrapped this block has been removed
        // (PHPStan smaller.alwaysTrue).
        if ($rowsAffected > 0) {
            $this->logger->infoMessage($rowsAffected .
                ' log rows were migrated to the new table structre.');
            $dbCore->queryAndGetResults('drop table ' . $dbCore->tableNameResolver()->getLowercasePrefix() . 'abj404_logs');
        }
    }
}
