<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lock-free, bounded compare-and-swap on a single WordPress options row, for
 * state that several concurrent requests append to or clear.
 *
 * get_option() followed by update_option() cannot do this on a site with no
 * persistent object cache (the default): WordPress serves the read from a
 * per-request cache and primes that cache with whatever it just wrote, so two
 * racing requests each read a value that no longer exists in the database and
 * the last writer silently discards the other's change. See
 * ABJ_404_Solution_ExclusiveOptionRow for the full account. The only thing that
 * arbitrates is the database, so this class reads the stored value with direct
 * SQL, computes the new value from exactly that read, and writes it with a
 * statement conditional on the row still holding what was read:
 *
 *   UPDATE {wp_options} SET option_value = new WHERE option_name = n AND BINARY option_value = BINARY old
 *
 * The guard compares bytes, including case and trailing spaces, independently
 * of the options table collation. Zero affected rows means another request
 * changed the row in between, so the
 * whole read-compute-write is repeated on the fresh value, at most
 * MAX_ATTEMPTS times. A row that does not exist yet is created with
 * INSERT IGNORE (autoload 'no'), which UNIQUE(option_name) satisfies exactly
 * once; a duplicate is a lost race and retried like any other.
 *
 * It never blocks and never throws, because its callers run in fatal and
 * shutdown handlers: after the attempts are spent the update is dropped and one
 * durable WARN names the option and the attempt count. All SQL goes through the
 * DatabaseCore query layer (ABJ_404_Solution_DatabaseQueryInterface), the way
 * ABJ_404_Solution_SameSiteRequestRegistry reaches wp_options.
 *
 * The action SEAM_ACTION fires between the read and the write. It is a real
 * extension point (observe or annotate a swap), and it is how the suite injects
 * a competing write at the exact moment the race window is open.
 */
final class ABJ_404_Solution_OptionRowCompareAndSwap {

    /** Read-compute-write repetitions before an update is dropped. */
    const MAX_ATTEMPTS = 3;

    /**
     * Fires immediately before a conditional write. Arguments: the option name
     * (string) and the 1-based attempt number (int).
     */
    const SEAM_ACTION = 'abj404_option_row_before_swap';

    /** The new value was written. */
    const RESULT_SWAPPED = 'swapped';

    /** The compute step declined to change the row (it returned null or the same value). */
    const RESULT_UNCHANGED = 'unchanged';

    /** Every attempt lost the race; the update was dropped and logged. */
    const RESULT_CONTENDED = 'contended';

    /** The database layer could not read or write the row; queryAndGetResults logged why. */
    const RESULT_UNAVAILABLE = 'unavailable';

    /**
     * Replace the row's value with one computed from its current value.
     *
     * @param array{optionName: string, compute: callable(string|null): (string|null), onFailure?:callable(string):void} $swap
     *   optionName: the wp_options row. compute: receives the value read from
     *   the database (null when the row does not exist) and returns the value
     *   to store, or null to leave the row alone. It runs once per attempt, so
     *   it must be side-effect free.
     * @return string one of the RESULT_* constants.
     */
    public static function update(array $swap): string {
        $optionName = $swap['optionName'];
        $onFailure = $swap['onFailure'] ?? null;
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            self::notifyFailure($onFailure, 'the database layer is unavailable', $optionName);
            self::warn('the database layer is unavailable', $optionName);
            return self::RESULT_UNAVAILABLE;
        }
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = self::readRow($dbCore, $optionName, $onFailure);
            if ($row['status'] === 'failed') {
                return self::RESULT_UNAVAILABLE;
            }
            $current = $row['status'] === 'found' ? $row['value'] : null;
            $next = call_user_func($swap['compute'], $current);
            if (!is_string($next) || $next === $current) {
                return self::RESULT_UNCHANGED;
            }
            do_action(self::SEAM_ACTION, $optionName, $attempt);
            $written = $current === null
                ? self::insertIfAbsent($dbCore, $optionName, $next, $onFailure)
                : self::replaceIfUnchanged($dbCore, $optionName, $current, $next, $onFailure);
            if ($written === null) {
                // A failed/ambiguous mutation may have reached the server before
                // its result envelope was lost. Do not claim success, but do not
                // leave a stale WordPress option cache pointing at the old value.
                ABJ_404_Solution_OptionCacheInvalidator::site($optionName);
                return self::RESULT_UNAVAILABLE;
            }
            if ($written) {
                ABJ_404_Solution_OptionCacheInvalidator::site($optionName);
                return self::RESULT_SWAPPED;
            }
        }
        self::warn('another request changed it on each of ' . self::MAX_ATTEMPTS . ' attempts, so this update was dropped',
            $optionName);
        return self::RESULT_CONTENDED;
    }

    /**
     * Delete the row, but only while it still holds the value the caller read.
     * A row a concurrent request rewrote in the meantime survives.
     *
     * @param array{optionName: string, expectedValue: string} $claim
     * @return bool true when this call removed the row.
     */
    public static function deleteIfValueIs(array $claim): bool {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            self::warn('the database layer is unavailable', $claim['optionName']);
            return false;
        }
        do_action(self::SEAM_ACTION, $claim['optionName'], 1);
        $result = self::query($dbCore, $claim['optionName'],
            "DELETE FROM {wp_options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
            array('query_params' => array($claim['optionName'], $claim['expectedValue']))
        );
        $deletedCount = self::affectedCount($result);
        if ($deletedCount === null) {
            // DELETE may have committed even when the driver returned false or
            // an incomplete envelope. The caller cannot report deletion, but a
            // cache entry for the old value is no longer safe.
            ABJ_404_Solution_OptionCacheInvalidator::site($claim['optionName']);
            return false;
        }
        $deleted = $deletedCount > 0;
        if ($deleted) {
            ABJ_404_Solution_OptionCacheInvalidator::site($claim['optionName']);
        }
        return $deleted;
    }

    /**
     * The value stored for the row, read from the database and not from
     * WordPress's option cache.
     *
     * @param string $optionName
     * @return string|null null when the row does not exist or could not be read.
     */
    public static function read(string $optionName): ?string {
        $row = self::readResult($optionName);
        return $row['status'] === 'found' ? $row['value'] : null;
    }

    /**
     * Read without collapsing database failure into an absent row. Admission
     * gates must reject unavailable state rather than substitute healthy defaults.
     *
     * @param string $optionName
     * @return array{status: 'found'|'absent'|'failed', value: string}
     */
    public static function readResult(string $optionName, ?callable $onFailure = null): array {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            self::notifyFailure($onFailure, 'the database layer is unavailable', $optionName);
            self::warn('the database layer is unavailable', $optionName);
            return array('status' => 'failed', 'value' => '');
        }
        return self::readRow($dbCore, $optionName, $onFailure);
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param string $optionName
     * @return array{status: 'found'|'absent'|'failed', value: string}
     */
    private static function readRow($dbCore, string $optionName, ?callable $onFailure = null): array {
        $result = self::query($dbCore, $optionName,
            "SELECT option_value FROM {wp_options} WHERE option_name = %s LIMIT 1",
            array('query_params' => array($optionName)), $onFailure
        );
        if (!self::querySucceeded($result)) {
            return array('status' => 'failed', 'value' => '');
        }
        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            self::reportMalformedResult($optionName, 'the successful read returned no rows array', $onFailure);
            return array('status' => 'failed', 'value' => '');
        }
        $rows = $result['rows'];
        if ($rows === array()) {
            return array('status' => 'absent', 'value' => '');
        }
        if (!array_key_exists(0, $rows) || !is_array($rows[0])) {
            self::reportMalformedResult($optionName, 'the successful read returned a malformed row', $onFailure);
            return array('status' => 'failed', 'value' => '');
        }
        // Case-insensitive: MySQL drivers vary the case of returned column names.
        $row = array_change_key_case($rows[0], CASE_LOWER);
        if (!array_key_exists('option_value', $row) || !is_scalar($row['option_value'])) {
            self::reportMalformedResult($optionName, 'the successful read returned no scalar option_value', $onFailure);
            return array('status' => 'failed', 'value' => '');
        }
        $value = $row['option_value'];
        return array('status' => 'found', 'value' => (string)$value);
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @return bool|null true when written, false when the row changed underneath, null on a database error.
     */
    private static function replaceIfUnchanged($dbCore, string $optionName, string $current, string $next, ?callable $onFailure = null): ?bool {
        $result = self::query($dbCore, $optionName,
            "UPDATE {wp_options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            array('query_params' => array($next, $optionName, $current)), $onFailure
        );
        return self::affectedOne($result);
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @return bool|null true when created, false when the row appeared underneath, null on a database error.
     */
    private static function insertIfAbsent($dbCore, string $optionName, string $next, ?callable $onFailure = null): ?bool {
        $result = self::query($dbCore, $optionName,
            "INSERT IGNORE INTO {wp_options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            array('query_params' => array($optionName, $next)), $onFailure
        );
        return self::affectedOne($result);
    }

    /**
     * SQL availability boundary for fatal/shutdown callers. The ordinary query
     * pipeline deliberately propagates driver exceptions; these callers must
     * preserve the row and report unavailable instead of interrupting shutdown.
     *
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function query($dbCore, string $optionName, string $sql, array $options, ?callable $onFailure = null): array {
        try {
            $result = $dbCore->queryAndGetResults($sql, $options);
        } catch (Throwable $e) {
            $error = get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage();
            self::notifyFailure($onFailure, $error, $optionName);
            self::warn('the SQL operation failed (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage(),
                $optionName);
            return array('last_error' => get_class($e) . ': ' . $e->getMessage(), 'rows' => array(), 'rows_affected' => 0);
        }
        if (!empty($result['last_error'])) {
            $error = $result['last_error'];
            self::notifyFailure($onFailure, is_scalar($error) ? (string)$error : var_export($error, true), $optionName);
        } elseif (!self::querySucceeded($result)) {
            $error = self::resultFailureReason($result);
            self::notifyFailure($onFailure, $error, $optionName);
            self::warn($error, $optionName);
        }
        return $result;
    }

    /** @param array<string, mixed> $result */
    private static function querySucceeded(array $result): bool {
        return array_key_exists('query_succeeded', $result)
            && $result['query_succeeded'] === true
            && array_key_exists('last_error', $result)
            && is_string($result['last_error'])
            && $result['last_error'] === ''
            && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
    }

    /** @param array<string, mixed> $result */
    private static function affectedCount(array $result): ?int {
        if (!self::querySucceeded($result) || !array_key_exists('rows_affected', $result)) {
            return null;
        }
        return ABJ_404_Solution_ExactInteger::read($result['rows_affected'], 0);
    }

    /** @param array<string, mixed> $result */
    private static function resultFailureReason(array $result): string {
        if (array_key_exists('timed_out', $result) && $result['timed_out'] === true) {
            return 'the SQL operation timed out';
        }
        if (!array_key_exists('query_succeeded', $result)) {
            return 'the SQL result omitted query_succeeded';
        }
        if ($result['query_succeeded'] !== true) {
            return 'the SQL operation was not confirmed successful';
        }
        if (!array_key_exists('last_error', $result) || !is_string($result['last_error'])) {
            return 'the SQL result omitted a valid last_error';
        }
        return 'the SQL result contained invalid metadata';
    }

    private static function reportMalformedResult(string $optionName, string $reason, ?callable $onFailure): void {
        self::notifyFailure($onFailure, $reason, $optionName);
        self::warn($reason, $optionName);
    }

    /** Preserve a refused SQL result before a later query resets wpdb's last_error. */
    private static function notifyFailure(?callable $observer, string $error, string $optionName): void {
        if ($observer === null) { return; }
        try {
            $observer($error);
        } catch (Throwable $e) {
            self::warn('failure observer failed (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage()
                . '; original SQL failure: ' . $error, $optionName);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return bool|null
     */
    private static function affectedOne(array $result): ?bool {
        $affected = self::affectedCount($result);
        if ($affected === null) {
            return null;
        }
        return $affected === 1;
    }

    /**
     * One durable WARN. Never throws: this runs in shutdown and fatal handlers.
     */
    private static function warn(string $why, string $optionName): void {
        $message = 'Could not update the options row "' . $optionName . '": ' . $why . '.';
        try {
            $logger = function_exists('abj_service') ? abj_service('logging') : null;
            if (is_object($logger) && method_exists($logger, 'warn')) {
                $logger->warn($message);
                return;
            }
        } catch (Throwable $e) {
            $message .= ' The logger failed too (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage();
        }
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('option-row-compare-and-swap', $message);
        }
    }

    /**
     * Read straight from the container, as ABJ_404_Solution_SameSiteRequestRegistry does.
     *
     * @return ABJ_404_Solution_DatabaseQueryInterface|null
     */
    private static function dbCore() {
        if (!class_exists('ABJ_404_Solution_ServiceContainer')) {
            return null;
        }
        $dbCore = ABJ_404_Solution_ServiceContainer::safeGet('db_core');
        return ($dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface) ? $dbCore : null;
    }
}
