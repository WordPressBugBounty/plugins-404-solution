<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The support-payload section for stranded requests: workers that are still
 * in flight far past a plausible lifetime, and workers that already were.
 *
 * This section exists because of how report 193 was actually read. Four
 * pagination workers had been stranded 121-198 seconds while their own
 * handlers had returned `status: complete` in 1.3-4.7s -- the single most
 * decisive fact in the payload -- and it reached the developer only as a
 * side effect: the census rides checkpoint records, and those particular
 * records happened to survive a 48 KiB excerpt chosen by a priority pass over
 * a 512 KiB journal that rotates against site traffic. Nothing in the feedback
 * layer read the census at all. On a busier site, or a report sent a few hours
 * later, the same evidence would simply have been gone, and the follow-up was
 * going to be asking the user to fetch journal files off their own server
 * before they rotated -- a race the user loses on a site with real traffic.
 *
 * So the finding is composed here instead, from the registry directly: a
 * bounded, self-describing block that says which requests are stranded, how
 * long they have been, and which lifecycle segment each one was inside when it
 * last managed to say so. It reads no journal, so nothing it reports can be
 * elided or rotated away, and it is small enough to ship whole every time.
 *
 * ABJ_404_Solution_SameSiteRequestCensus owns what counts as stranded and what
 * a reading contains; ABJ_404_Solution_StrandedRequestLedger owns the durable
 * account of already-reaped rows. This class owns only the payload job: asking
 * both, and rendering the answer inside a byte budget.
 */
final class ABJ_404_Solution_StrandedRequestSupportSection {

    /**
     * Hard cap on the rendered block. A bounded number of accounts, each a
     * handful of scalars, so it is small by construction; the cap keeps it so
     * regardless of how many strands a busy site accumulated. Reclaimed from
     * the checkpoint excerpt budget so the section sum stays inside the report
     * contract -- see ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES
     * and SupportExcerptBudgetContractTest.
     */
    const MAX_STRANDED_DIAG_BYTES = 8192;

    /**
     * How long a request must have been running before it is reported here.
     *
     * Well past any healthy request on the instrumented path (report 193's own
     * handlers completed in 1.3-4.7 seconds) and well under the census reap
     * threshold, so a strand is reported while its row is still live rather
     * than only after it has been reaped into the ledger.
     */
    const STRANDED_AFTER_MS = 30000;

    /** The one JSON key the block hangs under, so a reader can grep for it. */
    const STRANDED_DIAG_KEY = 'abj404_stranded_requests';

    /**
     * The whole section, ready to join into the support payload. Never throws:
     * a support request is the last thing that may be blocked by its own
     * diagnostics, so a missing class or an unreadable option degrades to a
     * stated reason rather than a fatal in the request the admin is waiting on.
     * @param array<int, string> $requestIds Authorized, normalized failures to pin.
     */
    public static function compose(array $requestIds = array()): string {
        if (!class_exists('ABJ_404_Solution_SameSiteCensusReading')
                || !class_exists('ABJ_404_Solution_StrandedRequestLedger')) {
            return 'Stranded-request diagnostics unavailable: the census classes could not be'
                . ' loaded on this install, so in-flight worker state was not read here.';
        }
        try {
            $original = json_decode($requestIds === array() ? self::retainedOriginals()
                : self::forRequests($requestIds, true), true);
            return self::render(self::record(), is_array($original) ? $original : array());
        } catch (Throwable $e) {
            return 'Stranded-request diagnostics could not be computed: '
                . substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * Render named, ALREADY retained accounts without sampling/reaping census
     * state. Safe inside AjaxFailureReporter and independently authorized
     * support retrieval. A missing frame never borrows collector identity.
     *
     * @param array<int, string> $requestIds
     */
    public static function forRequests(array $requestIds, bool $includePendingFatal = false): string {
        $entries = array_merge(ABJ_404_Solution_StrandedRequestLedger::read(),
            ABJ_404_Solution_StrandedRequestLedger::readTimelines());
        if ($includePendingFatal && class_exists('ABJ_404_Solution_CrashBeaconStore')) {
            $pending = self::pendingFatalEntry();
            if ($pending !== null) {
                $requestIds[] = $pending['request_id'];
                $entries[] = $pending['entry'];
            }
        }
        if ($requestIds === array()) { return ''; }
        $records = array();
        $ids = self::uniqueRequestIds($requestIds);
        foreach ($ids as $rawId) {
            $id = ABJ_404_Solution_AjaxRequestLedger::normalizeId($rawId, '');
            if ($id === '') { continue; }
            $records[] = self::recordForRequest($id, $entries);
        }
        $total = count($records);
        // Keep the named first request and the newest context. Automatic
        // reporting supplies exactly its named request, so it cannot be evicted.
        $records = count($records) > 2 ? array($records[0], $records[count($records) - 1]) : $records;
        do {
            $record = array('records' => $records, 'truncated' => count($records) < $total,
                'omitted_requests' => $total - count($records),
                'collector_runtime' => ABJ_404_Solution_RequestFailureFrame::runtimeIdentity());
            $json = ABJ_404_Solution_Utf8SafeRecord::encode(array('abj404_original_requests' => $record), 'original request evidence');
            // This block spends at most half of the existing section budget;
            // it does not allocate another report section or enlarge the sum.
            if (strlen($json) <= intdiv(self::MAX_STRANDED_DIAG_BYTES, 2)) { return $json; }
            array_pop($records);
        } while ($records !== array());
        return '{"abj404_original_requests":{"records":[],"truncated":true,"status":"unavailable"}}';
    }

    /**
     * @return array{request_id: string, entry: array<string, mixed>}|null
     */
    private static function pendingFatalEntry(): ?array {
        $pending = ABJ_404_Solution_CrashBeaconStore::forCurrentSite()->read();
        $beacon = $pending['beacon'];
        $fatalFrame = $beacon === null ? null : $beacon->failureFrame();
        $fatalRequestId = is_array($fatalFrame) ? ($fatalFrame['request_id'] ?? null) : null;
        if (!is_string($fatalRequestId) || $fatalRequestId === '') {
            return null;
        }
        return array(
            'request_id' => $fatalRequestId,
            'entry' => array('timeline' => array('rid' => $fatalRequestId, 'failure_frame' => $fatalFrame)),
        );
    }

    /**
     * @param array<int, mixed> $requestIds
     * @return array<int, string>
     */
    private static function uniqueRequestIds(array $requestIds): array {
        $unique = array();
        foreach ($requestIds as $requestId) {
            if (is_string($requestId)) {
                $unique[$requestId] = true;
            }
        }
        return array_keys($unique);
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<string, mixed>
     */
    private static function timelineForRequest(array $entries, string $id): array {
        $timeline = array();
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['timeline'] ?? null)) { continue; }
            $entryTimeline = $entry['timeline'];
            if (($entryTimeline['rid'] ?? null) !== $id) { continue; }
            // A later receipt can outlive the retired census row. Its missing
            // frame must not hide an original still retained for another reason.
            $frame = ABJ_404_Solution_RequestFailureFrame::normalize($entryTimeline['failure_frame'] ?? null);
            if ($frame !== null) { $timeline['failure_frame'] = $frame; }
            $rawClient = $entryTimeline['client_observation'] ?? null;
            $client = is_array($rawClient)
                ? ABJ_404_Solution_RequestFailureFrame::clientObservation($rawClient) : null;
            if ($client !== null) { $timeline['client_observation'] = $client; }
        }
        if (self::validFrameForRequest($timeline['failure_frame'] ?? null, $id)) {
            return $timeline;
        }

        // A bounded ledger can evict the original frame, and the registry may
        // retire the row before a later client receipt promotes its timeline.
        // Read only this requested id as a fallback; never broaden the lookup
        // into a census.
        $retired = self::retiredTimelineForRequest($id);
        if (is_array($retired['failure_frame'] ?? null)) {
            $timeline['failure_frame'] = $retired['failure_frame'];
        }
        // A client observation already retained in the bounded ledger remains
        // authoritative. Only carry one from the retired row when the ledger
        // had none, so the fallback cannot replace later client evidence.
        if (!isset($timeline['client_observation'])
                && is_array($retired['client_observation'] ?? null)) {
            $timeline['client_observation'] = $retired['client_observation'];
        }
        return $timeline;
    }

    /**
     * Read one exact request row from the existing bounded registry API.
     *
     * @return array<string, mixed>
     */
    private static function retiredTimelineForRequest(string $id): array {
        if ($id === '' || !class_exists('ABJ_404_Solution_SameSiteRequestRegistry')) {
            return array();
        }
        try {
            $found = ABJ_404_Solution_SameSiteRequestRegistry::findTimeline($id);
            if (!in_array($found['state'] ?? null, array('retired', 'in_flight'), true)
                    || !is_string($found['value'] ?? null) || $found['value'] === '') {
                return array();
            }
            $encoded = ABJ_404_Solution_SameSiteRequestRowCodec::timelineOfValue($found['value']);
            $decoded = ABJ_404_Solution_RequestPhaseTimeline::decode($encoded);
            if (!is_array($decoded) || ($decoded['rid'] ?? null) !== $id) {
                return array();
            }
            $frame = ABJ_404_Solution_RequestFailureFrame::normalize($decoded['failure_frame'] ?? null);
            if (!self::validFrameForRequest($frame, $id)) {
                return array();
            }
            $result = array('failure_frame' => $frame);
            $client = is_array($decoded['client_observation'] ?? null)
                ? ABJ_404_Solution_RequestFailureFrame::clientObservation($decoded['client_observation']) : null;
            if ($client !== null) {
                $result['client_observation'] = $client;
            }
            return $result;
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-support',
                'retired request frame fallback failed for ' . $id . ': '
                . substr($e->getMessage(), 0, 200));
            return array();
        }
    }

    /** @param mixed $frame */
    private static function validFrameForRequest($frame, string $id): bool {
        return is_array($frame) && ($frame['request_id'] ?? null) === $id;
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<string, mixed>
     */
    private static function recordForRequest(string $id, array $entries): array {
        $timeline = self::timelineForRequest($entries, $id);
        $frame = ABJ_404_Solution_RequestFailureFrame::normalize($timeline['failure_frame'] ?? null);
        $client = $timeline['client_observation'] ?? null;
        return array(
            'request_id' => $id,
            'status' => $frame === null ? 'unavailable' : 'available',
            'reason' => $frame === null ? 'never_reached_or_write_failed_or_expired' : '',
            'frame' => $frame,
            'client_observation' => is_array($client)
                ? ABJ_404_Solution_RequestFailureFrame::clientObservation($client) : null,
        );
    }

    /** Local read-only evidence for the existing independently authorized support/preview routes. */
    public static function retainedOriginals(): string {
        return self::forRequests(array_keys(ABJ_404_Solution_StrandedRequestLedger::condemnedRequestIds()), true);
    }

    /**
     * The finding: currently-stranded requests from the live census, plus the
     * durable accounts of ones already reaped.
     *
     * `census_status` is carried even when it is fine, because "no strands"
     * and "could not read the census" are opposite findings and a blank would
     * let a blind spot read as a healthy site.
     *
     * @return array<string, mixed>
     */
    private static function record(): array {
        $sample = ABJ_404_Solution_SameSiteCensusReading::sample();
        $status = isset($sample['status']) && is_string($sample['status'])
            ? $sample['status'] : 'unknown';
        $entries = isset($sample['entries']) && is_array($sample['entries'])
            ? $sample['entries'] : array();

        $stranded = array();
        foreach ($entries as $entry) {
            $account = is_array($entry) ? self::strandedAccount($entry) : null;
            if ($account !== null) {
                $stranded[] = $account;
            }
        }

        $reaped = ABJ_404_Solution_StrandedRequestLedger::read();
        $timelines = ABJ_404_Solution_StrandedRequestLedger::readTimelines();
        return array(
            'census_status' => $status,
            'census_reason' => isset($sample['reason']) && is_string($sample['reason'])
                ? $sample['reason'] : '',
            'in_flight_total' => isset($sample['count']) && is_int($sample['count'])
                ? $sample['count'] : -1,
            'stranded_after_ms' => self::STRANDED_AFTER_MS,
            'stranded_now' => $stranded,
            'stranded_previously' => $reaped,
            'request_timelines' => $timelines,
            'phase_meaning' => 'the lifecycle segment the request had ENTERED when it last'
                . ' recorded one; a request that died inside a segment never records the next',
        );
    }

    /**
     * One census entry as a stranded-request account, or null when the request
     * is simply still running.
     *
     * A request that is merely in flight is not a finding: reporting every one
     * would bury the strand in ordinary traffic, which is the same
     * signal-to-noise failure that made the raw journal excerpt unusable.
     *
     * Keyed loosely because that is what a census reading actually is: values
     * decoded out of options rows, whose keys this class must not assume are
     * present or well-typed. Every field it reads is validated below.
     *
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function strandedAccount(array $entry): ?array {
        $ageMs = ABJ_404_Solution_ExactInteger::readOr($entry['age_ms'] ?? null, 0, 0);
        if ($ageMs < self::STRANDED_AFTER_MS) {
            return null;
        }
        return array(
            'action' => isset($entry['action']) && is_string($entry['action'])
                ? $entry['action'] : '',
            'channel' => isset($entry['channel']) && is_string($entry['channel'])
                ? $entry['channel'] : '',
            'pid' => ABJ_404_Solution_ExactInteger::readOr($entry['pid'] ?? null, 0, 0),
            'age_ms' => $ageMs,
            'phase' => isset($entry['phase']) && is_string($entry['phase']) && $entry['phase'] !== ''
                ? $entry['phase'] : 'unrecorded',
            'timeline' => isset($entry['timeline']) && is_string($entry['timeline'])
                ? ABJ_404_Solution_RequestPhaseTimeline::decode($entry['timeline'])
                : null,
        );
    }

    /**
     * The record as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the historical accounts first -- the reducible
     * detail, since a currently-stranded worker is contemporaneous with the
     * click that sent the report -- then the older promoted timelines, then
     * falls back to the counts and phases alone, rather than being cut at a
     * byte offset. A record cut mid-JSON is unreadable by machine and
     * misleading to a human.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $original Already bounded original-request block.
     */
    private static function render(array $record, array $original = array()): string {
        $header = 'Stranded-request diagnostics -- ' . self::summary($record) . " (JSON):\n";

        $withoutHistory = $record;
        $withoutHistory['stranded_previously'] = 'over_budget';

        $trimmedTimelines = $withoutHistory;
        $trimmedTimelines['request_timelines'] =
            array_slice(self::listOf($record, 'request_timelines'), -8);

        $minimal = array(
            'census_status' => $record['census_status'],
            'in_flight_total' => $record['in_flight_total'],
            'stranded_now_count' => count(self::listOf($record, 'stranded_now')),
            'stranded_now_phases' => self::phaseTally(self::listOf($record, 'stranded_now')),
            'reduced' => 'over_budget',
        );

        foreach (array($record, $withoutHistory, $trimmedTimelines, $minimal) as $candidate) {
            $line = json_encode(array_merge($original, array(self::STRANDED_DIAG_KEY => $candidate)));
            if (is_string($line)
                    && strlen($header) + strlen($line) <= self::MAX_STRANDED_DIAG_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The stranded-request record could not be encoded for this payload.';
    }

    /**
     * The one-line version: how many are stranded right now and where they are
     * stuck, because the phase tally is the finding and the count alone is not.
     *
     * @param array<string, mixed> $record
     */
    private static function summary(array $record): string {
        $now = self::listOf($record, 'stranded_now');
        $previously = self::listOf($record, 'stranded_previously');
        if ($now === array() && $previously === array()) {
            return 'census ' . (is_string($record['census_status'] ?? null)
                ? $record['census_status'] : 'unknown') . ': no stranded requests';
        }
        $tally = self::phaseTally($now);
        $phases = array();
        foreach ($tally as $phase => $count) {
            $phases[] = $phase . ' x' . $count;
        }
        return count($now) . ' stranded now'
            . ($phases !== array() ? ' (' . implode(', ', $phases) . ')' : '')
            . ', ' . count($previously) . ' recorded previously';
    }

    /**
     * How many stranded requests sit in each lifecycle segment.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, int>
     */
    private static function phaseTally(array $entries): array {
        $tally = array();
        foreach ($entries as $entry) {
            $phase = is_array($entry) && isset($entry['phase']) && is_string($entry['phase'])
                ? $entry['phase'] : 'unrecorded';
            $tally[$phase] = isset($tally[$phase]) ? $tally[$phase] + 1 : 1;
        }
        return $tally;
    }

    /**
     * One record field as a list, or an empty list when it is absent or was
     * already shed to fit the budget.
     *
     * @param array<string, mixed> $record
     * @return array<int, array<string, mixed>>
     */
    private static function listOf(array $record, string $field): array {
        $value = $record[$field] ?? null;
        if (!is_array($value)) {
            return array();
        }
        $entries = array();
        foreach ($value as $entry) {
            if (is_array($entry)) {
                // Records are JSON objects; a numeric key is not a record field.
                $entries[] = array_filter($entry, 'is_string', ARRAY_FILTER_USE_KEY);
            }
        }
        return $entries;
    }
}
