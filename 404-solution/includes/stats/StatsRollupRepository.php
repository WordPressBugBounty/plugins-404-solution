<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL storage for digest dimensions and replay-safe timestamp/window buckets.
 * @phpstan-import-type State from ABJ_404_Solution_StatsRollupService
 */
class ABJ_404_Solution_StatsRollupRepository {
    private const DIMENSIONS = array('requested_url', 'user_ip', 'referrer');
    /** @var ABJ_404_Solution_DatabaseCore */
    private $db;

    public function __construct(ABJ_404_Solution_DatabaseCore $db) { $this->db = $db; }

    /** Check schema before any materialization; bootstrap owns table creation. */
    public function available(): bool {
        foreach (array('stats_dimensions', 'stats_buckets', 'logsv2', 'redirects') as $suffix) {
            $name = $this->db->doTableNameReplacements('{wp_abj404_' . $suffix . '}');
            if (!$this->db->tableNameResolver()->tableExists($name)) { return false; }
        }
        return true;
    }

    /**
     * Indexed edge probes, with no retained-history aggregate.
     * @param int|null $oldestUpperId Bound only the retention floor to the snapshot.
     * @return array<string,int>
     */
    public function sourceBounds(ABJ_404_Solution_LogsHitsRebuildBudget $budget, ?int $oldestUpperId = null): array {
        $bounds = array('min_id' => 0, 'max_id' => 0, 'oldest' => 0, 'oldest_count' => 0);
        $oldestFound = false;
        foreach (array('min_id' => 'id ASC', 'max_id' => 'id DESC', 'oldest' => 'timestamp ASC') as $key => $order) {
            $column = $key === 'oldest' ? 'timestamp' : 'id';
            $where = $key === 'oldest' && $oldestUpperId !== null ? ' WHERE id <= %d' : '';
            $params = $where === '' ? array() : array($oldestUpperId);
            $rows = $this->query("SELECT {$column} AS edge FROM {wp_abj404_logsv2}{$where} ORDER BY {$order} LIMIT 1",
                $budget, $params)['rows'];
            if ($key === 'oldest') { $oldestFound = $rows !== array(); }
            $bounds[$key] = $rows ? max(0, $this->integerField($rows[0], 'edge', PHP_INT_MIN)) : 0;
        }
        if ($oldestFound) {
            $where = ' WHERE timestamp = %d' . ($oldestUpperId !== null ? ' AND id <= %d' : '');
            $params = array($bounds['oldest']);
            if ($oldestUpperId !== null) { $params[] = $oldestUpperId; }
            // allow-unbounded-select: COUNT(*) returns one scalar oldest-timestamp count under the query budget.
            $rows = $this->query('SELECT COUNT(*) AS edge_count FROM {wp_abj404_logsv2}' . $where,
                $budget, $params)['rows'];
            $bounds['oldest_count'] = $rows ? $this->integerField($rows[0], 'edge_count') : 0;
        }
        return $bounds;
    }

    /**
     * One bounded SQL unit. Repeating any unit preserves counts.
     * @param State $state
     */
    public function appendUnit(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): void {
        $generation = $state['data_generation'];
        $cursor = (int)$state['cursor'];
        $end = (int)$state['window_end'];
        $step = (int)$state['step'];
        $range = 'id > %d AND id <= %d';
        // created_at is a Unix epoch (bigint), like every hot-path time column.
        $now = abj_clock()->now();
        if ($step < 3) {
            $column = self::DIMENSIONS[$step];
            // Identity is SHA-256 of exact stored bytes; collation-equivalent variants deliberately remain separate.
            $key = "UNHEX(SHA2({$column}, 256))";
            // Generation and dimension are fixed here; digest order matches
            // the remaining clustered key and avoids random leaf-page writes.
            $sql = "INSERT INTO {wp_abj404_stats_dimensions} (generation, dimension_id, value_key, latest_404, latest_redirect, created_at)
                SELECT %s, %d, {$key}, MAX(CASE WHEN dest_url = '404' THEN timestamp END),
                    MAX(CASE WHEN dest_url <> '404' THEN timestamp END), %d
                FROM {wp_abj404_logsv2} WHERE {$range} AND {$column} IS NOT NULL
                GROUP BY {$key} ORDER BY {$key}
                ON DUPLICATE KEY UPDATE
                    latest_404 = GREATEST(COALESCE(latest_404, VALUES(latest_404)), COALESCE(VALUES(latest_404), latest_404)),
                    latest_redirect = GREATEST(COALESCE(latest_redirect, VALUES(latest_redirect)), COALESCE(VALUES(latest_redirect), latest_redirect))";
            $params = array($generation, $step, $now, $cursor, $end);
        } elseif ($step === 3) {
            $sql = 'DELETE FROM {wp_abj404_stats_buckets} WHERE generation = %s AND window_start = %d';
            $params = array($generation, $cursor);
        } elseif ($step === 4) {
            $sql = "INSERT INTO {wp_abj404_stats_buckets}
                (generation, window_start, window_end, timestamp, hit_404, hit_redirect, source_count, created_at)
                SELECT %s, %d, %d, timestamp, COUNT(CASE WHEN dest_url = '404' THEN 1 END),
                    COUNT(CASE WHEN dest_url <> '404' THEN 1 END), COUNT(*), %d
                FROM {wp_abj404_logsv2} WHERE {$range} AND timestamp >= 0 GROUP BY timestamp
                ON DUPLICATE KEY UPDATE hit_404 = VALUES(hit_404), hit_redirect = VALUES(hit_redirect), source_count = VALUES(source_count)";
            $params = array($generation, $cursor, $end, $now, $cursor, $end);
        } else {
            // Negative timestamp is a window manifest, never a hit bucket.
            $sql = 'INSERT INTO {wp_abj404_stats_buckets} (generation, window_start, window_end, timestamp, created_at)
                VALUES (%s, %d, %d, -1, %d) ON DUPLICATE KEY UPDATE window_end = VALUES(window_end)';
            $params = array($generation, $cursor, $end, $now);
        }
        $this->query($sql, $budget, $params);
    }

    /**
     * Prune one dimension/class or timestamp bucket batch.
     * @param State $state
     */
    public function pruneUnit(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): bool {
        $step = (int)$state['step'];
        $params = array($state['data_generation']);
        if ($step < 6) {
            $column = $step % 2 === 0 ? 'latest_404' : 'latest_redirect';
            $sourceColumn = self::DIMENSIONS[(int)floor($step / 2)];
            $classPredicate = $step % 2 === 0 ? "dest_url = '404'" : "dest_url <> '404'";
            $hash = "UNHEX(SHA2({$sourceColumn}, 256))";
            $sql = "UPDATE {wp_abj404_stats_dimensions} AS d SET {$column} = NULL
                WHERE d.generation = %s AND d.dimension_id = %d
                AND (d.{$column} < %d OR (d.{$column} = %d AND NOT EXISTS (
                    SELECT 1 FROM (
                        SELECT DISTINCT {$hash} AS value_key
                        FROM {wp_abj404_logsv2}
                        WHERE id <= %d AND timestamp = %d AND {$sourceColumn} IS NOT NULL
                            AND {$classPredicate}
                    ) AS boundary_keys WHERE boundary_keys.value_key = d.value_key
                ))) LIMIT 5000";
            $params[] = (int)floor($step / 2);
            $params[] = $state['oldest'];
            $params[] = $state['oldest'];
            $params[] = $state['high_water'];
            $params[] = $state['oldest'];
        } elseif ($step === 6) {
            $sql = 'DELETE FROM {wp_abj404_stats_dimensions} WHERE generation = %s
                AND latest_404 IS NULL AND latest_redirect IS NULL LIMIT 5000';
        } else {
            $sql = 'DELETE FROM {wp_abj404_stats_buckets} WHERE generation = %s
                AND timestamp >= 0 AND timestamp < %d LIMIT 5000';
            $params[] = $state['oldest'];
        }
        $result = $this->query($sql, $budget, $params);
        return (int)($result['rows_affected'] ?? 0) < 5000;
    }

    /**
     * Detect cardinality changes using id-bounded source reads.
     * @param State $state
     * @return array{next:int,window_start:int,window_end:int,changed:bool,boundary_changed:bool,above_changed:bool}|null
     */
    public function auditWindow(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): ?array {
        $rows = $this->query('SELECT window_start, window_end FROM {wp_abj404_stats_buckets}
            WHERE generation = %s AND timestamp = -1 AND window_start >= %d ORDER BY window_start LIMIT 1',
            $budget, array($state['data_generation'], $state['audit_after']))['rows'];
        if (!$rows) { return null; }
        $window = $rows[0];
        // allow-unbounded-select: one aggregate over one bucket window's id range; returns one row.
        $actual = $this->query('SELECT
                COALESCE(SUM(CASE WHEN timestamp > %d THEN 1 ELSE 0 END), 0) AS above_n,
                COALESCE(SUM(CASE WHEN timestamp = %d THEN 1 ELSE 0 END), 0) AS boundary_n
            FROM {wp_abj404_logsv2} WHERE id > %d AND id <= %d AND timestamp >= %d', $budget,
            array($state['oldest'], $state['oldest'], $window['window_start'], $window['window_end'], $state['oldest']))['rows'];
        $expected = $this->query('SELECT
                COALESCE(SUM(CASE WHEN timestamp > %d THEN source_count ELSE 0 END), 0) AS above_n,
                COALESCE(SUM(CASE WHEN timestamp = %d THEN source_count ELSE 0 END), 0) AS boundary_n
            FROM {wp_abj404_stats_buckets} WHERE generation = %s AND window_start = %d AND timestamp >= %d', $budget,
            array($state['oldest'], $state['oldest'], $state['data_generation'], $window['window_start'], max(0, $state['oldest'])))['rows'];
        $aboveChanged = $this->integerField($actual[0], 'above_n') !== $this->integerField($expected[0], 'above_n');
        $boundaryChanged = $this->integerField($actual[0], 'boundary_n') !== $this->integerField($expected[0], 'boundary_n');
        return array('next' => $this->integerField($window, 'window_end'),
            'window_start' => $this->integerField($window, 'window_start'),
            'window_end' => $this->integerField($window, 'window_end'),
            'changed' => $aboveChanged || $boundaryChanged, 'boundary_changed' => $boundaryChanged,
            'above_changed' => $aboveChanged);
    }

    /**
     * Replay one changed floor window while preserving its negative manifest row.
     * @param State $state
     * @param array{window_start:int,window_end:int} $window
     */
    public function repairBoundaryWindow(array $state, array $window, ABJ_404_Solution_LogsHitsRebuildBudget $budget): void {
        $start = $this->integerField($window, 'window_start');
        $end = $this->integerField($window, 'window_end');
        $this->query('DELETE FROM {wp_abj404_stats_buckets}
            WHERE generation = %s AND window_start = %d AND timestamp >= 0', $budget,
            array($state['data_generation'], $start));
        $this->query('INSERT INTO {wp_abj404_stats_buckets}
            (generation, window_start, window_end, timestamp, hit_404, hit_redirect, source_count, created_at)
            SELECT %s, %d, %d, timestamp, COUNT(CASE WHEN dest_url = \'404\' THEN 1 END),
                COUNT(CASE WHEN dest_url <> \'404\' THEN 1 END), COUNT(*), %d
            FROM {wp_abj404_logsv2} WHERE id > %d AND id <= %d AND timestamp >= 0 GROUP BY timestamp
            ON DUPLICATE KEY UPDATE hit_404 = VALUES(hit_404), hit_redirect = VALUES(hit_redirect), source_count = VALUES(source_count)',
            $budget, array($state['data_generation'], $start, $end, abj_clock()->now(), $start, $end));
    }

    /**
     * One indexed count; indices cover the threshold range.
     * @param State $state
     * @return array<string,int>
     */
    public function countUnit(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $step = (int)$state['step'];
        $periods = array_keys($state['thresholds']);
        $period = $periods[(int)floor($step / 7)];
        $metric = $step % 7;
        $params = array($state['data_generation']);
        if ($metric === 6) {
            $sql = 'SELECT COALESCE(SUM(hit_404), 0) AS disp404, COALESCE(SUM(hit_redirect), 0) AS redirected
                FROM {wp_abj404_stats_buckets} WHERE generation = %s AND timestamp >= %d';
        } else {
            $keys = array('distinct404', 'distinctredirected', 'visitors404', 'distinctvisitors', 'refer404', 'distinctrefer');
            $column = $metric % 2 === 0 ? 'latest_404' : 'latest_redirect';
            $sql = "SELECT COUNT(*) AS {$keys[$metric]} FROM {wp_abj404_stats_dimensions}
                WHERE generation = %s AND dimension_id = %d AND {$column} >= %d";
            $params[] = (int)floor($metric / 2);
        }
        $params[] = $state['thresholds'][$period];
        $row = $this->query($sql, $budget, $params)['rows'][0];
        $counts = array();
        foreach ($row as $key => $value) { $counts[$key] = $this->integerField($row, $key); }
        return $counts;
    }

    /** @param array<string,mixed> $row */
    private function integerField(array $row, string $key, int $minimum = 0): int {
        $value = ABJ_404_Solution_ExactInteger::read($row[$key] ?? null, $minimum);
        if ($value === null) { throw new UnexpectedValueException('Statistics SQL returned an invalid integer: ' . $key, 502); }
        return $value;
    }

    /** Remove obsolete shadow/previous generations in bounded batches. */
    public function cleanupUnit(string $generation, int $step, ABJ_404_Solution_LogsHitsRebuildBudget $budget): bool {
        $table = $step === 0 ? 'stats_dimensions' : 'stats_buckets';
        $result = $this->query('DELETE FROM {wp_abj404_' . $table . '} WHERE generation <> %s LIMIT 5000', $budget, array($generation));
        return (int)($result['rows_affected'] ?? 0) < 5000;
    }

    /**
     *
     * @param array<int,mixed> $params
     * @return array{rows:list<array<string,mixed>>,rows_affected:int}
     */
    private function query(string $sql, ABJ_404_Solution_LogsHitsRebuildBudget $budget, array $params = array()): array {
        $result = $this->db->queryAndGetResults($sql, array('query_params' => $params,
            'timeout' => $budget->timeoutSeconds(), 'log_too_slow' => false));
        if (!empty($result['timed_out']) || !empty($result['last_error'])) {
            throw new RuntimeException('Statistics rollup SQL failed: ' . (is_string($result['last_error'] ?? null) ? $result['last_error'] : 'timeout'), 503);
        }
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            throw new RuntimeException('Statistics rollup SQL failed without an error message.', 503);
        }
        if (!isset($result['rows']) || !is_array($result['rows'])) {
            throw new UnexpectedValueException('Statistics rollup SQL returned malformed rows.', 502);
        }
        $rows = array();
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) { throw new UnexpectedValueException('Statistics SQL row is not an associative record.', 502); }
            $record = array();
            foreach ($row as $key => $value) {
                if (!is_string($key)) { throw new UnexpectedValueException('Statistics SQL column name is not a string.', 502); }
                $record[$key] = $value;
            }
            $rows[] = $record;
        }
        return array('rows' => $rows, 'rows_affected' => ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? 0, 0, 0));
    }
}
