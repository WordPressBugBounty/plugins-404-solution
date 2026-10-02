<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The browser's drained transport-attempt buffer: what it says, and how it
 * fits inside a byte budget without being destroyed.
 *
 * Split from ABJ_404_Solution_ClientTransportReport, which keeps the other
 * half of the client's evidence: a single report riding one request. This
 * class is the pure functions over the raw drained buffer the support-request
 * handler receives -- the buffer is a JSON array of per-attempt records, not
 * a request parameter, and every rule about what it is lives here so the
 * payload composer (ABJ_404_Solution_SupportEvidenceExcerpt) owns none of
 * them.
 *
 * The support payload is the one place both halves of the request ledger
 * meet, so "the browser is reporting attempt X and the collected journals
 * never mention X" is a decisive fact about the COLLECTION rather than the
 * request -- which is why the attempt ids are read here BEFORE the buffer is
 * bounded down to fit the payload.
 */
final class ABJ_404_Solution_DrainedTelemetryBuffer {

    /**
     * Hard bound on the raw drained buffer BEFORE it is parsed. Only an input
     * guard against an absurd POST; the shipping bound is the caller's budget.
     */
    const MAX_DRAINED_BUFFER_INPUT_BYTES = 131072;

    /**
     * The only attempt outcome that means "did not fail". An allowlist, not a
     * deny-list: 'pending' is an attempt that never finished (the hung request
     * itself) and an unrecognised or absent outcome is an unknown, which is
     * worth more than a known success when something has to be dropped.
     */
    const HEALTHY_OUTCOMES = array('success');

    /**
     * Attempt ids carried into the support-collection manifest. The browser's
     * own ring buffer holds 16 records, so this is that ceiling plus headroom
     * for a buffer that arrives from an older or a modified client.
     */
    const MAX_ATTEMPT_IDS_REPORTED = 32;

    /**
     * The attempt outcomes the browser says its drained buffer describes.
     *
     * The three statuses are kept distinct on purpose: "the browser sent
     * nothing" and "the browser sent something we could not read" are
     * different findings, and collapsing the second into an empty id list is
     * the same silent-empty defect the collection manifest exists to end.
     *
     * A failure is sticky across duplicate records. Browser storage is a
     * ring buffer and a retry can leave more than one account of an attempt;
     * a later success must not erase an earlier timeout, and a later timeout
     * must still override an earlier success. Only the explicit `success`
     * outcome is healthy, matching the ranking rules used after journaling.
     *
     * @param string $raw The raw POSTed buffer, already unslashed.
     * @return array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>}
     *   status: `absent`, `unparseable`, or `parsed`.
     */
    public static function attemptOutcomes(string $raw): array {
        if ($raw === '') {
            return array(
                'status' => 'absent', 'ids' => array(), 'records' => 0, 'outcomes' => array(),
            );
        }
        $boundedRaw = substr($raw, 0, self::MAX_DRAINED_BUFFER_INPUT_BYTES);
        $decoded = json_decode($boundedRaw, true);
        if (!is_array($decoded) || !self::isJsonArrayDocument($boundedRaw)) {
            return array(
                'status' => 'unparseable', 'ids' => array(), 'records' => 0, 'outcomes' => array(),
            );
        }
        $ids = array();
        $outcomes = array();
        foreach ($decoded as $record) {
            if (!is_array($record) || !isset($record['id']) || !is_scalar($record['id'])) {
                continue;
            }
            $id = (string)$record['id'];
            // The wire contract's own request-id shape. An id that cannot be a
            // server request id cannot be reconciled against one, and letting
            // arbitrary browser text into the manifest would put an unbounded
            // string in a bounded record.
            if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $id) !== 1) {
                continue;
            }
            $ids[$id] = true;
            $outcome = isset($record['outcome']) && is_scalar($record['outcome'])
                ? (string)$record['outcome'] : '';
            $healthy = in_array($outcome, self::HEALTHY_OUTCOMES, true);
            if (!array_key_exists($id, $outcomes) || !$healthy) {
                $outcomes[$id] = $healthy;
            }
        }
        return array(
            'status' => 'parsed',
            'ids' => array_slice(array_keys($ids), 0, self::MAX_ATTEMPT_IDS_REPORTED),
            'records' => count($decoded),
            'outcomes' => $outcomes,
        );
    }

    /**
     * Fit the browser's drained attempt buffer inside a byte budget WITHOUT
     * destroying it.
     *
     * The buffer can exceed what the support payload will carry: the browser
     * store holds up to 16 records / 48 KB. Cutting the serialized array at a
     * byte offset -- which is what both ends used to do -- leaves invalid
     * JSON, so an overflowing buffer arrived as "unparseable" and EVERY
     * attempt was lost rather than the least interesting one. That is the
     * same defect the journal excerpt had, on the one channel that can
     * describe attempts the server never saw at all.
     *
     * So whole records are dropped, not bytes, and the ones kept are chosen:
     * attempts that did not succeed first (oldest first, because the first
     * failure is the one without retry effects), then the rest newest first.
     *
     * A buffer whose records carry bounded body excerpts (support report 521)
     * has those excerpts redacted here, before the JSON can be embedded in
     * the support payload; see
     * ABJ_404_Solution_ClientTransportReport::redactReportBodyExcerpt().
     *
     * @param string $raw The raw POSTed buffer.
     * @param int $budgetBytes Ceiling for the returned JSON.
     * @return array{json: string, parsed: bool, kept: int, dropped: int, raw_length: int, error: string}
     */
    public static function bound(string $raw, int $budgetBytes): array {
        $rawLength = strlen($raw);
        $unparseable = array(
            'json' => '', 'parsed' => false, 'kept' => 0, 'dropped' => 0,
            'raw_length' => $rawLength, 'error' => '',
        );
        if ($raw === '') {
            return $unparseable;
        }
        $boundedRaw = substr($raw, 0, self::MAX_DRAINED_BUFFER_INPUT_BYTES);
        $decoded = json_decode($boundedRaw, true);
        if (!is_array($decoded) || !self::isJsonArrayDocument($boundedRaw)) {
            $unparseable['error'] = is_array($decoded)
                ? 'expected a JSON array of attempt records'
                : json_last_error_msg();
            return $unparseable;
        }
        $records = array();
        $carriesExcerpts = false;
        foreach ($decoded as $record) {
            $carriesExcerpts = $carriesExcerpts
                || (is_array($record) && array_key_exists('bodyExcerpt', $record));
            $records[] = $record;
        }
        if ($carriesExcerpts) {
            // Excerpts are response TEXT, so they are redacted before this
            // buffer can be embedded in the support payload, and the raw
            // verbatim shortcut below is skipped: only records that carry an
            // excerpt pay for the redaction pass, older clients keep their
            // exact bytes.
            $records = array_map(
                static function ($record) {
                    return is_array($record)
                        ? ABJ_404_Solution_ClientTransportReport::redactReportBodyExcerpt($record)
                        : $record;
                },
                $records);
        } elseif ($rawLength <= self::MAX_DRAINED_BUFFER_INPUT_BYTES
                && $rawLength <= $budgetBytes) {
            return array(
                'json' => $raw, 'parsed' => true, 'kept' => count($records), 'dropped' => 0,
                'raw_length' => $rawLength, 'error' => '',
            );
        }

        $kept = self::keepWithinBudget($records, $budgetBytes);
        $json = json_encode($kept, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > $budgetBytes) {
            $unparseable['error'] = 'buffer could not be reduced to the support budget';
            return $unparseable;
        }
        return array(
            'json' => $json, 'parsed' => true, 'kept' => count($kept),
            'dropped' => count($records) - count($kept), 'raw_length' => $rawLength, 'error' => '',
        );
    }

    /**
     * Whether decoded JSON came from the buffer's required top-level array.
     *
     * Associative decoding turns both JSON objects and arrays into PHP arrays,
     * so the decoded type alone cannot enforce the wire contract. Inspecting
     * the first non-whitespace byte keeps a valid object from being reported as
     * a successfully parsed empty attempt list.
     */
    private static function isJsonArrayDocument(string $raw): bool {
        return substr(ltrim($raw), 0, 1) === '[';
    }

    /**
     * The records that fit, in their original order, failures first.
     *
     * @param array<int, mixed> $records
     * @return array<int, mixed>
     */
    private static function keepWithinBudget(array $records, int $budgetBytes): array {
        $failed = array();
        $healthy = array();
        foreach ($records as $position => $record) {
            $outcome = is_array($record) && isset($record['outcome']) && is_scalar($record['outcome'])
                ? (string)$record['outcome'] : '';
            if (in_array($outcome, self::HEALTHY_OUTCOMES, true)) {
                $healthy[] = $position;
            } else {
                $failed[] = $position;
            }
        }
        $order = array_merge($failed, array_reverse($healthy));

        // Two brackets and the commas between the records; charged up front so
        // the encoded result cannot creep past the budget on the last record.
        $used = 2;
        $keepPositions = array();
        foreach ($order as $position) {
            $encoded = json_encode($records[$position], JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }
            $cost = strlen($encoded) + ($keepPositions === array() ? 0 : 1);
            if ($used + $cost > $budgetBytes) {
                continue;
            }
            $used += $cost;
            $keepPositions[] = $position;
        }
        sort($keepPositions);

        $kept = array();
        foreach ($keepPositions as $position) {
            $kept[] = $records[$position];
        }
        return $kept;
    }
}
