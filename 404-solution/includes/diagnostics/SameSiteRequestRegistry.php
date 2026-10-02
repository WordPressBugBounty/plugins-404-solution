<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for the set of PHP requests this site currently has in flight.
 *
 * ONE ROW PER REQUEST, written and deleted by that request alone. A single
 * shared counter incremented at request start and decremented at shutdown is
 * corrupted by exactly the event the census exists to investigate: a request
 * killed mid-flight never runs its decrement, so the counter drifts upward
 * forever and every later reading is fabricated. A row that only its own
 * request ever writes has no read-modify-write and therefore no interleaving
 * to lose, and a row whose request died is simply an old row -- something a
 * reader can recognise and delete, which a corrupted integer is not.
 *
 * This class owns the row's names and the statements that write, read and
 * remove it; the layout of its stored value belongs to
 * ABJ_404_Solution_SameSiteRequestRowCodec. What counts as "old", which requests are allowed to register, and what a
 * reading reports belong to ABJ_404_Solution_SameSiteRequestCensus. The same
 * split this subsystem already uses for
 * ABJ_404_Solution_AjaxCheckpointLogger and its journal writer.
 *
 * @phpstan-import-type Row from ABJ_404_Solution_SameSiteRequestRowCodec
 * @phpstan-import-type Entry from ABJ_404_Solution_SameSiteRequestRowCodec
 */
final class ABJ_404_Solution_SameSiteRequestRegistry {

    /**
     * Option-name prefix for one in-flight request. Alphanumeric on purpose:
     * it is used as a LIKE prefix, and `_` is a single-character wildcard in
     * LIKE, so an underscore here would silently widen the match to rows this
     * class never wrote.
     */
    const OPTION_PREFIX = 'abj404inflight';

    /**
     * Rows read at once. A real reading is a handful; the ceiling exists so a
     * pathological leak degrades into a truncated (and self-announcing)
     * reading rather than an unbounded SELECT on the path being measured.
     */
    const MAX_ENTRIES_READ = 200;

    /** Rows removed per call. Repeated readings converge; one never stalls on cleanup. */
    const MAX_REMOVED_PER_CALL = 50;

    /**
     * Option-name prefix for a retired request: a finished instrumented
     * request renamed out of the in-flight namespace so a retry or beacon
     * arriving later can still find its timeline. Alphanumeric for the same
     * LIKE reason as OPTION_PREFIX, and disjoint from it so a reading of
     * live requests never counts a retired one.
     */
    const RETIRED_PREFIX = 'abj404reqdone';

    /** Retired rows removed per prune call. Repeated prunes converge. */
    const MAX_PRUNED_PER_CALL = 50;

    /**
     * Register one in-flight request.
     *
     * INSERT IGNORE rather than an upsert: this row belongs to this request
     * alone and nothing else may ever write it, which is the property that
     * makes the whole registry race-free.
     *
     * The row is keyed, not positional: channel, action, phase and timeline are
     * all strings, so a swapped pair would type-check and store one under the
     * other's name.
     *
     * @param Row $row the request's identity. `phase` is the segment it is in
     *   at registration: the caller names it (which segments exist, and what
     *   they mean, belongs to ABJ_404_Solution_SameSiteRequestCensus) and it is
     *   written with the row rather than by a follow-up update so registration
     *   still costs one query. `timeline` is the request's encoded phase
     *   timeline, carried on this write rather than as an extra statement.
     * @param string $processToken names the row when the PID is unavailable.
     * @return string the option name the request was registered under, or ''
     *   when it could not be registered at all.
     */
    public static function add(array $row, string $processToken = ''): string {
        $pid = $row['pid'];
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return '';
        }
        // Preserve the historical hexadecimal PID name on ordinary hosts;
        // only the unavailable-PID branch needs the synthetic process token.
        $identity = $pid !== null ? dechex($pid)
            : ($processToken !== '' ? $processToken : 'unavailable');
        $identity = substr((string)preg_replace('/[^A-Za-z0-9-]/', '', $identity), 0, 32);
        $optionName = self::OPTION_PREFIX . $identity
            . preg_replace('/[^a-f0-9]/', '', uniqid('', true));
        $result = $dbCore->queryAndGetResults(
            "INSERT IGNORE INTO {wp_options} (option_name, option_value, autoload) "
            . "VALUES (%s, %s, 'no')",
            array('query_params' => array($optionName,
                ABJ_404_Solution_SameSiteRequestRowCodec::encode($row)))
        );
        if (!empty($result['last_error'])) {
            // queryAndGetResults already logged it (CLAUDE.md: it is the
            // centralized error handler). A registry that cannot register is a
            // missing diagnostic, never a reason to affect the request.
            return '';
        }
        return $optionName;
    }

    /**
     * Record which segment of its own lifecycle this request has entered.
     *
     * A plain UPDATE of one row by primary key, and the single-writer property
     * that makes the registry race-free is what makes it safe: the row belongs
     * to this request alone, so there is no read-modify-write and nothing to
     * interleave with. The other fields are rewritten from the caller's own
     * values rather than read back and merged, for the same reason.
     *
     * ALWAYS CALLED BEFORE ENTERING THE SEGMENT IT NAMES, never after. A
     * request that dies inside a segment cannot write anything afterwards, so
     * a phase recorded on the way out would be exactly the one missing from
     * every row worth reading. Recorded on the way in, an abandoned row's
     * phase names the segment the worker was inside when it stopped -- which
     * is the entire question a stranded worker poses.
     *
     * @param string $optionName the row this request registered under.
     * @param Row $row the request's identity with its new `phase` and timeline.
     * @return bool whether the row was updated.
     */
    public static function advance(string $optionName, array $row): bool {
        $dbCore = self::dbCore();
        if ($dbCore === null || $optionName === '') {
            return false;
        }
        $result = $dbCore->queryAndGetResults(
            "UPDATE {wp_options} SET option_value = %s WHERE option_name = %s",
            array('query_params' => array(ABJ_404_Solution_SameSiteRequestRowCodec::encode($row), $optionName))
        );
        // queryAndGetResults is the centralized error handler (CLAUDE.md #11).
        // A phase that cannot be recorded is a coarser reading, never a reason
        // to affect the request being measured.
        return empty($result['last_error']);
    }

    /**
     * Every registered request, decoded, oldest option name first.
     *
     * `truncated` says the read ceiling was reached, so a reader can tell a
     * bounded reading from a complete one instead of quietly believing the
     * smaller number.
     *
     * @return array{status: string, reason: string, entries: array<int, Entry>, truncated: bool}
     */
    public static function readAll(): array {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return self::unreadable('dao_unavailable');
        }
        $result = $dbCore->queryAndGetResults(
            "SELECT option_name, option_value FROM {wp_options} "
            . "WHERE option_name LIKE %s ORDER BY option_name LIMIT " . (self::MAX_ENTRIES_READ + 1),
            array('query_params' => array(self::OPTION_PREFIX . '%'))
        );
        if (!empty($result['last_error'])) {
            return self::unreadable('read_failed');
        }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $truncated = count($rows) > self::MAX_ENTRIES_READ;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ENTRIES_READ);
        }
        $entries = array();
        foreach ($rows as $row) {
            $entry = ABJ_404_Solution_SameSiteRequestRowCodec::decodeRow($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return array('status' => 'available', 'reason' => '', 'entries' => $entries,
            'truncated' => $truncated);
    }

    /**
     * Remove registrations by option name. Idempotent, bounded per call, and
     * safe when two readers remove the same name at once.
     *
     * @param array<int, string> $optionNames
     * @return int how many rows the delete claimed.
     */
    public static function remove(array $optionNames): int {
        $optionNames = array_values($optionNames);
        $dbCore = self::dbCore();
        if ($dbCore === null || $optionNames === array()) {
            return 0;
        }
        $optionNames = array_slice($optionNames, 0, self::MAX_REMOVED_PER_CALL);
        $placeholders = implode(', ', array_fill(0, count($optionNames), '%s'));
        $result = $dbCore->queryAndGetResults(
            "DELETE FROM {wp_options} WHERE option_name IN (" . $placeholders . ")",
            array('query_params' => $optionNames)
        );
        if (!empty($result['last_error'])) {
            return 0;
        }
        return ABJ_404_Solution_ExactInteger::readOr(
            $result['rows_affected'] ?? null,
            0,
            count($optionNames)
        );
    }

    /**
     * Retire one owned row: rename it into the retired namespace and rewrite
     * its value, in one UPDATE by primary key. The single-writer property is
     * what makes this safe, exactly as for advance(): the row belongs to the
     * retiring request alone.
     *
     * Retiring replaces the shutdown DELETE for the instrumented endpoint, at
     * the same statement count. The retired name carries the request's start
     * timestamp so pruning is an index-range DELETE rather than a scan.
     *
     * The retired name is built here, from the row's start time and the
     * request id, so the layout pruneRetired() ranges over and findTimeline()
     * matches on has exactly one writer.
     *
     * @param string $optionName the in-flight row this request owns.
     * @param Row $row the row's final identity, phase and timeline.
     * @param string $requestId the request's ledger id.
     * @return bool whether the row was retired.
     */
    public static function retire(string $optionName, array $row, string $requestId): bool {
        $dbCore = self::dbCore();
        if ($dbCore === null || $optionName === '' || $requestId === '') {
            return false;
        }
        $result = $dbCore->queryAndGetResults(
            "UPDATE {wp_options} SET option_name = %s, option_value = %s WHERE option_name = %s",
            array('query_params' => array(
                self::retiredNameFor($row['started_at_ms'], $requestId), ABJ_404_Solution_SameSiteRequestRowCodec::encode($row), $optionName))
        );
        return empty($result['last_error']);
    }

    /**
     * Delete retired rows whose start timestamp predates the cutoff, bounded
     * per call. The retired name sorts by start time under the shared prefix,
     * so this is one DELETE over the unique index range, never a scan.
     *
     * @param int $cutoffMs epoch ms; rows started before this are pruned.
     * @return int how many rows the delete claimed.
     */
    public static function pruneRetired(int $cutoffMs): int {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return 0;
        }
        $result = $dbCore->queryAndGetResults(
            "DELETE FROM {wp_options} WHERE option_name >= %s AND option_name < %s "
            . "LIMIT " . self::MAX_PRUNED_PER_CALL,
            array('query_params' => array(
                self::RETIRED_PREFIX,
                self::retiredNameFor($cutoffMs, '')))
        );
        if (!empty($result['last_error'])) {
            return 0;
        }
        return ABJ_404_Solution_ExactInteger::readOr(
            $result['rows_affected'] ?? null,
            0,
            0
        );
    }

    /**
     * The retired-namespace name: prefix, 13-digit zero-padded start ms (so
     * names sort by start time and pruning is an index range), then the
     * request id. With an empty id it is the range bound for a cutoff.
     */
    private static function retiredNameFor(int $startedAtMs, string $requestId): string {
        return self::RETIRED_PREFIX . sprintf('%013d', $startedAtMs) . $requestId;
    }

    /**
     * Find one request's timeline row by its ledger id: first a retired row
     * carrying that id in its name, then a live row carrying it in its
     * timeline. A retry or client beacon arriving after its parent finished
     * (or while it is still running) is how the parent's timeline gets
     * promoted into the durable ledger.
     *
     * @param string $requestId
     * @return array{state: string, value: string} state is retired, in_flight
     *   or not_seen; value is the row's stored value, or '' when not seen.
     */
    public static function findTimeline(string $requestId): array {
        $missing = array('state' => 'not_seen', 'value' => '');
        if ($requestId === '') {
            return $missing;
        }
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return $missing;
        }
        $retired = $dbCore->queryAndGetResults(
            "SELECT option_value FROM {wp_options} WHERE option_name LIKE %s LIMIT 1",
            array('query_params' => array(self::RETIRED_PREFIX . '%' . $requestId))
        );
        $value = self::firstOptionValue($retired);
        if ($value !== null) {
            return array('state' => 'retired', 'value' => $value);
        }
        // A live in-flight row's own name carries no rid: it is minted from
        // the registering process's identity (see add()), before a request's
        // evidence is known to be worth keying on. The rid can only be
        // matched inside option_value's timeline field. Rather than a
        // `option_value LIKE '%rid%'` sweep -- unseekable on any column, and
        // doubly so on a value column with no index at all -- the in-flight
        // set is read the same bounded, sargable way readAll() already reads
        // it (option_name LIKE the literal, non-wildcard OPTION_PREFIX), and
        // matched by rid in PHP.
        $needle = '"rid":"' . $requestId . '"';
        foreach (self::readAll()['entries'] as $entry) {
            if (strpos($entry['timeline'], $needle) === false) {
                continue;
            }
            return array('state' => 'in_flight', 'value' => ABJ_404_Solution_SameSiteRequestRowCodec::encode($entry));
        }
        return $missing;
    }

    /**
     * The first row's option value from a single-value SELECT, or null when
     * the read failed or matched nothing. Case-insensitive on the column
     * name: MySQL drivers vary the case of returned column names.
     *
     * @param array<string, mixed> $result
     * @return string|null
     */
    private static function firstOptionValue(array $result): ?string {
        if (!empty($result['last_error'])) {
            return null;
        }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if ($rows === array() || !is_array($rows[0])) {
            return null;
        }
        $row = array_change_key_case($rows[0], CASE_LOWER);
        $value = $row['option_value'] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @return array{status: string, reason: string, entries: array<int, Entry>, truncated: bool}
     */
    private static function unreadable(string $reason): array {
        return array('status' => 'unavailable', 'reason' => $reason, 'entries' => array(),
            'truncated' => false);
    }

    /**
     * Read straight from the container rather than through
     * ABJ_404_Solution_Ajax_ServiceResolver, which is a two-line pass-through
     * to this same call that exists to serve the AJAX endpoint adapters. The
     * census runs on every in-scope request, not only AJAX ones, so borrowing
     * the endpoint layer's accessor was both an indirection with nothing in it
     * and a dependency pointing the wrong way: instrumentation must not need
     * the presentation surface to be loaded in order to read a service.
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
