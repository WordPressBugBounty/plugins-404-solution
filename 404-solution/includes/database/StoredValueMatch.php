<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The SQL predicate for "this column still holds exactly this value": the
 * guard of every compare-and-swap, guarded delete and fenced write the plugin
 * makes on a row it read a moment earlier, and the exact-match refinement of
 * every redirect URL lookup.
 *
 * The guard must compare bytes, including case and trailing spaces, whatever
 * the column collation. `BINARY column = BINARY literal` does that, but it
 * compares the column's bytes in the COLUMN charset with the literal's bytes in
 * the CONNECTION charset (WordPress's DB_CHARSET, sent with SET NAMES). The two
 * are the same bytes only when the charsets match. A site can run a latin1
 * connection against utf8mb4 tables: the server converts each literal to the
 * column charset on a write and back on a read, so PHP always sees its own
 * bytes but the column holds different ones, and a non-ASCII value never
 * matches the row it was just read from. The write that guarded on it is then
 * refused on every attempt (the settings version stamp stays behind the running
 * code; every options compare-and-swap reports contention), and a redirect for
 * a non-ASCII URL is never found.
 *
 * Converting both sides to one charset removes the dependence on the
 * connection. utf8mb4 holds every character of every MySQL charset, so the
 * conversion loses nothing, and it needs no PHP-side knowledge of which charset
 * the connection or the column uses (wpdb::$charset can be empty or stale).
 * Where the charsets already match the predicate is exactly the byte compare it
 * replaces.
 *
 * Wrapping the column in a function makes it non-sargable. A caller on a hot
 * path keeps a plain `column = value` (or `column IN (...)`) prefilter that the
 * index serves, and adds this predicate as the exact refinement.
 */
final class ABJ_404_Solution_StoredValueMatch {

    /** The charset both sides are converted to before the byte compare. */
    private const COMMON_CHARSET = 'utf8mb4';

    /**
     * The predicate "the column holds exactly this value".
     *
     * @param string $column The stored value: a column reference or expression
     *   ('option_value', 'settings.option_value', 'DATABASE()').
     * @param string $valueSql The compared value as SQL in the connection
     *   charset: a quoted literal as wpdb::prepare('%s', ...) returns it, or the
     *   '%s' placeholder of a query that is prepared afterwards.
     * @return string A boolean SQL expression safe to AND into a WHERE clause.
     */
    public static function equals(string $column, string $valueSql): string {
        return self::bytes($column) . ' = ' . self::bytes($valueSql);
    }

    /**
     * The predicate "the column holds exactly one of these values".
     *
     * @param string $column See equals().
     * @param array<int, string> $valueSqls Each compared value as SQL, as in equals(). Never empty:
     *   `IN ()` is a syntax error, and the caller knows what an empty list means.
     * @return string A boolean SQL expression safe to AND into a WHERE clause.
     */
    public static function isOneOf(string $column, array $valueSqls): string {
        if ($valueSqls === array()) {
            throw new InvalidArgumentException('StoredValueMatch::isOneOf needs at least one value.');
        }
        return self::bytes($column) . ' IN (' . implode(', ', array_map(array(self::class, 'bytes'), $valueSqls)) . ')';
    }

    /**
     * The expression as bytes in the common charset.
     *
     * @param string $sql A column, expression or value literal.
     * @return string
     */
    private static function bytes(string $sql): string {
        return 'BINARY CONVERT(' . $sql . ' USING ' . self::COMMON_CHARSET . ')';
    }
}
