<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lowercase-rename maintenance operation for plugin tables.
 *
 * Owns making all plugin table names lowercase (for hosts running with
 * lower_case_table_names=0), and detecting/adopting orphaned plugin tables
 * left under old prefixes (from site migrations or the rename bug in
 * v2.35.16 through v3.x). This is a prefix-based, tenant-isolation-aware
 * flow: on multisite, a sibling subsite owns and lowercases its own tables,
 * so a table under a DIFFERENT active blog's prefix is left alone; a
 * genuinely orphaned prefix (no live blog) is renamed and handed to the
 * orphan-adoption collaborator to recover.
 *
 * Extracted from the table-bootstrap orchestrator (DatabaseUpgradeBootstrap)
 * because table-bootstrap orchestration and the lowercase-rename maintenance
 * operation are distinct concerns: this one runs unconditionally on every
 * createDatabaseTables() pass, has its own MySQL-variable-driven skip path,
 * and reaches into information_schema directly rather than the plugin's own
 * tables.
 *
 * Collaborator of ABJ_404_Solution_DatabaseUpgradeBootstrap, which constructs
 * it fresh on every call (never cached) with the upgrade coordinator, the
 * current DB core / logger, and a pre-computed active-blog-prefix set, so it
 * always observes whichever dependencies are current at call time (dbCore /
 * logger can be swapped at runtime via replaceDatabaseUpgradeDependencies()
 * on the owning component).
 */
class ABJ_404_Solution_DatabaseTableLowercaseRenamer {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $coordinator;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var array<int, string> */
    private $activeBlogPrefixesLowercase;

    /** @var callable|null Resolves foreign-install prefixes through the shared component boundary. */
    private $foreignWordPressOptionsPrefixResolver;

    /**
     * @param ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator
     * @param ABJ_404_Solution_DatabaseCore $dbCore Deliberately untyped at the
     *   PHP level (matching ABJ_404_Solution_DatabaseUpgradeComponent::$dbCore):
     *   this collaborator needs methods from both DatabaseCoreInterface
     *   (tableNameResolver) and DatabaseQueryInterface (queryAndGetResults),
     *   and PHP 7.4 (the plugin's floor version) has no intersection types.
     *   Test doubles (e.g. Abj404NullDatabaseCore) implement those interfaces
     *   without extending the concrete class, so a native type hint here would
     *   break them.
     * @param ABJ_404_Solution_Logging $logger
     * @param array<int, string> $activeBlogPrefixesLowercase Lowercased table
     *   prefixes of every active site on the network (or just the current
     *   prefix on single-site), computed once by the caller before
     *   construction since it's a pure read with no per-call state.
     * @param callable|null $foreignWordPressOptionsPrefixResolver Existing
     *   tenant-boundary reader supplied by the owning component.
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $coordinator,
            $dbCore, ABJ_404_Solution_Logging $logger,
            array $activeBlogPrefixesLowercase, ?callable $foreignWordPressOptionsPrefixResolver = null) {
        $this->coordinator = $coordinator;
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->activeBlogPrefixesLowercase = $activeBlogPrefixesLowercase;
        $this->foreignWordPressOptionsPrefixResolver = $foreignWordPressOptionsPrefixResolver;
    }

    /** Makes all plugin table names lowercase, in case someone thought it was funny to use
     * the lower_case_table_names=0 setting. Also detects and adopts orphaned plugin tables
     * under old prefixes (from site migrations or the rename bug in v2.35.16 through v3.x).
     * @return void
     */
    public function rename() {
        global $wpdb;

        // On case-insensitive MySQL (lower_case_table_names >= 1), table names
        // are already treated as lowercase internally. Renaming is pointless and
        // can cause issues on some hosting setups.
        // DAO-bypass-approved: Schema-bootstrap inside renameAbj404TablesToLowerCase(). Runs before plugin DAO is fully wired during DB upgrades.
        $lctnResult = ABJ_404_Solution_DatabaseDriverCall::getRow($wpdb, "SHOW VARIABLES LIKE 'lower_case_table_names'", ARRAY_A);
        if (is_array($lctnResult)) {
            $lctnValue = null;
            foreach ($lctnResult as $key => $value) {
                if (strtolower((string)$key) === 'value') {
                    $lctnValue = $value;
                    break;
                }
            }
            if (is_scalar($lctnValue) && (int)$lctnValue >= 1) {
                // MySQL already handles table names case-insensitively.
                // Still run adoption check in case of prefix mismatch.
                $this->coordinator->orphanAdoptionUpgrade()->adoptOrphanedTables();
                return;
            }
        }

        // Fetch all tables containing "abj404", case-insensitive
        $dbNameRaw = $wpdb->dbname ?? '';
        if ($dbNameRaw === '') {
            $this->logger->warn("Could not determine database name for lowercase rename.");
            return;
        }
        $inventory = $this->readTableInventory($dbNameRaw);
        if ($inventory === null) {
            $this->logger->warn("Could not verify information_schema tables for lowercase rename; deferring rename.");
            return;
        }

        // Tenant isolation: a sibling subsite owns and lowercases its own
        // tables. Never rename tables that belong to a DIFFERENT active
        // multisite blog (same whole-schema-scan class as the orphan-adoption
        // finding). A migrated old prefix with no live blog is absent from the
        // active-blog set and is still renamed so adoption can recover it.
        $currentPrefix = $this->dbCore->tableNameResolver()->getLowercasePrefix();
        $activeBlogPrefixes = $this->activeBlogPrefixesLowercase;
        $foreign = $this->resolveForeignPrefixes($inventory['candidatePrefixes']);
        $this->renameTableNames($inventory['tableNames'], $currentPrefix, $activeBlogPrefixes,
            $foreign['prefixes'], $foreign['available']);

        // After renaming, check for orphaned tables under old prefixes.
        $this->coordinator->orphanAdoptionUpgrade()->adoptOrphanedTables();
    }

    /** @return array{tableNames:array<int,string>,candidatePrefixes:array<int,string>}|null */
    private function readTableInventory(string $dbName): ?array {
        // Both values are bound, not spliced: the executor rewrites `{wp_...}`
        // tokens before binding query_params. Binding the LIKE pattern keeps
        // its percent signs out of prepare() placeholder processing.
        $results = $this->dbCore->queryAndGetResults(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND LOWER(table_name) LIKE %s",
            array('query_params' => array($dbName, '%abj404%'))
        );
        $lastError = $results['last_error'] ?? null;
        if (($results['query_succeeded'] ?? null) !== true || !is_string($lastError) || $lastError !== ''
            || (array_key_exists('timed_out', $results) && $results['timed_out'] !== false)
            || !is_array($results['rows'] ?? null)) {
            return null;
        }
        $names = array();
        $prefixes = array();
        foreach ($results['rows'] as $row) {
            if (!is_array($row)) { continue; }
            $name = null;
            // Drivers vary the case of information_schema column names.
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'table_name' && is_scalar($value)) { $name = (string)$value; break; }
            }
            if ($name === null || $name === '') {
                $this->logger->warn("I didn't find a table name in the results of this row: " . print_r($row, true));
                continue;
            }
            $names[] = $name;
            $prefix = $this->prefixOfAbj404Table(strtolower($name));
            if ($prefix !== null) { $prefixes[] = $prefix; }
        }
        return array('tableNames' => $names, 'candidatePrefixes' => $prefixes);
    }

    /**
     * @param array<int,string> $candidatePrefixes
     * @return array{prefixes:array<int,string>,available:bool}
     */
    private function resolveForeignPrefixes(array $candidatePrefixes): array {
        if ($candidatePrefixes === array()) { return array('prefixes' => array(), 'available' => false); }
        if ($this->foreignWordPressOptionsPrefixResolver === null) {
            $this->logger->warn('Foreign-install tenant authority is unavailable; deferring non-current table renames.');
            return array('prefixes' => array(), 'available' => false);
        }
        try {
            $resolved = call_user_func($this->foreignWordPressOptionsPrefixResolver, array_values(array_unique($candidatePrefixes)));
            if (is_array($resolved) && count(array_filter($resolved, 'is_string')) === count($resolved)) {
                $prefixes = array();
                foreach ($resolved as $prefix) { if (is_string($prefix)) { $prefixes[] = $prefix; } }
                return array('prefixes' => $prefixes, 'available' => true);
            }
        } catch (Throwable $e) {
            $this->logger->warn('Foreign-install tenant authority could not be read; deferring non-current table renames.');
            return array('prefixes' => array(), 'available' => false);
        }
        $this->logger->warn('Foreign-install tenant authority returned malformed data; deferring non-current table renames.');
        return array('prefixes' => array(), 'available' => false);
    }

    /**
     * @param array<int,string> $tableNames
     * @param array<int,string> $activePrefixes
     * @param array<int,string> $foreignPrefixes
     */
    private function renameTableNames(array $tableNames, string $currentPrefix, array $activePrefixes,
            array $foreignPrefixes, bool $foreignAvailable): void {
        foreach ($tableNames as $tableName) {
            $lower = strtolower($tableName);
            if ($tableName === $lower) { continue; }
            $prefix = $this->prefixOfAbj404Table($lower);
            if ($prefix !== null && $prefix !== $currentPrefix
                && (in_array($prefix, $activePrefixes, true)
                    || !$foreignAvailable || in_array($prefix, $foreignPrefixes, true))) { continue; }
            $this->dbCore->queryAndGetResults("RENAME TABLE `{$tableName}` TO `{$lower}`",
                array('ignore_errors' => array('already exists')));
            $this->logger->infoMessage("Renamed table {$tableName} to {$lower}\n");
        }
    }

    /**
     * Extract the table prefix from a lowercase plugin table name: everything
     * before the first "abj404" segment (e.g. "wp_2_abj404_redirects" -> "wp_2_").
     * Returns null when the name has no abj404 segment.
     *
     * @param string $lowercaseTableName
     * @return string|null
     */
    private function prefixOfAbj404Table(string $lowercaseTableName): ?string {
        $pos = strpos($lowercaseTableName, 'abj404');
        if ($pos === false) {
            return null;
        }
        $prefix = substr($lowercaseTableName, 0, $pos);
        return is_string($prefix) ? $prefix : null;
    }
}
