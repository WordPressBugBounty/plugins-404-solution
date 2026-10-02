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
 *   UPDATE {wp_options} SET option_value = new WHERE option_name = n AND option_value = old
 *
 * Zero affected rows means another request changed the row in between, so the
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
     * @param array{optionName: string, compute: callable(string|null): (string|null)} $swap
     *   optionName: the wp_options row. compute: receives the value read from
     *   the database (null when the row does not exist) and returns the value
     *   to store, or null to leave the row alone. It runs once per attempt, so
     *   it must be side-effect free.
     * @return string one of the RESULT_* constants.
     */
    public static function update(array $swap): string {
        $optionName = $swap['optionName'];
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            self::warn('the database layer is unavailable', $optionName);
            return self::RESULT_UNAVAILABLE;
        }
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $row = self::readRow($dbCore, $optionName);
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
                ? self::insertIfAbsent($dbCore, $optionName, $next)
                : self::replaceIfUnchanged($dbCore, $optionName, $current, $next);
            if ($written === null) {
                return self::RESULT_UNAVAILABLE;
            }
            if ($written) {
                self::invalidateCaches($optionName);
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
        $result = $dbCore->queryAndGetResults(
            "DELETE FROM {wp_options} WHERE option_name = %s AND option_value = %s",
            array('query_params' => array($claim['optionName'], $claim['expectedValue']))
        );
        if (!empty($result['last_error'])) {
            return false;
        }
        $deleted = ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0) > 0;
        if ($deleted) {
            self::invalidateCaches($claim['optionName']);
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
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return null;
        }
        $row = self::readRow($dbCore, $optionName);
        return $row['status'] === 'found' ? $row['value'] : null;
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param string $optionName
     * @return array{status: string, value: string} status is found, absent or failed.
     */
    private static function readRow($dbCore, string $optionName): array {
        $result = $dbCore->queryAndGetResults(
            "SELECT option_value FROM {wp_options} WHERE option_name = %s LIMIT 1",
            array('query_params' => array($optionName))
        );
        if (!empty($result['last_error'])) {
            return array('status' => 'failed', 'value' => '');
        }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if ($rows === array() || !is_array($rows[0])) {
            return array('status' => 'absent', 'value' => '');
        }
        // Case-insensitive: MySQL drivers vary the case of returned column names.
        $row = array_change_key_case($rows[0], CASE_LOWER);
        $value = $row['option_value'] ?? null;
        return array('status' => 'found', 'value' => is_scalar($value) ? (string)$value : '');
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @return bool|null true when written, false when the row changed underneath, null on a database error.
     */
    private static function replaceIfUnchanged($dbCore, string $optionName, string $current, string $next): ?bool {
        $result = $dbCore->queryAndGetResults(
            "UPDATE {wp_options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            array('query_params' => array($next, $optionName, $current))
        );
        return self::affectedOne($result);
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @return bool|null true when created, false when the row appeared underneath, null on a database error.
     */
    private static function insertIfAbsent($dbCore, string $optionName, string $next): ?bool {
        $result = $dbCore->queryAndGetResults(
            "INSERT IGNORE INTO {wp_options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            array('query_params' => array($optionName, $next))
        );
        return self::affectedOne($result);
    }

    /**
     * @param array<string, mixed> $result
     * @return bool|null
     */
    private static function affectedOne(array $result): ?bool {
        if (!empty($result['last_error'])) {
            return null;
        }
        return ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0) === 1;
    }

    /**
     * A direct SQL write leaves WordPress's option caches describing the row as
     * it was, so the next get_option() would serve a value that no longer
     * exists. Same three keys CanonicalHookCensusStore::refreshReads() drops,
     * plus alloptions for a row that was autoloaded.
     */
    private static function invalidateCaches(string $optionName): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete($optionName, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
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
