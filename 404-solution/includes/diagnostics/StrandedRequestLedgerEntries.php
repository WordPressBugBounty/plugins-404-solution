<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The data rules of the entries held in the stranded-request ledger options.
 *
 * Split out of ABJ_404_Solution_StrandedRequestLedger, which keeps the
 * persistence: which options hold the entries, the compare-and-swap writes, the
 * locked transactional append and the reporting of newly added accounts. This
 * class is pure: it turns a reaped registry row into a bounded account, decodes
 * a stored value back into entries, normalizes a stored timeline and applies
 * the keep-both-ends trim. It reads and writes nothing, so every rule here is
 * testable without a database.
 *
 * The dependency is one-directional. This class owns the entry bounds; the
 * ledger keeps its published constants as aliases so existing callers retain
 * their API without the entry rules depending on the persistence boundary.
 */
final class ABJ_404_Solution_StrandedRequestLedgerEntries {

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
     * How many promoted timelines are kept. Twenty finished requests is far
     * past the point where the reader has the pattern, and the support
     * section sheds to the newest 8 when the whole record would not fit.
     */
    const MAX_TIMELINE_ENTRIES = 20;

    /**
     * One reaped row as a bounded account, or null when the row carries nothing
     * worth keeping.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public static function account(array $entry, ?string $eventId = null): ?array {
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
        $account = array(
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
        if (is_string($eventId) && $eventId !== '') {
            $account['source_event_id'] = substr($eventId, 0, 96);
        }
        return $account;
    }

    /**
     * Keep both ends and drop the middle. See
     * RETAINED_EARLIEST.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param int $maxEntries how many accounts survive the trim.
     * @return array<int, array<string, mixed>>
     */
    public static function trim(array $entries, int $maxEntries = self::MAX_ENTRIES): array {
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
    public static function entriesFromRaw(string $raw): array {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return array();
        }
        $entries = array();
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                if (is_array($entry['timeline'] ?? null)) {
                    $entry['timeline'] = self::normalizeTimeline($entry['timeline']);
                }
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Normalize new evidence at both ledger ingress and retrieval; old optional keys remain compatible.
     *
     * @param array<mixed, mixed> $timeline
     * @return array<string, mixed>
     */
    public static function normalizeTimeline(array $timeline): array {
        if (array_key_exists('failure_frame', $timeline)) {
            $frame = ABJ_404_Solution_RequestFailureFrame::normalize($timeline['failure_frame']);
            $timeline['failure_frame'] = $frame !== null && $frame['request_id'] === ($timeline['rid'] ?? null) ? $frame : null;
        }
        if (array_key_exists('client_observation', $timeline)) {
            $timeline['client_observation'] = is_array($timeline['client_observation'])
                ? ABJ_404_Solution_RequestFailureFrame::clientObservation($timeline['client_observation']) : null;
        }
        return $timeline;
    }
}
