<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The durable account of requests that were never deregistered by their own
 * process -- the workers that died or stalled past any plausible lifetime.
 *
 * ABJ_404_Solution_SameSiteRequestCensus reaps those leftover rows so a dead
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
 */
final class ABJ_404_Solution_StrandedRequestLedger {

    /** The option holding the whole ledger. Non-autoloaded; read only on demand. */
    const OPTION_NAME = 'abj404_stranded_requests';

    /**
     * How many accounts are kept.
     *
     * Small on purpose. This has to fit whole inside a support payload without
     * competing with the journal excerpts for their byte budget, and twenty
     * strands is already far past the point where the reader has the pattern.
     */
    const MAX_ENTRIES = 20;

    /**
     * How many of the EARLIEST accounts are never evicted.
     *
     * A plain ring keeps the newest and loses the first, which is backwards for
     * this evidence: the first strands on an install happened before retries,
     * warmed caches and an already-degraded host could confound them, so they
     * are the cleanest single account of the failure. The newest matter too --
     * they are contemporaneous with the click that sent the report -- so the
     * ledger keeps both ends and drops the middle, which is the part that only
     * repeats what the two ends already say.
     */
    const RETAINED_EARLIEST = 6;

    /**
     * The option holding the promoted request timelines. Non-autoloaded; read
     * only on demand. A second ledger beside the stranded accounts rather
     * than a parallel store: same cap, same keep-both-ends trim, same write
     * path.
     */
    const TIMELINE_OPTION_NAME = 'abj404_request_timelines';

    /**
     * How many promoted timelines are kept. Twenty finished requests is far
     * past the point where the reader has the pattern, and the support
     * section sheds to the newest 8 when the whole record would not fit.
     */
    const MAX_TIMELINE_ENTRIES = 20;

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
     * handing over whatever came back out of an options row, and account()
     * below is what decides which of it is usable. A stricter parameter type
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
                $account = self::account($entry);
                if ($account !== null) {
                    $added[] = $account;
                }
            }
            if ($added === array()) {
                return 0;
            }
            $result = self::append(self::OPTION_NAME, static function (array $existing) use ($added): array {
                return self::trim(array_merge($existing, $added));
            });
            return $result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED ? count($added) : 0;
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request record failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return 0;
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
                    return self::trim($existing, self::MAX_TIMELINE_ENTRIES);
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
            $raw = get_option($optionName, '');
            return self::entriesFromRaw(is_string($raw) ? $raw : '');
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request read failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /**
     * One reaped row as a bounded account, or null when the row carries nothing
     * worth keeping.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function account(array $entry): ?array {
        $pid = ABJ_404_Solution_ExactInteger::readOr($entry['pid'] ?? null, 0, 0);
        $ageMs = ABJ_404_Solution_ExactInteger::readOr($entry['age_ms'] ?? null, 0, 0);
        if ($pid === 0 && $ageMs === 0) {
            return null;
        }
        $phase = isset($entry['phase']) && is_string($entry['phase']) && $entry['phase'] !== ''
            ? substr($entry['phase'], 0, 32)
            // A row written before phases existed, or by a request that died
            // before its first transition. Named rather than blank, so it is
            // never read as "reached no phase".
            : 'unrecorded';
        return array(
            'channel' => isset($entry['channel']) && is_string($entry['channel'])
                ? substr($entry['channel'], 0, 16) : '',
            'action' => isset($entry['action']) && is_string($entry['action'])
                ? substr($entry['action'], 0, 64) : '',
            'pid' => $pid,
            'phase' => $phase,
            'started_at_ms' => ABJ_404_Solution_ExactInteger::readOr(
                $entry['started_at_ms'] ?? null,
                0,
                0
            ),
            'age_ms_at_reap' => $ageMs,
            // The row's own phase timeline, decoded: the worst strand on the
            // site keeps its record of where it spent its time. Null when the
            // row predates timelines or carried none.
            'timeline' => isset($entry['timeline']) && is_string($entry['timeline'])
                ? ABJ_404_Solution_RequestPhaseTimeline::decode($entry['timeline'])
                : null,
        );
    }

    /**
     * Keep both ends and drop the middle. See RETAINED_EARLIEST.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param int $maxEntries how many accounts survive the trim.
     * @return array<int, array<string, mixed>>
     */
    private static function trim(array $entries, int $maxEntries = self::MAX_ENTRIES): array {
        // The gap marker is NOT an account and must never occupy a slot or be
        // re-counted. Folding prior markers back into one running total first
        // is what keeps the ledger at MAX_ENTRIES accounts with exactly one
        // marker, instead of growing by one marker per trim.
        $dropped = 0;
        $accounts = array();
        foreach ($entries as $entry) {
            if (isset($entry['dropped_middle_accounts'])) {
                // A hand-edited or truncated option can put anything here. A
                // non-numeric marker still means "accounts were dropped", so it
                // is kept as a marker and counted as at least one rather than
                // silently becoming zero.
                $dropped += ABJ_404_Solution_ExactInteger::readOr(
                    $entry['dropped_middle_accounts'],
                    0,
                    1
                );
                continue;
            }
            $accounts[] = $entry;
        }

        if (count($accounts) > $maxEntries) {
            $earliest = array_slice($accounts, 0, self::RETAINED_EARLIEST);
            $newest = array_slice($accounts, -($maxEntries - self::RETAINED_EARLIEST));
            $dropped += count($accounts) - count($earliest) - count($newest);
        } else {
            $earliest = array_slice($accounts, 0, self::RETAINED_EARLIEST);
            $newest = array_slice($accounts, self::RETAINED_EARLIEST);
        }

        if ($dropped === 0) {
            return array_merge($earliest, $newest);
        }
        // The gap is stated in the ledger itself. A reader who cannot see that
        // accounts were dropped would read the two ends as one continuous
        // history, which is the same "looks complete, is 3% of it" failure the
        // journal excerpt summary exists to prevent.
        return array_merge($earliest, array(array('dropped_middle_accounts' => $dropped)), $newest);
    }

    /**
     * The entries a stored ledger value holds, oldest first; anything
     * unreadable is an empty ledger.
     *
     * @param string $raw
     * @return array<int, array<string, mixed>>
     */
    private static function entriesFromRaw(string $raw): array {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return array();
        }
        $entries = array();
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
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
                $entries = call_user_func($next, self::entriesFromRaw($current ?? ''));
                if ($entries === null) {
                    return null;
                }
                $encoded = ABJ_404_Solution_Utf8SafeRecord::encode(array_values($entries), 'stranded request ledger');
                return $encoded === '' ? null : $encoded;
            },
        ));
    }
}
