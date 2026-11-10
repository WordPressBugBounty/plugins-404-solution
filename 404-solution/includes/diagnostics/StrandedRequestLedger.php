<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Utf8SafeRecord.php';
require_once __DIR__ . '/../database/DatabaseTransactionConnection.php';

/**
 * The durable account of requests that were never deregistered by their own
 * process -- the workers that died or stalled past any plausible lifetime.
 *
 * ABJ_404_Solution_SameSiteCensusReaper reaps those leftover rows (a census
 * reading on an admin screen, and the nightly maintenance sweep) so a dead
 * request stops being counted as a live competitor. Reaping is correct and has
 * to keep happening, but it used to be a silent DELETE that kept only a count,
 * which threw away precisely the evidence the census exists to produce: the
 * longest-stranded worker is both the most diagnostic one and the first one
 * erased. Report 193 survived only because its strands (121-198s) happened to
 * sit UNDER the reap threshold and were caught mid-flight; a longer stall --
 * the worse bug -- would have left nothing but `stale_reaped: 4`.
 *
 * So a row is promoted into this ledger before it is deleted. What is kept is
 * an ACCOUNT, not a record stream: which lifecycle segment the request was
 * inside when it last managed to write one, how old it had got, and which
 * process held it. That is small enough to ship whole in a support payload,
 * which is the property that matters -- the journals it replaces for this
 * question are byte-capped, rotate against site traffic, and get sampled by a
 * priority pass, so on a busy site the answer can be gone before the admin
 * clicks "send". This is written once per reaped row, bounded, and read back
 * verbatim.
 *
 * Storage is a single non-autoloaded option rather than a file: the payload is
 * assembled from the database anyway, and the uploads directory is the one
 * place a shared host is most likely to have made unwritable.
 *
 * What an entry looks like and how the ledger is trimmed -- the data rules --
 * belong to ABJ_404_Solution_StrandedRequestLedgerEntries; this class owns the
 * options that hold the entries and the writes to them.
 *
 * @phpstan-import-type NativeResult from ABJ_404_Solution_DatabaseTransactionConnection
 */
final class ABJ_404_Solution_StrandedRequestLedger {

    /** The option holding the whole ledger. Non-autoloaded; read only on demand. */
    const OPTION_NAME = 'abj404_stranded_requests';

    /** MAX_ENTRIES is owned by the ledger entry rules. */
    const MAX_ENTRIES = ABJ_404_Solution_StrandedRequestLedgerEntries::MAX_ENTRIES;

    /** RETAINED_EARLIEST is owned by the ledger entry rules. */
    const RETAINED_EARLIEST = ABJ_404_Solution_StrandedRequestLedgerEntries::RETAINED_EARLIEST;

    /**
     * The option holding the promoted request timelines. Non-autoloaded; read
     * only on demand. A second ledger beside the stranded accounts rather
     * than a parallel store: same cap, same keep-both-ends trim, same write
     * path.
     */
    const TIMELINE_OPTION_NAME = 'abj404_request_timelines';

    /** MAX_TIMELINE_ENTRIES is owned by the ledger entry rules. */
    const MAX_TIMELINE_ENTRIES = ABJ_404_Solution_StrandedRequestLedgerEntries::MAX_TIMELINE_ENTRIES;

    /** Promotion reasons: why a finished request's timeline was kept. */
    const REASON_SLOW = 'slow';
    const REASON_LATE_START = 'late_start';
    const REASON_RETRY = 'retry';
    const REASON_RETRY_PARENT = 'retry_parent';
    const REASON_CLIENT_REPORTED = 'client_reported';

    /**
     * Promote reaped registry rows into the ledger, newest last. Never throws:
     * a census reading must not fail because its own bookkeeping could not be
     * written.
     *
     * Takes loosely-typed decoded registry rows on purpose: the caller is
     * handing over whatever came back out of an options row, and the entry rules
     * decide which of it is usable. A stricter parameter type
     * here would only move that validation to a caller that has no better
     * information than this one does.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return int how many accounts were added.
     */
    public static function record(array $entries): int {
        if ($entries === array()) {
            return 0;
        }
        try {
            $added = array();
            foreach ($entries as $entry) {
                $account = ABJ_404_Solution_StrandedRequestLedgerEntries::account($entry);
                if ($account !== null) {
                    $added[] = $account;
                }
            }
            if ($added === array()) {
                return 0;
            }
            $result = self::append(self::OPTION_NAME, static function (array $existing) use ($added): array {
                return ABJ_404_Solution_StrandedRequestLedgerEntries::trim(array_merge($existing, $added));
            });
            if ($result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED) {
                return 0;
            }
            self::reportAddedAccounts($added);
            return count($added);
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request record failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Append one handoff while the caller owns the pinned InnoDB transaction.
     * The ledger row is locked before its bounded keep-both-ends rewrite, so
     * the source event identity is authoritative for the whole transaction.
     *
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param array<string,mixed> $entry
     * @return 'recorded'|'already_durable'|'unavailable'
     */
    public static function recordHandoffInTransaction(
        ABJ_404_Solution_DatabaseTransactionConnection $connection,
        array $entry,
        string $eventId,
        string $table,
        object $wpdb
    ): string {
        $account = ABJ_404_Solution_StrandedRequestLedgerEntries::account($entry, $eventId);
        if ($account === null || $eventId === '' || !is_callable(array($wpdb, 'prepare'))) {
            return 'unavailable';
        }
        $select = self::transactionSql($wpdb,
            "SELECT option_value FROM `" . $table . "` "
                . "WHERE option_name = %s AND " . ABJ_404_Solution_StoredValueMatch::equals('option_name', '%s') . " FOR UPDATE",
            array(self::OPTION_NAME, self::OPTION_NAME));
        $result = $connection->execute($select);
        self::requireTransactionResult($result, 'The stranded-request ledger could not be locked.');
        $existingRaw = null;
        if (count($result['rows']) > 1) {
            throw self::transactionFailure('The stranded-request ledger returned duplicate rows.');
        }
        if (count($result['rows']) === 1) {
            $row = $result['rows'][0];
            if (!is_array($row) || !array_key_exists('option_value', $row)
                    || !is_scalar($row['option_value'])) {
                throw self::transactionFailure('The stranded-request ledger row is malformed.');
            }
            $existingRaw = (string)$row['option_value'];
        }
        $existing = ABJ_404_Solution_StrandedRequestLedgerEntries::entriesFromRaw($existingRaw ?? '');
        foreach ($existing as $stored) {
            if (($stored['source_event_id'] ?? '') === $eventId) {
                return 'already_durable';
            }
        }
        $encoded = ABJ_404_Solution_Utf8SafeRecord::encode(
            ABJ_404_Solution_StrandedRequestLedgerEntries::trim(array_merge($existing, array($account))),
            'stranded request ledger'
        );
        if ($encoded === '') {
            throw self::transactionFailure('The stranded-request ledger could not be encoded.');
        }
        if ($existingRaw === null) {
            $write = self::transactionSql($wpdb,
                "INSERT INTO `" . $table . "` (option_name, option_value, autoload) "
                    . "VALUES (%s, %s, 'no')", array(self::OPTION_NAME, $encoded));
        } else {
            $write = self::transactionSql($wpdb,
                "UPDATE `" . $table . "` SET option_value = %s "
                    . "WHERE option_name = %s AND " . ABJ_404_Solution_StoredValueMatch::equals('option_name', '%s') . " "
                    . "AND " . ABJ_404_Solution_StoredValueMatch::equals('option_value', '%s'),
                array($encoded, self::OPTION_NAME, self::OPTION_NAME, $existingRaw));
        }
        $written = $connection->execute($write);
        self::requireTransactionResult($written, 'The stranded-request ledger append failed.');
        if ($written['rows_affected'] !== 1) {
            throw self::transactionFailure('The stranded-request ledger append was not acknowledged.');
        }
        return 'recorded';
    }

    /**
     * Report only after a transaction that newly appended this account commits.
     * @param array<string,mixed> $entry
     */
    public static function reportCommittedHandoff(array $entry): void {
        $account = ABJ_404_Solution_StrandedRequestLedgerEntries::account($entry, null);
        if ($account !== null) {
            self::reportAddedAccounts(array($account));
        }
    }

    /**
     * Every retained account, oldest first. Never throws; an unreadable or
     * malformed ledger reports as empty rather than propagating into whatever
     * asked for it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function read(): array {
        return self::readOption(self::OPTION_NAME);
    }

    /**
     * Every retained timeline promotion, oldest first. Same never-throws
     * contract as read(): entries are array{reason, state, timeline}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function readTimelines(): array {
        return self::readOption(self::TIMELINE_OPTION_NAME);
    }

    /**
     * Promote one request's timeline into the ledger. Deduplicated on
     * request id plus reason, so a retry and a beacon naming the same parent
     * for the same reason keep one entry. Never throws.
     *
     * @param array<string, mixed> $timeline the timeline shape, or a
     *   v/rid stub when the named request was never seen.
     * @param array{reason: string, state?: string} $promotion keyed because
     *   both are strings and a swap would store the state as the reason,
     *   which condemnedRequestIds() treats as a non-retry reason. `reason` is
     *   one of the REASON_* constants; `state` is retired (the default),
     *   in_flight or not_seen: where the named row was when it was promoted.
     * @return bool whether an entry was recorded.
     */
    public static function recordTimeline(array $timeline, array $promotion): bool {
        $timeline = ABJ_404_Solution_StrandedRequestLedgerEntries::normalizeTimeline($timeline);
        $reason = $promotion['reason'];
        $state = $promotion['state'] ?? 'retired';
        $rid = isset($timeline['rid']) && is_string($timeline['rid']) ? $timeline['rid'] : '';
        if ($rid === '' || $reason === '') {
            return false;
        }
        try {
            $result = self::append(self::TIMELINE_OPTION_NAME,
                static function (array $existing) use ($rid, $reason, $state, $timeline): ?array {
                    foreach ($existing as $entry) {
                        if (isset($entry['dropped_middle_accounts'])) {
                            continue;
                        }
                        $entryTimeline = isset($entry['timeline']) && is_array($entry['timeline'])
                            ? $entry['timeline'] : array();
                        $entryRid = isset($entryTimeline['rid']) && is_string($entryTimeline['rid'])
                            ? $entryTimeline['rid'] : '';
                        if ($entryRid === $rid && ($entry['reason'] ?? '') === $reason) {
                            return null;
                        }
                    }
                    $existing[] = array(
                        'reason' => substr($reason, 0, 32),
                        'state' => substr($state, 0, 16),
                        'timeline' => $timeline,
                    );
                    return ABJ_404_Solution_StrandedRequestLedgerEntries::trim($existing, self::MAX_TIMELINE_ENTRIES);
                });
            return $result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED;
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request timeline record failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Every request id condemned anywhere in either ledger: every promoted
     * timeline except a retry-only one (a retry is routine traffic until
     * something else condemns it), plus every stranded account whose row
     * carried a timeline. The support excerpts union this into the failure
     * index they rank on.
     *
     * @return array<string, bool>
     */
    public static function condemnedRequestIds(): array {
        $ids = array();
        try {
            foreach (self::readTimelines() as $entry) {
                if (!is_array($entry) || isset($entry['dropped_middle_accounts'])) {
                    continue;
                }
                $reason = isset($entry['reason']) && is_string($entry['reason'])
                    ? $entry['reason'] : '';
                if ($reason === '' || $reason === self::REASON_RETRY) {
                    continue;
                }
                $rid = self::timelineRid($entry);
                if ($rid !== '') {
                    $ids[$rid] = true;
                }
            }
            foreach (self::read() as $account) {
                $rid = self::timelineRid($account);
                if ($rid !== '') {
                    $ids[$rid] = true;
                }
            }
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request condemned ids failed (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
        return $ids;
    }

    /**
     * The request id one ledger entry's timeline names, or '' when it names
     * none. A gap marker carries no timeline, so it condemns nothing.
     *
     * @param mixed $entry
     * @return string
     */
    private static function timelineRid($entry): string {
        if (!is_array($entry)) {
            return '';
        }
        $timeline = isset($entry['timeline']) && is_array($entry['timeline'])
            ? $entry['timeline'] : array();
        $rid = $timeline['rid'] ?? null;
        return is_string($rid) ? $rid : '';
    }

    /** Forget every account. For uninstall and for tests that need a clean slate. */
    public static function clear(): void {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_NAME);
            delete_option(self::TIMELINE_OPTION_NAME);
        }
    }

    /**
     * One ledger option's entries, oldest first. Never throws.
     *
     * @param string $optionName
     * @return array<int, array<string, mixed>>
     */
    private static function readOption(string $optionName): array {
        try {
            if (!function_exists('get_option')) {
                return array();
            }
            $raw = ABJ_404_Solution_OptionShapeRead::scalar($optionName, '');
            return ABJ_404_Solution_StrandedRequestLedgerEntries::entriesFromRaw(
                is_string($raw) ? $raw : ''
            );
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request read failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Report newly-added accounts once; duplicate handoffs never reach here.
     * @param array<int,array<string,mixed>> $accounts
     */
    private static function reportAddedAccounts(array $accounts): void {
        foreach ($accounts as $account) {
            if (($account['channel'] ?? '') !== 'ajax') {
                continue;
            }
            $timeline = is_array($account['timeline']) ? $account['timeline'] : array();
            ABJ_404_Solution_AjaxFailureReporter::record(array(
                'kind' => 'stranded',
                'action' => $account['action'],
                'phase' => $account['phase'],
                'age_ms' => $account['age_ms_at_reap'],
                'subpage' => $timeline['subpage'] ?? '',
                'request_id' => $timeline['rid'] ?? '',
                'retry_count' => $timeline['rc'] ?? 0,
            ));
        }
    }

    /**
     * Change one ledger option from the value the database holds NOW.
     * Concurrent requests append to the same ledger, so this goes through
     * compare-and-swap: a lost race repeats $next on the fresh entries rather
     * than overwriting another request's account.
     *
     * @param string $optionName
     * @param callable(array<int, array<string, mixed>>): (array<int, array<string, mixed>>|null) $next
     *   the entries to store given the current ones, or null to store nothing.
     * @return string an OptionRowCompareAndSwap RESULT_* constant.
     */
    private static function append(string $optionName, callable $next): string {
        return ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => $optionName,
            'compute' => static function (?string $current) use ($next): ?string {
                $entries = call_user_func(
                    $next,
                    ABJ_404_Solution_StrandedRequestLedgerEntries::entriesFromRaw($current ?? '')
                );
                if ($entries === null) {
                    return null;
                }
                $encoded = ABJ_404_Solution_Utf8SafeRecord::encode(array_values($entries), 'stranded request ledger');
                return $encoded === '' ? null : $encoded;
            },
        ));
    }

    /**
     * @param object $wpdb WordPress-compatible database envelope.
     * @param array<int,mixed> $params
     */
    private static function transactionSql(object $wpdb, string $sql, array $params): string {
        $prepare = array($wpdb, 'prepare');
        if (!is_callable($prepare)) {
            throw self::transactionFailure('The stranded-request ledger SQL preparer is unavailable.');
        }
        $prepared = Closure::fromCallable($prepare)($sql, ...$params);
        if (!is_string($prepared) || $prepared === '') {
            throw self::transactionFailure('The stranded-request ledger SQL could not be prepared.');
        }
        return $prepared;
    }

    /** @param NativeResult $result */
    private static function requireTransactionResult(array $result, string $message): void {
        if ($result['query_succeeded'] !== true || $result['last_error'] !== '') {
            throw self::transactionFailure($result['last_error'] !== ''
                ? $result['last_error'] : $message);
        }
    }

    private static function transactionFailure(string $message): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, $message);
    }
}
