<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Refreshes the optimizer's statistics for the logs table when the server has
 * none for it.
 *
 * Reads the post-DDL row estimate from information_schema and runs
 * ANALYZE TABLE only when that estimate is zero. A zero estimate on a table
 * that holds rows would make the optimizer treat it as empty. The outcome is
 * `complete: false` whenever the estimate could not be read or the ANALYZE did
 * not report a positive native status, so the existing schema-insurance
 * continuation retries the refresh instead of the maintenance pass moving on.
 *
 * Every failure is described from the server's own answer (timeout, native
 * error, status rows) and logged as a warning: a refused or slow ANALYZE is a
 * hosting condition the plugin degrades past, never an error report.
 */
final class ABJ_404_Solution_LogsTableMetadataRefresh {

    private const QUERY_TIMEOUT_SECONDS = 5;

    /** @var callable(string, array<string,mixed>): array<string,mixed> */
    private $runQuery;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param callable(string, array<string,mixed>): array<string,mixed> $runQuery The database
     *        boundary, normally ABJ_404_Solution_DatabaseCore::queryAndGetResults.
     * @param ABJ_404_Solution_Logging $logger Receives the warnings.
     */
    public function __construct(callable $runQuery, ABJ_404_Solution_Logging $logger) {
        $this->runQuery = $runQuery;
        $this->logger = $logger;
    }

    /**
     * Refresh the logs table metadata if its row estimate is unknown.
     *
     * @return array{complete: bool}
     */
    public function run(): array {
        $estimateResult = $this->query(
            'SELECT table_rows AS estimated_rows FROM information_schema.tables '
            . "WHERE table_schema=DATABASE() AND table_name='{wp_abj404_logsv2}'"
        );
        $estimate = $this->rowEstimate($estimateResult);
        if ($estimate === null) {
            $this->logger->warn('logsv2 metadata estimate could not be read: '
                . $this->estimateFailureDetail($estimateResult) . '; holding schema insurance.');
            return array('complete' => false);
        }
        if ($estimate > 0) {
            return array('complete' => true);
        }

        $failure = $this->analyzeFailure();
        if ($failure === null) {
            return array('complete' => true);
        }
        $this->logger->warn('logsv2 ANALYZE TABLE metadata refresh failed: ' . $failure);
        return array('complete' => false);
    }

    /**
     * Run ANALYZE TABLE and describe why it did not succeed.
     *
     * @return string|null Null when the server reported a clean positive status.
     */
    private function analyzeFailure(): ?string {
        $result = $this->query('ANALYZE TABLE {wp_abj404_logsv2}');
        $timedOut = !empty($result['timed_out']);
        $lastError = self::trimmedText($result['last_error'] ?? null);
        $status = $this->readStatusRows($this->statusRows($result));

        if (($result['query_succeeded'] ?? null) === true && !$timedOut && $lastError === ''
            && $status['positive'] && $status['error'] === '') {
            return null;
        }
        if ($timedOut) {
            return 'timed out after ' . self::QUERY_TIMEOUT_SECONDS . 's';
        }
        if ($lastError !== '') {
            return $lastError;
        }
        if ($status['error'] !== '') {
            return 'native status error: ' . $status['error'];
        }
        if (!empty($status['details'])) {
            return 'native status was not positive (' . implode('; ', $status['details']) . ')';
        }
        return 'native status was not positive (no status rows)';
    }

    /**
     * The status rows of an ANALYZE answer. ANALYZE is a mutation-shaped command
     * in the central query router, so its native status rows can be retained in
     * last_result instead of rows.
     *
     * @param array<string,mixed> $result
     * @return array<int|string,mixed>
     */
    private function statusRows(array $result): array {
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if (empty($rows) && isset($result['last_result']) && is_array($result['last_result'])) {
            $rows = $result['last_result'];
        }
        return $rows;
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return array{positive: bool, error: string, details: array<int,string>}
     */
    private function readStatusRows(array $rows): array {
        $positive = false;
        $errorRow = '';
        $details = array();
        foreach ($rows as $row) {
            if (is_object($row)) { $row = (array)$row; }
            if (!is_array($row)) {
                $details[] = 'malformed row=' . (string)json_encode($row);
                continue;
            }
            $type = self::scalarText($row['Msg_type'] ?? $row['msg_type'] ?? null);
            $text = self::scalarText($row['Msg_text'] ?? $row['msg_text'] ?? null);
            $details[] = 'Msg_type=' . ($type !== '' ? $type : '<missing>')
                . ' Msg_text=' . ($text !== '' ? $text : '<missing>');
            if (strcasecmp($type, 'status') === 0 && strcasecmp(trim($text), 'OK') === 0) {
                $positive = true;
            } elseif (strcasecmp($type, 'error') === 0) {
                $errorRow = trim($text);
            }
        }
        return array('positive' => $positive, 'error' => $errorRow, 'details' => $details);
    }

    /**
     * @param array<string,mixed> $result
     * @return int|null The estimated row count, or null when it is unreadable.
     */
    private function rowEstimate(array $result): ?int {
        if (($result['query_succeeded'] ?? null) !== true || !empty($result['timed_out'])
            || self::reportsError($result['last_error'] ?? null)) {
            return null;
        }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if (count($rows) !== 1 || !is_array($rows[0])) { return null; }
        $row = array_change_key_case($rows[0], CASE_LOWER);
        $value = $row['estimated_rows'] ?? null;
        if (is_int($value) && $value >= 0) { return $value; }
        if (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) { return (int)$value; }
        return null;
    }

    /** @param array<string,mixed> $result */
    private function estimateFailureDetail(array $result): string {
        if (!empty($result['timed_out'])) {
            return 'query timed out after ' . self::QUERY_TIMEOUT_SECONDS . 's';
        }
        $lastError = self::trimmedText($result['last_error'] ?? null);
        if ($lastError !== '') { return 'native error: ' . $lastError; }
        if (($result['query_succeeded'] ?? null) !== true) { return 'query was not successful'; }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if (count($rows) === 0) { return 'no metadata row returned'; }
        if (count($rows) !== 1) { return 'expected one metadata row, got ' . count($rows); }
        $row = is_object($rows[0]) ? (array)$rows[0] : $rows[0];
        if (!is_array($row)) { return 'metadata row was malformed'; }
        $encoded = json_encode($row);
        return 'metadata row lacked a non-negative estimated_rows value: '
            . ($encoded === false ? '<unencodable>' : $encoded);
    }

    /**
     * @param string $sql
     * @return array<string,mixed>
     */
    private function query(string $sql): array {
        return ($this->runQuery)(
            $sql,
            array('timeout' => self::QUERY_TIMEOUT_SECONDS, 'log_errors' => false)
        );
    }

    /**
     * The text of a scalar value, or '' for anything else (null, array, object).
     *
     * @param mixed $value
     */
    private static function scalarText($value): string {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param mixed $value
     */
    private static function trimmedText($value): string {
        return trim(self::scalarText($value));
    }

    /**
     * Whether a last_error slot reports a failure: any non-empty scalar, or a
     * value of an unexpected shape (an unexpected shape is never "clean").
     *
     * @param mixed $value
     */
    private static function reportsError($value): bool {
        return is_scalar($value) ? (string)$value !== '' : $value !== null;
    }
}
