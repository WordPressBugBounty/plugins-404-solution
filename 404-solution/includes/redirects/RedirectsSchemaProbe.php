<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema probes for the redirects tables, shared by the denormalized-column
 * chunk resolver and the logs_hits write-back.
 *
 * Both callers run under budgets: the write-back supplies a lease-renewing
 * beforeQuery callback, the chunk resolver supplies none. Every probe takes
 * that callback, reports failures through an out parameter instead of
 * throwing, and turns "query did not succeed" into an explicit error so a
 * malformed or timed-out result is never read as "column or table absent".
 *
 * Stateless: nothing is memoized here. A caller that wants a stable view
 * across one pass keeps the returned set itself, so a later request
 * rediscovers a schema that changed in between.
 */
class ABJ_404_Solution_RedirectsSchemaProbe {

    /**
     * Lowercased column-name set of the redirects table via one SHOW COLUMNS.
     *
     * Honors the caller's lease/budget callback: beforeQuery runs immediately
     * before the query and supplies its query options. Failures are reported
     * through $error, never thrown, and yield an empty set so every
     * column-presence check degrades to "absent" (the safe schema-drift
     * fallback: skip the write or omit the column).
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param string $redirectsTable Fully-qualified redirects table name.
     * @param callable():array<string,mixed>|null $beforeQuery
     * @param-out string $error Empty on success, otherwise the failure cause.
     * @return array<string,bool>
     */
    public static function columnSet(
        ABJ_404_Solution_DatabaseCore $dbCore,
        string $redirectsTable,
        ?callable $beforeQuery = null,
        ?string &$error = null
    ): array {
        $error = '';
        if ($beforeQuery !== null) {
            $queryOptions = $beforeQuery();
            if (!is_array($queryOptions)) {
                $error = 'before_query must return query options.';
                return array();
            }
            $queryOptions['log_errors'] = false;
        } else {
            $queryOptions = array('log_errors' => false);
        }
        $result = $dbCore->queryAndGetResults('SHOW COLUMNS FROM ' . $redirectsTable, $queryOptions);
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? trim((string)$result['last_error']) : '';
        if ($lastError !== '') {
            $error = $lastError;
            return array();
        }
        if (!empty($result['timed_out'])) {
            $error = 'Database query timed out.';
            return array();
        }
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            $error = 'Database query failed without an error message.';
            return array();
        }
        return self::columnSetFromResult($result);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,bool>
     */
    private static function columnSetFromResult(array $result): array {
        $set = array();
        foreach (is_array($result['rows'] ?? null) ? $result['rows'] : array() as $row) {
            if (!is_array($row)) { continue; }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'field' && is_scalar($value)) {
                    $set[strtolower((string)$value)] = true;
                    break;
                }
            }
        }
        return $set;
    }

    /**
     * Whether a plugin table exists, probed with SHOW TABLES LIKE through the
     * centralized DAO with error logging off, so an absent table on a
     * stripped install is a clean "false" rather than a logged failure.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param string $tableToken Table placeholder such as {wp_abj404_logs_hits}.
     * @param callable():array<string,mixed>|null $beforeQuery
     * @param-out string $error Empty when the probe ran, otherwise the failure cause.
     */
    public static function tableExists(
        ABJ_404_Solution_DatabaseCore $dbCore,
        string $tableToken,
        ?callable $beforeQuery,
        ?string &$error
    ): bool {
        $error = '';
        $queryOptions = array('log_errors' => false);
        if ($beforeQuery !== null) {
            $queryOptions = $beforeQuery();
            if (!is_array($queryOptions)) {
                $error = 'before_query must return query options.';
                return false;
            }
        }
        $queryOptions['log_errors'] = false;
        $table = $dbCore->doTableNameReplacements($tableToken);
        $result = $dbCore->queryAndGetResults('SHOW TABLES LIKE %s', array_merge(
            $queryOptions,
            array('query_params' => array($table))
        ));
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? trim((string)$result['last_error']) : '';
        if ($lastError !== '') {
            $error = $lastError;
            return false;
        }
        if (!empty($result['timed_out'])) {
            $error = 'Database query timed out.';
            return false;
        }
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            $error = 'Database query failed without an error message.';
            return false;
        }
        $rows = $result['rows'] ?? null;
        $row = is_array($rows) && is_array($rows[0] ?? null) ? $rows[0] : array();
        $value = reset($row);
        return is_scalar($value) && (string)$value === (string)$table;
    }

}
