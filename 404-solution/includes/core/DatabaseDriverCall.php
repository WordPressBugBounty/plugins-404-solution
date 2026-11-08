<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's only caller of wpdb's statement methods.
 *
 * wpdb::print_error() records a failed statement in last_error and
 * $EZSQL_ERROR, then calls error_log() unless the connection's
 * suppress_errors flag is set. A host that lists error_log in
 * disable_functions turns that call into a thrown Error on PHP 8 (a warning
 * on PHP 7.4), so an ordinary infrastructure failure (a read-only replica,
 * a missing table, a lost connection) becomes a fatal in whichever plugin
 * line issued the statement, before that line can read last_error.
 *
 * Every plugin statement therefore runs through this class, which sets
 * suppress_errors for the duration of one call only when the core printer
 * cannot run, and restores the flag the connection had before. The return
 * value, last_error and $EZSQL_ERROR are exactly what wpdb produced, so a
 * caller reads a failure the same way on every host. Where error_log() is
 * available nothing changes and core keeps writing its usual error line.
 *
 * The scope is per call on purpose. Setting suppress_errors once at boot
 * would also silence core's and other plugins' statements on hosts where
 * their errors still reach the PHP error log.
 *
 * Callers pass the connection they would have called and exactly the
 * arguments they would have passed, so a drop-in, a test double or a
 * strict mock sees the same call it always did.
 */
class ABJ_404_Solution_DatabaseDriverCall {

    /**
     * wpdb::query() with the caller's own arguments, forwarded unchanged.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string $query
     * @return int|bool
     */
    public static function query($wpdb, $query) {
        return self::run($wpdb, static fn($wpdb) => $wpdb->query($query));
    }

    /**
     * wpdb::get_var() with the caller's own arguments, forwarded unchanged:
     * only the arguments the caller passed reach wpdb.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string|null $query
     * @param int $x
     * @param int $y
     * @return string|null The provider's exact value, including empty strings
     *   from compatible connection adapters; this boundary does not normalize it.
     */
    public static function getVar($wpdb, $query = null, $x = 0, $y = 0) {
        $passed = func_num_args() - 1;
        return self::run($wpdb, static function ($wpdb) use ($query, $x, $y, $passed) {
            if ($passed >= 3) {
                return $wpdb->get_var($query, $x, $y);
            }
            if ($passed === 2) {
                return $wpdb->get_var($query, $x);
            }
            return $passed === 1 ? $wpdb->get_var($query) : $wpdb->get_var();
        });
    }

    /**
     * Read one scalar on an already-owned connection without reconnect or
     * recovery. Lock identity and guarded native option writes must observe
     * this connection, even before the service graph is available. Failed
     * reads never authorize work: a driver error, or a value that is not a
     * string, reads as null.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string $query
     * @return string|null Null when the value or its query could not be read.
     */
    public static function getCheckedVar($wpdb, string $query): ?string {
        $value = self::getVar($wpdb, $query);
        $error = $wpdb->last_error ?? '';
        return is_string($error) && $error === '' && is_string($value) ? $value : null;
    }

    /**
     * wpdb::get_row() with the caller's own arguments, forwarded unchanged:
     * only the arguments the caller passed reach wpdb.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string|null $query
     * @param string $output
     * @param int $y
     * @return array<mixed>|object|null
     * @phpstan-param 'OBJECT'|'ARRAY_A'|'ARRAY_N' $output
     * @phpstan-param int<0, max> $y
     * @phpstan-return (
     *     $query is non-falsy-string
     *         ? (
     *             $output is 'OBJECT'
     *                 ? stdClass|null
     *                 : (
     *                     $output is 'ARRAY_A'
     *                         ? array<array-key, mixed>|null
     *                         : (
     *                             $output is 'ARRAY_N'
     *                                 ? list<mixed>|null
     *                                 : null
     *                         )
     *                 )
     *         )
     *         : null
     * )
     */
    public static function getRow($wpdb, $query = null, $output = OBJECT, $y = 0) {
        $passed = func_num_args() - 1;
        return self::run($wpdb, static function ($wpdb) use ($query, $output, $y, $passed) {
            if ($passed >= 3) {
                return $wpdb->get_row($query, $output, $y);
            }
            if ($passed === 2) {
                return $wpdb->get_row($query, $output);
            }
            return $passed === 1 ? $wpdb->get_row($query) : $wpdb->get_row();
        });
    }

    /**
     * wpdb::get_results() with the caller's own arguments, forwarded
     * unchanged: only the arguments the caller passed reach wpdb.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string|null $query
     * @param string $output
     * @return array<mixed>|object|null
     * @phpstan-param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $output
     * @phpstan-return (
     *     $query is non-falsy-string
     *         ? (
     *             $output is 'OBJECT'
     *                 ? list<stdClass>|null
     *                 : (
     *                     $output is 'OBJECT_K'
     *                         ? array<array-key, stdClass>
     *                         : (
     *                             $output is 'ARRAY_A'
     *                                 ? list<array<array-key, mixed>>
     *                                 : (
     *                                     $output is 'ARRAY_N'
     *                                         ? list<list<mixed>>
     *                                         : null
     *                                 )
     *                         )
     *                 )
     *         )
     *         : null
     * )
     */
    public static function getResults($wpdb, $query = null, $output = OBJECT) {
        $passed = func_num_args() - 1;
        return self::run($wpdb, static function ($wpdb) use ($query, $output, $passed) {
            if ($passed >= 2) {
                return $wpdb->get_results($query, $output);
            }
            return $passed === 1 ? $wpdb->get_results($query) : $wpdb->get_results();
        });
    }

    /**
     * wpdb::get_col() with the caller's own arguments, forwarded unchanged:
     * only the arguments the caller passed reach wpdb.
     *
     * @param object $wpdb The connection the caller would have called.
     * @param string|null $query
     * @param int $x
     * @return array<mixed> The provider's exact keys and values, without list
     *   normalization or removal of empty values from compatible adapters.
     */
    public static function getCol($wpdb, $query = null, $x = 0) {
        $passed = func_num_args() - 1;
        return self::run($wpdb, static function ($wpdb) use ($query, $x, $passed) {
            if ($passed >= 2) {
                return $wpdb->get_col($query, $x);
            }
            return $passed === 1 ? $wpdb->get_col($query) : $wpdb->get_col();
        });
    }

    /**
     * Open a suppression scope that outlives one statement, for the query
     * pipeline that retries and repairs inside one window. Pair every call
     * with {@see restorePrinter()} in a finally block.
     *
     * @param mixed $wpdb
     * @param bool $suppressEvenWhenPrinterCanRun True when the caller reports
     *   the error itself and does not want core's line as well.
     * @return array{opened: bool, previous: bool} The token restorePrinter() needs.
     */
    public static function suppressPrinter($wpdb, bool $suppressEvenWhenPrinterCanRun = false): array {
        $opened = is_object($wpdb) && method_exists($wpdb, 'suppress_errors')
            && ($suppressEvenWhenPrinterCanRun || !self::corePrinterCanRun());
        $previous = $opened ? (bool)$wpdb->suppress_errors(true) : false;
        return array('opened' => $opened, 'previous' => $previous);
    }

    /**
     * Close a scope opened by {@see suppressPrinter()}.
     *
     * @param mixed $wpdb
     * @param array{opened: bool, previous: bool} $token
     * @return void
     */
    public static function restorePrinter($wpdb, array $token): void {
        if ($token['opened'] && is_object($wpdb) && method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($token['previous']);
        }
    }

    /**
     * Whether wpdb::print_error() can reach error_log() on this host.
     *
     * @return bool
     */
    public static function corePrinterCanRun(): bool {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('error_log');
    }

    /**
     * Run one statement on the connection inside a per-call printer scope.
     *
     * @template T
     * @param object $wpdb
     * @param callable(wpdb): T $statement
     * @return T
     */
    private static function run($wpdb, callable $statement) {
        /** @var wpdb $connection Core's wpdb, a drop-in subclass, or a double with its methods. */
        $connection = $wpdb;
        $token = self::suppressPrinter($connection);
        try {
            return $statement($connection);
        } finally {
            self::restorePrinter($connection, $token);
        }
    }
}
