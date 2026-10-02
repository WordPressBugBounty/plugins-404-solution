<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's transient WRITE boundary, and the only place allowed to decide
 * what a refused `set_transient()` means.
 *
 * WordPress's transient API answers a question callers do not ask. `store()`'s
 * callers want to know "is the value I asked for in the store now"; the
 * primitive returns whether the STORED BYTES CHANGED:
 *
 *   - `update_option()` returns false for a write whose serialized value is
 *     already in the row, byte for byte (wp-includes/option.php compares
 *     `maybe_serialize($value) === maybe_serialize($old_value)` and returns
 *     early). Two requests publishing the same packet inside one wall-clock
 *     second are the common way to hit it.
 *   - `add_option()` returns false when the option already exists, which is
 *     the losing side of an add/add race whose winner stored the same thing.
 *
 * Both are the store agreeing with the caller, and reading them as failure
 * produces a diagnostic about work that was already done, plus whatever
 * "recovery" the caller announces for it. That reached production twice: as
 * `[SUGGESTION_CACHE_WRITE_FAILED]` (report 395) and, through the same
 * primitive one layer down, as WordPress's own `could_not_set` cron error
 * (reports 276 / 285 / 292, settled by {@see ABJ_404_Solution_CronWriteOutcome}).
 *
 * So the verdict comes from the durable end state: write, and when the store
 * declines, read back and compare. Only a store that does not hold the
 * requested value afterwards has failed, and that case still reports, because
 * a full options table and a downed object cache are real and the caller's
 * fallback is real work.
 *
 * Read side deliberately absent. `get_transient()` already answers its own
 * question truthfully and needs no interpretation.
 */
class ABJ_404_Solution_TransientStore {

    /**
     * Store a transient and report whether the store holds the requested value
     * afterwards, which is what every caller actually means by "did the write
     * succeed".
     *
     * @param string $key Transient key (already namespaced by the caller).
     * @param mixed $value Value to store. Serialized by WordPress as needed.
     * @param int $ttlSeconds Transient lifetime in seconds.
     * @return bool True when the store holds $value (whether this call wrote
     *   it or an identical concurrent write got there first); false only when
     *   the store genuinely does not hold it.
     */
    public static function store(string $key, $value, int $ttlSeconds): bool {
        if (!function_exists('set_transient')) {
            return false;
        }
        // This class only writes what it is handed and reports the end state;
        // whether $value is worth caching is decided and guarded at the call site.
        // allow-cache-empty: boundary adapter, the one file the marker is for.
        if (set_transient($key, $value, $ttlSeconds)) {
            return true;
        }
        return self::holdsValue($key, $value);
    }

    /**
     * Whether the store currently holds $value under $key.
     *
     * Consulted only after a refused write, so a false negative costs nothing
     * beyond the pre-existing behaviour (the caller reports a failure it would
     * have reported anyway), while a false positive would silence a real one.
     * The comparison is biased accordingly: it is strict about shape, and only
     * loose about the string/int round trip the options table performs on
     * every scalar it stores.
     *
     * @param string $key Transient key.
     * @param mixed $value The value the caller asked to store.
     * @return bool
     */
    public static function holdsValue(string $key, $value): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        $stored = get_transient($key);
        // get_transient() cannot distinguish "absent" from "holds false", so an
        // absent-looking read is treated as a failed write. Conservative on
        // purpose: the caller keeps the behaviour it had before this class.
        if ($stored === false) {
            return false;
        }
        return self::isSameStoredValue($stored, $value);
    }

    /**
     * Compare a read-back value against the one that was requested, the way
     * the storage layer itself would.
     *
     * @param mixed $stored Value read back from the store.
     * @param mixed $requested Value the caller asked to store.
     * @return bool
     */
    private static function isSameStoredValue($stored, $requested): bool {
        if (is_array($stored) || is_object($stored) || is_array($requested) || is_object($requested)) {
            return self::serializeForComparison($stored) === self::serializeForComparison($requested);
        }
        if (is_bool($stored) || is_bool($requested) || $stored === null || $requested === null) {
            return $stored === $requested;
        }
        if (!is_scalar($stored) || !is_scalar($requested)) {
            return false;
        }
        // wp_options stores every scalar as a string, so an int written is an
        // int-shaped string read. Comparing the stored forms is what the round
        // trip actually preserves; `===` here would call every counter write a
        // failure the moment the store declined a no-op.
        return (string) $stored === (string) $requested;
    }

    /**
     * Serialize for comparison the way WordPress serializes for storage.
     *
     * @param mixed $value
     * @return string
     */
    private static function serializeForComparison($value): string {
        if (function_exists('maybe_serialize')) {
            $serialized = maybe_serialize($value);
            return is_string($serialized) ? $serialized : serialize($serialized);
        }
        return serialize($value);
    }
}
