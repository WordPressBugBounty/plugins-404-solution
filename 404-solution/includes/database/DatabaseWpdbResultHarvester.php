<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies wpdb execution state into the plugin's standard query result array.
 *
 * The query pipeline and recovery paths all need the same fields after each
 * initial query or retry. Centralizing the adapter keeps the result contract
 * consistent across SELECT, mutation, timeout fallback, table recovery,
 * and table-repair retry paths.
 */
class ABJ_404_Solution_DatabaseWpdbResultHarvester {

    /**
     * @param array<string, mixed> $result
     * @param mixed $driverResult Raw return value from wpdb::get_results() or
     *   wpdb::query(). Null is treated as a failed driver return.
     * @param bool $producesRows True for a read return, false for a mutation
     *   return. Legacy callers omit the raw return and retain read routing.
     * @return void
     */
    public function harvestWpdbResult(array &$result, $driverResult = null, bool $producesRows = true): void {
        global $wpdb;
        $result['last_error'] = (string)($wpdb->last_error ?? '');
        $result['last_result'] = $wpdb->last_result ?? array();
        $result['rows_affected'] = $wpdb->rows_affected ?? 0;
        $result['insert_id'] = $wpdb->insert_id ?? 0;

        // wpdb only hydrates mutation metadata when SQL starts with its DML
        // keyword. MariaDB's SET STATEMENT timeout wrapper (and leading SQL
        // comments) masks that keyword, even though the driver stored the row.
        // Read the current statement's metadata before any nested query can
        // overwrite it. Do not consult native state after a failed return.
        if (!$producesRows && $this->driverResultSucceeded($driverResult, false)
                && $result['last_error'] === '' && isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
            $driverResult = mysqli_affected_rows($wpdb->dbh);
            $result['rows_affected'] = $driverResult;
            $result['insert_id'] = mysqli_insert_id($wpdb->dbh);
        }
        $result['query_succeeded'] = $this->driverResultSucceeded($driverResult, $producesRows)
            && $result['last_error'] === '';
        $result['query_rows_affected'] = !$producesRows && is_int($driverResult) && $driverResult >= 0
            ? $driverResult : null;
    }

    /**
     * wpdb reads succeed only when they return an array, including an empty
     * array. Mutations accept the documented integer count (including zero)
     * or boolean true. False, null, negative, and other values are failures.
     *
     * @param mixed $driverResult
     * @param bool $producesRows
     * @return bool
     */
    private function driverResultSucceeded($driverResult, bool $producesRows): bool {
        if ($producesRows) {
            return is_array($driverResult);
        }
        if ($driverResult === true) {
            return true;
        }
        return is_int($driverResult) && $driverResult >= 0;
    }
}
