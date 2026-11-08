<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Read-only probes of THIS PLUGIN's own storage shape for the feedback
 * payload's `environment_extras` field: how large each plugin table is, how
 * healthy its indexes are, and what collation its JOIN-hot URL columns carry.
 *
 * One subject: the plugin's schema as the server currently reports it, read
 * through `information_schema` and `SHOW INDEX`. Reads about the SERVER's own
 * configuration live in ABJ_404_Solution_MysqlServerStateProbe; reads about
 * the rollup's freshness live in ABJ_404_Solution_RollupFreshnessProbe.
 *
 * Every probe here iterates a candidate table list and isolates each table in
 * its own try/catch, so a missing table (rebuild race, repair pending, an
 * install that never got that table) degrades to a missing map entry rather
 * than blanking the whole probe. A probe throws only when EVERY attempt
 * failed, which is the "we learned nothing" case the caller's recordProbe()
 * wrapper should mark as `<probe>_error`.
 *
 * The DAO-bypass markers here are specifically scoped to read-only metadata
 * probes: these statements read table/column/index METADATA, never plugin row
 * data, so DatabaseCore's repair-and-retry recovery does not apply (see
 * docs/adr/dataaccess-refactor.md).
 */
class ABJ_404_Solution_PluginSchemaMetadataProbe {

    /**
     * The c584 definitional verdict over installed plugin tables, counts only.
     * One metadata query covers the composite-bearing permanent DDL targets;
     * absent optional tables cause no query errors and no invented drift.
     * Unreadable metadata throws so recordProbe reports null with a reason.
     *
     * @return int
     */
    public function probeCompositeIndexDriftCount(): int {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
            throw new \RuntimeException('wpdb unavailable for index definition probe');
        }
        $targets = $this->compositeIndexTargets();
        if ($targets === array()) {
            throw new \RuntimeException('invalid shape: no composite index DDL targets');
        }
        $rows = $this->readCompositeIndexMetadata(array_keys($targets));
        $byTable = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('invalid shape: index metadata row');
            }
            $fields = ABJ_404_Solution_ShowIndexRowReader::normalizedFields($row);
            $table = isset($fields['table_name']) && is_string($fields['table_name']) ? $fields['table_name'] : '';
            if (isset($targets[$table])) {
                $byTable[$table][] = $row;
            } elseif ($table === '') {
                throw new \RuntimeException('invalid shape: index metadata table name');
            }
        }
        $count = 0;
        foreach ($byTable as $table => $tableRows) {
            $definitions = ABJ_404_Solution_TableIndexDefinitions::fromShowIndexRows($tableRows);
            $narrowed = $definitions === null ? null
                : ABJ_404_Solution_IndexDefinitionComparator::countNarrowedCompositeIndexes($definitions, $targets[$table]);
            if ($narrowed === null) {
                throw new \RuntimeException('invalid shape: incomplete index definitions');
            }
            $count += $narrowed;
        }
        return $count;
    }

    /**
     * Read one bounded index-metadata result after the public probe has
     * established the wpdb capabilities. Suppression is always restored,
     * including failed preparation and permission/driver failures.
     *
     * @param array<int, string> $tables Plugin-owned tables from the DDL catalog.
     * @return array<int, mixed>
     */
    private function readCompositeIndexMetadata(array $tables): array {
        global $wpdb;
        // Catalog filters may have run since the public capability check.
        // Recheck the connection before issuing or restoring session state.
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for index metadata');
        }
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $suppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            // DAO-bypass-approved: bound plugin table names, read-only index metadata, no row data or repairs.
            $query = $wpdb->prepare('SELECT TABLE_NAME, INDEX_NAME AS Key_name, NON_UNIQUE AS Non_unique,
                SEQ_IN_INDEX AS Seq_in_index, COLUMN_NAME AS Column_name, SUB_PART AS Sub_part
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (' . $placeholders . ')', $tables);
            if (!is_string($query) || $query === '') {
                throw new \RuntimeException('invalid shape: index metadata query could not be prepared');
            }
            // DAO-bypass-approved: information_schema metadata only; never reads or writes plugin records.
            $rows = ABJ_404_Solution_DatabaseDriverCall::getResults($wpdb, $query, ARRAY_A);
            if (!empty($wpdb->last_error) || !is_array($rows)) {
                throw new \RuntimeException('index definition SQL probe failed: ' . (string)($wpdb->last_error ?? 'non-array'));
            }
        } finally {
            if (method_exists($wpdb, 'suppress_errors')) {
                $wpdb->suppress_errors($suppress);
            }
        }
        return array_values($rows);
    }

    /**
     * Reuse the upgrade catalog and parser, rather than another list of index
     * names that could drift from the definitions used by the repair path.
     * Only plugin-owned permanent tables with composite indexes are queried.
     *
     * @return array<string, array<string, array{name: string, columns: string, unique: bool}>>
     */
    private function compositeIndexTargets(): array {
        global $wpdb;
        $upgrades = function_exists('abj_service_optional') ? abj_service_optional('database_upgrades') : null;
        if (!is_object($upgrades) || !method_exists($upgrades, 'components')) {
            throw new \RuntimeException('service unavailable: database_upgrades');
        }
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : 'wp_';
        $targets = array();
        foreach ($upgrades->components()->bootstrapUpgrade()->discoverPermanentDDLFiles() as $entry) {
            if (preg_match('/^abj404_[A-Za-z0-9_]+$/D', $entry['bareTableName']) !== 1) {
                continue;
            }
            $specs = ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql($entry['ddlContent']);
            foreach ($specs as $name => $spec) {
                if (count(ABJ_404_Solution_CreateTableIndexParser::ddlColumnList($spec['columns'])) > 1) {
                    $targets[$prefix . $entry['bareTableName']][$name] = $spec;
                }
            }
        }
        return $targets;
    }

    /**
     * Text columns whose collation probeDbCollation() reports, keyed by table
     * name without the WordPress prefix. A column named here that the table's
     * DDL does not declare makes information_schema answer with no row, which
     * the probe reads as "nothing to report"; PluginSchemaMetadataProbeTargetsTest
     * keeps every entry equal to a column the shipped DDL declares.
     */
    public const COLLATION_PROBE_TARGETS = array(
        'abj404_redirects' => array('url', 'canonical_url'),
        'abj404_logs_hits' => array('requested_url'),
        'abj404_logsv2'    => array('requested_url', 'canonical_url'),
    );

    /**
     * Size of plugin-owned tables beyond logsv2 (which has its own typed
     * column). The redirects-tab perf bug is bounded by the
     * redirects/logs_hits volume, not logsv2, so shipping both lets the
     * report rank reports by the right axis.
     *
     * Per-table shape: {data_length: int, index_length: int, data_free: int,
     * bytes: int}. `data_free` is the fragmentation indicator (bytes
     * allocated to the file but not in use); a fragmentation ratio of
     * data_free / (data_length + index_length) over ~0.3 explains the
     * "tables are 200 MB but only 50 MB of data" long-tail slowness.
     * `bytes` is the legacy combined data+index size kept for backward
     * compatibility with consumers that pre-dated the data_free split.
     *
     * @return array<string, array<string, int>>
     */
    public function collectPluginTableSizes(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_row')) {
            throw new \RuntimeException('wpdb unavailable for plugin_tables_bytes probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $candidates = array(
            'redirects'        => $prefix . 'abj404_redirects',
            'logs_hits'        => $prefix . 'abj404_logs_hits',
            'logs_hits_preagg' => $prefix . 'abj404_logs_hits_preagg',
            'permalink_cache'  => $prefix . 'abj404_permalink_cache',
            'spelling_cache'   => $prefix . 'abj404_spelling_cache',
            'lookup'           => $prefix . 'abj404_lookup',
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $out = array();
        $errors = 0;
        $attempted = 0;
        foreach ($candidates as $key => $table) {
            $attempted++;
            try {
                if (!method_exists($wpdb, 'prepare')) { continue; }
                // DAO-bypass-approved: information_schema metadata probe placeholder bind; no plugin-table writes.
                $prepared = $wpdb->prepare(
                    'SELECT data_length, index_length, data_free '
                  . 'FROM information_schema.TABLES '
                  . 'WHERE table_schema = DATABASE() AND table_name = %s',
                    $table
                );
                if ($prepared === null) { continue; }
                // DAO-bypass-approved: information_schema probe; no plugin tables touched, no error class repair would apply.
                $row = ABJ_404_Solution_DatabaseDriverCall::getRow($wpdb, $prepared, ARRAY_A);
                if (!is_array($row)) { continue; }
                $dl = 0; $il = 0; $df = 0;
                foreach ($row as $col => $val) {
                    if (!is_scalar($val) || !is_numeric($val)) { continue; }
                    $clow = strtolower((string)$col);
                    if ($clow === 'data_length')  { $dl = (int)$val; }
                    if ($clow === 'index_length') { $il = (int)$val; }
                    if ($clow === 'data_free')    { $df = (int)$val; }
                }
                $out[$key] = array(
                    'data_length'  => $dl,
                    'index_length' => $il,
                    'data_free'    => $df,
                    'bytes'        => $dl + $il,
                );
            } catch (\Throwable $e) {
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'collectPluginTableSizes probe failed for ' . $table . ': ' . $e->getMessage());
                $errors++;
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out)) {
            throw new \RuntimeException('plugin_tables_bytes: all tables failed SQL probe');
        }
        return $out;
    }

    /**
     * Per-index cardinality for the canonical indexes on the JOIN-hot
     * plugin tables. Output shape:
     *   { redirects: {idx_url_disabled_status: int, idx_canonical_url: int, ...},
     *     logs_hits: {requested_url: int, ...},
     *     logs_hits_preagg: {...} }
     *
     * Each table is probed in its own SHOW INDEX statement, isolated in
     * try/catch so a missing table (rebuild race, repair pending) does
     * not blank the whole map. Only the Cardinality value is captured;
     * the rest of the SHOW INDEX columns are not emitted.
     *
     * @return array<string, array<string, int>>
     */
    public function probeIndexCardinality(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('wpdb unavailable for index_cardinality probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $candidates = array(
            'redirects'        => $prefix . 'abj404_redirects',
            'logs_hits'        => $prefix . 'abj404_logs_hits',
            'logs_hits_preagg' => $prefix . 'abj404_logs_hits_preagg',
            'logsv2'           => $prefix . 'abj404_logsv2',
        );
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $out = array();
        $errors = 0;
        $attempted = 0;
        foreach ($candidates as $key => $table) {
            $attempted++;
            try {
                $quotedTable = str_replace('`', '``', $table);
                $prepared = 'SHOW INDEX FROM `' . $quotedTable . '`';
                // DAO-bypass-approved: SHOW INDEX is read-only metadata; no plugin-table writes possible.
                $rows = ABJ_404_Solution_DatabaseDriverCall::getResults($wpdb, $prepared, ARRAY_A);
                if (!is_array($rows)) {
                    continue;
                }
                $byIndex = $this->parseIndexCardinalityRows($rows);
                if (!empty($byIndex)) {
                    $out[$key] = $byIndex;
                }
            } catch (\Throwable $e) {
                // allow-silent-catch: per-table probe is best-effort; a missing-table or permissions error must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeIndexCardinality failed for ' . $table . ': ' . $e->getMessage());
                $errors++;
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out)) {
            throw new \RuntimeException('index_cardinality: all tables failed SHOW INDEX probe');
        }
        return $out;
    }

    /**
     * Reduce SHOW INDEX rows to the largest known cardinality per index.
     *
     * @param array<mixed, mixed> $rows
     * @return array<string, int>
     */
    private function parseIndexCardinalityRows(array $rows): array {
        $byIndex = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $idxName = '';
            $card = null;
            foreach ($row as $col => $val) {
                $clow = strtolower((string)$col);
                if ($clow === 'key_name' && is_scalar($val)) { $idxName = (string)$val; }
                // read(), not readOr(): NULL means the engine has no estimate
                // yet, and 0 is a real answer this map uses to spot damaged indexes.
                if ($clow === 'cardinality') { $card = ABJ_404_Solution_ExactInteger::read($val, 0); }
            }
            if ($idxName === '' || $card === null) { continue; }
            if (!isset($byIndex[$idxName]) || $card > $byIndex[$idxName]) {
                $byIndex[$idxName] = $card;
            }
        }
        return $byIndex;
    }

    /**
     * DB-level + per-column collation for the JOIN-hot URL columns on
     * `abj404_redirects` and `abj404_logs_hits`. Collation drift between
     * the two columns disables the index seek silently: MySQL falls back
     * to a full-scan ON the un-joined column. Capturing both lets the
     * server side classify "fast on staging, slow on prod" reports by
     * the cause that is invisible from the SHOW CREATE TABLE output.
     *
     * Shape:
     *   { db_charset: string, db_collate: string,
     *     columns: { '{prefix}abj404_redirects.url': string,
     *                '{prefix}abj404_redirects.canonical_url': string,
     *                '{prefix}abj404_logs_hits.requested_url': string,
     *                '{prefix}abj404_logsv2.requested_url': string,
     *                '{prefix}abj404_logsv2.canonical_url': string } }
     *
     * @return array<string, mixed>
     */
    public function probeDbCollation(): array {
        $out = array(
            'db_charset' => defined('DB_CHARSET') && is_string(DB_CHARSET) ? DB_CHARSET : '',
            'db_collate' => defined('DB_COLLATE') && is_string(DB_COLLATE) ? DB_COLLATE : '',
            'columns'    => array(),
        );
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_var')) {
            throw new \RuntimeException('wpdb unavailable for db_collation probe');
        }
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        $targets = array();
        foreach (self::COLLATION_PROBE_TARGETS as $tableSuffix => $columns) {
            $targets[$prefix . $tableSuffix] = $columns;
        }
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        $errors = 0;
        $attempted = 0;
        foreach ($targets as $table => $cols) {
            foreach ($cols as $col) {
                $attempted++;
                try {
                    $collation = $this->readColumnCollation($table, $col);
                    if ($collation !== '') {
                        $out['columns'][$table . '.' . $col] = $collation;
                    }
                } catch (\Throwable $e) {
                    // allow-silent-catch: per-column probe is best-effort; missing-table / permissions errors must not abort the whole map. Aggregated failure is rethrown after the loop when ALL attempts failed (see $errors check below) so the recordProbe wrapper can write the marker key.
                    ABJ_404_Solution_FeedbackTransportLog::log('warn', 'probeDbCollation probe failed for ' . $table . '.' . $col . ': ' . $e->getMessage());
                    $errors++;
                }
            }
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        if ($errors === $attempted && empty($out['columns'])) {
            throw new \RuntimeException('db_collation: all per-column SQL probes failed');
        }
        return $out;
    }

    /**
     * One information_schema lookup: the collation of a single column.
     *
     * @return string The collation name, or '' when the column has no row or wpdb cannot prepare.
     */
    private function readColumnCollation(string $table, string $column): string {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) { return ''; }
        // DAO-bypass-approved: information_schema collation probe placeholder bind; no plugin-table writes.
        $prepared = $wpdb->prepare(
            'SELECT COLLATION_NAME '
          . 'FROM information_schema.COLUMNS '
          . 'WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
            $table,
            $column
        );
        if ($prepared === null) { return ''; }
        // DAO-bypass-approved: information_schema metadata probe; no plugin-table writes.
        $value = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, $prepared);
        return is_scalar($value) ? (string)$value : '';
    }
}
