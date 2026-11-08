<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database metadata for the published logs-hits table.
 *
 * The coordinator reads freshness and watermarks; the SQL rebuilder reads the
 * publication marker. Managed hosts may refuse information_schema, so SHOW
 * remains an independent read path. Generation checks require an answered
 * status query rather than treating a storage failure as an absent table.
 */
class ABJ_404_Solution_LogsHitsTableMetadata {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * The rollup table's own creation instant, as a true UTC epoch, read from
     * information_schema.
     *
     * The server is asked for UNIX_TIMESTAMP(create_time) rather than
     * create_time itself, because create_time is DISPLAYED in the MySQL session
     * timezone: one instant reads as "2026-08-13 21:52:51" at +00:00 and
     * "2026-08-14 06:52:51" at +09:00. Only the epoch form is timezone-invariant,
     * and it is the form the caller needs, since it compares this against
     * abj_clock()->now(), a true UTC epoch.
     *
     * @return int|null Epoch seconds, or null when the server has no usable
     *                  answer (no such table, or the read was refused and the
     *                  fallback could not date it either).
     */
    public function freshnessEpoch(): ?int {
        $query = "SELECT UNIX_TIMESTAMP(create_time) AS create_time_epoch FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->schemaQuery($query);
        if ($results['rows'] == null || empty($results['rows'])) {
            // Rows AND an error means the read was refused (restricted grants
            // on managed hosts), which is the only case worth a second attempt.
            return empty($results['last_error']) ? null : $this->readStatusFallbackFreshnessEpoch();
        }
        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? array_change_key_case($hitsRows[0]) : array();
        $createTimeEpoch = $row['create_time_epoch'] ?? null;
        if (!is_numeric($createTimeEpoch)) { return null; }
        $schemaTimestamp = (int)$createTimeEpoch;
        return $schemaTimestamp > 0 ? $schemaTimestamp : null;
    }

    /**
     * The same instant read through SHOW TABLE STATUS, for hosts that refuse
     * information_schema. Prefers Update_time, which InnoDB leaves NULL until
     * the table is written, and falls back to Create_time.
     *
     * @return int|null Epoch seconds, or null when the row carries no usable date.
     */
    private function readStatusFallbackFreshnessEpoch(): ?int {
        $statusRow = $this->tryStatusRow();
        $dateValue = $statusRow['update_time'] ?? ($statusRow['create_time'] ?? '');
        if (!is_string($dateValue) || $dateValue === '') { return null; }
        return $this->mysqlDatetimeToEpoch($dateValue);
    }

    /**
     * Convert a datetime string the database server just rendered back into a
     * true UTC epoch, using that same server's session timezone.
     *
     * SHOW TABLE STATUS cannot be wrapped in an expression the way
     * information_schema's create_time can, so its Create_time / Update_time
     * arrive here as a string already rendered in the MySQL session timezone
     * (normally SYSTEM, i.e. the DB host's OS timezone). Handing that string to
     * PHP's strtotime() would re-read it in PHP's default timezone, which
     * WordPress pins to UTC -- two systems rendering and parsing the same
     * instant under different implicit timezones, which puts the rollup age out
     * by the server's whole UTC offset. Asking the server to convert its own
     * rendering keeps interpretation on the side that produced it, so the two
     * cannot disagree.
     *
     * @param string $mysqlDatetime A datetime as rendered by the server.
     * @return int|null Epoch seconds, or null when the server cannot convert it
     *                  (unparseable, zero date, or a failed conversion query).
     */
    private function mysqlDatetimeToEpoch(string $mysqlDatetime): ?int {
        $results = $this->dbCore->queryAndGetResults(
            "SELECT UNIX_TIMESTAMP(%s) AS `epoch`",
            array('query_params' => array($mysqlDatetime), 'log_errors' => false)
        );
        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($rows[0] ?? null) ? array_change_key_case($rows[0]) : array();
        $epoch = $row['epoch'] ?? null;
        if (!is_numeric($epoch)) { return null; }
        $epoch = (int)$epoch;
        return $epoch > 0 ? $epoch : null;
    }


    /**
     * Read an exact table's status, propagating storage failures to publishers.
     * @param array<string,mixed> $options Optional bounded query execution policy.
     * @return array<string,mixed>
     */
    public function statusRow(array $options = array()): array {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $result = $this->dbCore->queryAndGetResults('SHOW TABLE STATUS WHERE Name = %s',
            array_merge(array('log_errors' => false), $options, array('query_params' => array($table))));
        $error = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($error !== '' || !empty($result['timed_out'])) {
            throw new RuntimeException($error !== '' ? $error : 'Logs-hits table status query timed out.');
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) { throw new UnexpectedValueException('Malformed logs-hits table status result.'); }
        if (empty($rows)) { return array(); }
        if (!is_array($rows[0] ?? null)) { throw new UnexpectedValueException('Malformed logs-hits table status row.'); }
        // MySQL and MariaDB report metadata with different column-name case.
        $row = array();
        foreach ($rows[0] as $key => $value) { $row[strtolower((string)$key)] = $value; }
        return $row;
    }

    /** @return array<string,mixed> */
    private function tryStatusRow(): array {
        try { return $this->statusRow(); }
        catch (Throwable $error) {
            $this->logReadFailure('SHOW TABLE STATUS', $error);
            return array();
        }
    }

    /** @return bool */
    public function exists(): bool {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $result = $this->schemaQuery($query);
        if (!empty($result['rows'])) { return true; }
        if (empty($result['last_error'])) { return false; }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $fallback = $this->dbCore->queryAndGetResults('SHOW TABLES LIKE %s',
            array('query_params' => array($table), 'log_errors' => false));
        $rows = is_array($fallback['rows'] ?? null) ? $fallback['rows'] : array();
        $row = $rows[0] ?? null;
        return is_array($row) && reset($row) === $table;
    }

    /** The second comment component remains the backward-compatible watermark. */
    public function storedMaxLogId(): int {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $result = $this->schemaQuery($query);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            $status = empty($result['last_error']) ? array() : $this->tryStatusRow();
            $comment = $status['comment'] ?? '';
        } else {
            $row = is_array($rows[0] ?? null) ? array_change_key_case($rows[0]) : array();
            $comment = $row['table_comment'] ?? '';
        }
        $parts = explode('|', is_string($comment) ? $comment : '');
        return count($parts) >= 2 ? (int)$parts[1] : 0;
    }

    /**
     * Preserve driver exceptions as error data so the independent SHOW path can run.
     * @return array<string,mixed>
     */
    private function schemaQuery(string $query): array {
        try { return $this->dbCore->queryAndGetResults($this->dbCore->doTableNameReplacements($query)); }
        catch (Throwable $error) {
            $this->logReadFailure('information_schema', $error);
            return array('rows' => array(), 'last_error' => get_class($error) . ' ('
                . $error->getCode() . '): ' . $error->getMessage());
        }
    }

    private function logReadFailure(string $source, Throwable $error): void {
        $this->logger->warn('Logs-hits metadata ' . $source . ' failed: ' . get_class($error)
            . ' (' . $error->getCode() . '): ' . $error->getMessage());
    }
}
