<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Live readiness and bounded legacy-row population for Logs URL ordering.
 *
 * Like Redirects, Logs orders by a narrow URL prefix, with ID breaking ties in
 * the selected direction. The full URL remains the display/source value.
 * The full key index locates NULL keys directly, so restored/imported NULLs
 * close readiness without a stale completion latch or persisted cursor.
 * Scheduling, locks, and schema repair belong to database maintenance.
 */
final class ABJ_404_Solution_LogsUrlSortRepository {
    public const INDEX_NAME = 'idx_url_sort_id';
    private const CHUNK_SIZE = 250;
    private ABJ_404_Solution_DatabaseCore $dbCore;
    private string $table;
    private string $tableSql;
    private ?bool $schemaAvailable = null;

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
        $this->table = $dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $this->tableSql = ABJ_404_Solution_TableIndexDefinitions::quoteIdentifier($this->table) ?? '';
    }

    /** A fresh indexed probe, never a retained claim about previously drained rows. */
    public function isReady(): bool {
        return $this->schemaAvailable() && $this->missingIds(1) === array();
    }

    /** Both column width and the complete ordered index definition must agree. */
    private function schemaAvailable(): bool {
        if ($this->schemaAvailable !== null) { return $this->schemaAvailable; }
        $this->schemaAvailable = false;
        if ($this->tableSql === '') { return false; }
        $result = $this->dbCore->queryAndGetResults('SHOW COLUMNS FROM ' . $this->tableSql,
            array('timeout' => 1, 'log_errors' => false));
        if (!$this->healthy($result) || !is_array($result['rows'] ?? null)) { return false; }
        $columnPresent = false;
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) { return false; }
            $fields = array_change_key_case($row, CASE_LOWER);
            if (!is_string($fields['field'] ?? null) || !is_string($fields['type'] ?? null)) {
                return false;
            }
            if (strtolower($fields['field']) === 'url_sort_key') {
                $columnPresent = strtolower($fields['type']) === 'varchar(191)';
            }
        }
        if (!$columnPresent) { return false; }
        $indexes = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($this->table);
        $index = $indexes[self::INDEX_NAME] ?? null;
        if (!is_array($index) || !ABJ_404_Solution_TableIndexDefinitions::isDescribable($index)) {
            return false;
        }
        $this->schemaAvailable = $index['columns'] === array(
            array('column' => 'url_sort_key', 'prefix' => null),
            array('column' => 'id', 'prefix' => null)
        );
        return $this->schemaAvailable;
    }

    /**
     * Populate only derived keys, committing each fixed-size chunk before the
     * next one. A refused/unknown result stops the pass without claiming ready.
     *
     * @return array{updated:int,complete:bool,failed:bool}
     */
    public function backfill(float $timeBudgetSeconds = 5.0): array {
        $progress = array('updated' => 0, 'complete' => false, 'failed' => false);
        if (!is_finite($timeBudgetSeconds) || $timeBudgetSeconds <= 0.0 || !$this->schemaAvailable()) {
            return $progress;
        }
        $deadline = abj_clock()->nowFloat() + min(15.0, $timeBudgetSeconds);
        while (abj_clock()->nowFloat() < $deadline) {
            $ids = $this->missingIds(self::CHUNK_SIZE);
            if ($ids === null) { $progress['failed'] = true; return $progress; }
            if ($ids === array()) { $progress['complete'] = true; return $progress; }
            $result = $this->dbCore->queryAndGetResults(
                'UPDATE ' . $this->tableSql . ' SET url_sort_key = LEFT(requested_url, 191)'
                . ' WHERE url_sort_key IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
                array('query_params' => $ids, 'timeout' => 1));
            $updated = ABJ_404_Solution_ExactInteger::read($result['rows_affected'] ?? null, 0);
            if (!$this->healthy($result) || $updated === null || $updated > count($ids)) {
                $progress['failed'] = true;
                return $progress;
            }
            $progress['updated'] += $updated;
            if ($updated === 0) {
                // A concurrent writer may have completed these IDs; recheck once
                // rather than spinning on a successful-but-ineffective update.
                $progress['complete'] = $this->missingIds(1) === array();
                return $progress;
            }
        }
        $progress['complete'] = $this->missingIds(1) === array();
        return $progress;
    }

    /** @return array<int,int>|null An unreadable chunk is never an empty backlog. */
    private function missingIds(int $limit): ?array {
        $result = $this->dbCore->queryAndGetResults(
            'SELECT id FROM ' . $this->tableSql . ' FORCE INDEX (' . self::INDEX_NAME . ')'
            . ' WHERE url_sort_key IS NULL ORDER BY url_sort_key ASC, id ASC LIMIT ' . $limit,
            array('timeout' => 1));
        if (!$this->healthy($result) || !is_array($result['rows'] ?? null)) { return null; }
        $ids = array();
        $previous = 0;
        foreach ($result['rows'] as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
            if ($id === null || $id <= $previous) { return null; }
            $ids[] = $id;
            $previous = $id;
        }
        return count($ids) <= $limit ? $ids : null;
    }

    /** @param array<string,mixed> $result */
    private function healthy(array $result): bool {
        return array_key_exists('last_error', $result) && $result['last_error'] === ''
            && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
    }
}
