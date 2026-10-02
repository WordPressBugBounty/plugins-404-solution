<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The browser's half of the request ledger (Bruno timeout cause matrix,
 * coverage req. 6).
 *
 * The server flight recorder can prove what PHP did with a request. It cannot
 * prove that the request ever left the browser, how long it sat in the browser
 * connection queue, whether response headers arrived and the body then stalled,
 * or whether the completion callback lost a race to jQuery's timeout timer.
 * Only the browser can answer those, and only if what it observed gets back
 * here. This class is where it lands.
 *
 * Two arrival routes, both authenticated exactly like a normal table request:
 *
 *   1. Riding the next table request's params (the primary route: the client
 *      attaches its previous attempt's record to the following request, so the
 *      evidence arrives even if the admin never sends a support request).
 *   2. A clientReportOnly beacon fired after the last attempt of a failed
 *      request, which does no table work and returns immediately.
 *
 * Reports are journaled through ABJ_404_Solution_AjaxCheckpointLogger rather
 * than the trace class, for the same reason the server checkpoints are: a
 * defect in the component under investigation must not be able to erase the
 * evidence about it. The payload is treated as untrusted text throughout: it
 * is length-bounded, parsed defensively, and never echoed back to any client.
 *
 * The browser's DRAINED attempt buffer (what a support request POSTs whole)
 * is not a request parameter and has its own rules; that half lives in
 * ABJ_404_Solution_DrainedTelemetryBuffer.
 */
final class ABJ_404_Solution_ClientTransportReport {

    /**
     * Hard bound on a single client report. The client trims its own record to
     * 4000 characters before sending; this is the server refusing to journal
     * more than that regardless of what actually arrives.
     */
    const MAX_REPORT_BYTES = 4096;

    /**
     * The endpoint a response part belongs to, for the one question that has
     * to be answered from the part alone: a report-only beacon always travels
     * on the table action, so when a health bar attempt fails to parse, the
     * part is the only field naming the endpoint whose body was not JSON.
     * Unmapped parts (table, counts, pagination, absent, unrecognized) are
     * the table endpoint, the action the beacon itself arrives on.
     */
    const PARSE_FAILURE_ACTION_BY_PART = array(
        'health' => 'ajaxRefreshHealthBar',
    );

    const PARSE_FAILURE_DEFAULT_ACTION = 'ajaxUpdatePaginationLinks';

    /**
     * Read, bound, and journal whatever the browser said about a previous
     * attempt, plus the build identity of the JavaScript that said it. Never
     * throws: a malformed or absent report must not affect the request that
     * carried it.
     */
    public static function journal(string $requestId): void {
        try {
            $reader = self::requestReader();
            $build = (string)$reader->getPostOrGetSanitize('clientBuild', '');
            $buildModules = (string)$reader->getPostOrGetSanitize('clientBuildModules', '');
            $inflight = (string)$reader->getPostOrGetSanitize('clientInflight', '');
            $tabs = (string)$reader->getPostOrGetSanitize('clientTabs', '');
            $foreignInflight = (string)$reader->getPostOrGetSanitize('clientForeignInflight', '');
            $storageHealth = (string)$reader->getPostOrGetSanitize('clientStorageHealth', '');
            if ($build !== '' || $inflight !== '' || $tabs !== '' || $foreignInflight !== '' ||
                    $storageHealth !== '') {
                // What the client said about ITSELF at send time: which
                // JavaScript is executing, how many other plugin requests that
                // tab already had open, how many admin tabs of the page are
                // open at all, and how much non-plugin AJAX the tab had
                // outstanding. The last two are the browser's half of the
                // same-site contention the server counts in
                // ABJ_404_Solution_SameSiteRequestCensus; neither used to be
                // recorded anywhere, so a cross-tab cause could not even be
                // suspected from the evidence that survived. The previous
                // attempt's story is a separate record below.
                ABJ_404_Solution_AjaxCheckpointLogger::record(
                    $requestId,
                    'client_send_state',
                    array_merge(
                        ABJ_404_Solution_ClientBuildFingerprint::compare($build, $buildModules),
                        array(
                            'inflight' => ctype_digit($inflight) ? (int)$inflight : null,
                            'inflight_ids' => substr(
                                (string)$reader->getPostOrGetSanitize('clientInflightIds', ''), 0, 256),
                            // -1 is the client's own "could not observe this",
                            // and it is preserved rather than folded into null:
                            // an unobservable channel and an absent parameter
                            // are different findings about the client.
                            'open_tabs' => self::signedCountOrNull($tabs),
                            'foreign_inflight' => self::signedCountOrNull($foreignInflight),
                            'storage_health' => self::parseStorageHealth($storageHealth),
                        )
                    )
                );
            }
            $report = self::readReport($reader);
            if ($report === null) {
                return;
            }
            // Nested under one key, never spread across the record: the
            // envelope's own fields (request_id, event, ts, pid) are the join
            // keys the whole journal is read by, and a client that sent a
            // field with one of those names would otherwise overwrite them and
            // forge the identity of its own evidence.
            if (ABJ_404_Solution_ConcurrentControlReceipt::isBrowserReceipt($report)) {
                ABJ_404_Solution_ConcurrentControlReceipt::journal(array(
                    'carrierRequestId' => $requestId,
                    'sessionId' => (string)ABJ_404_Solution_AjaxRequestLedger::readFields($reader)['session_id'],
                    'report' => $report,
                ));
                return;
            }
            ABJ_404_Solution_AjaxCheckpointLogger::record(
                $requestId, 'client_prior_attempt',
                array('report' => self::redactReportBodyExcerpt($report)));
        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'client_report_error', array(
                'message' => substr($e->getMessage(), 0, 200),
            ));
        }
    }

    /**
     * The action whose body a client report says failed to parse, or '' when
     * it describes no parse failure. The verdict is jQuery's own word
     * ('parsererror' as jq or outcome); a timeout says nothing about JSON
     * delivery and must not arm anything. The part names the endpoint, see
     * PARSE_FAILURE_ACTION_BY_PART. Bounded like readReport(): untrusted text.
     */
    public static function parseFailureActionInReport(string $raw): string {
        $decoded = json_decode(substr($raw, 0, self::MAX_REPORT_BYTES), true);
        if (!is_array($decoded)) {
            return '';
        }
        $jq = isset($decoded['jq']) && is_scalar($decoded['jq']) ? (string)$decoded['jq'] : '';
        $outcome = isset($decoded['outcome']) && is_scalar($decoded['outcome'])
            ? (string)$decoded['outcome'] : '';
        if ($jq !== 'parsererror' && $outcome !== 'parsererror') {
            return '';
        }
        $part = isset($decoded['part']) && is_scalar($decoded['part']) ? (string)$decoded['part'] : '';
        return self::PARSE_FAILURE_ACTION_BY_PART[$part] ?? self::PARSE_FAILURE_DEFAULT_ACTION;
    }

    /**
     * Redact the bounded body excerpt inside one client record, if it has
     * one. The excerpt is the only field of a client report that carries
     * freeform response TEXT rather than measurements, and every route a
     * client record leaves this class through (the checkpoint journal here,
     * the drained support-payload buffer via
     * ABJ_404_Solution_DrainedTelemetryBuffer) lands in debug_log_excerpt,
     * which the payload redaction sweep skips by design. Anything that is not
     * the {head, tail} string pair the shipped client sends is carried
     * as-is, never mangled.
     *
     * @param array<mixed, mixed> $record Keys are untrusted decoded-JSON keys;
     *   only the bodyExcerpt entry is read or written.
     * @return array<mixed, mixed>
     */
    public static function redactReportBodyExcerpt(array $record): array {
        $excerpt = $record['bodyExcerpt'] ?? null;
        if (!is_array($excerpt)) {
            return $record;
        }
        $redactor = self::piiRedactor();
        if ($redactor === null) {
            return $record;
        }
        foreach (array('head', 'tail') as $side) {
            if (isset($excerpt[$side]) && is_string($excerpt[$side])) {
                $excerpt[$side] = $redactor->redact($excerpt[$side]);
            }
        }
        $record['bodyExcerpt'] = $excerpt;
        return $record;
    }

    /** @return ABJ_404_Solution_PiiRedactor|null */
    private static function piiRedactor() {
        if (!function_exists('abj_service_optional')) {
            return null;
        }
        /** @var ABJ_404_Solution_PiiRedactor|null $redactor */
        $redactor = abj_service_optional('pii_redactor');
        return $redactor instanceof ABJ_404_Solution_PiiRedactor ? $redactor : null;
    }


    /**
     * A client-sent count that is allowed to be -1 ("this browser could not
     * observe it"), or null when the parameter was absent or not a count at
     * all. Kept separate from ctype_digit() because -1 is a real reading here
     * and silently discarding it would turn a declared blind spot into a
     * missing field.
     */
    private static function signedCountOrNull(string $raw): ?int {
        return preg_match('/^-?\d{1,9}$/', $raw) === 1 ? (int)$raw : null;
    }

    /**
     * The browser storage adapter's bounded health result. Rebuild the shape
     * field by field because this is untrusted request data; malformed input
     * remains a positive "unparseable" finding rather than blocking the table.
     *
     * @return array<string, mixed>
     */
    private static function parseStorageHealth(string $raw): array {
        if ($raw === '') {
            return array('status' => 'absent', 'raw_length' => 0);
        }
        $decoded = json_decode(substr($raw, 0, 512), true);
        if (!is_array($decoded)) {
            return array('status' => 'unparseable', 'raw_length' => strlen($raw));
        }
        $status = isset($decoded['status']) && is_scalar($decoded['status'])
            ? (string)$decoded['status'] : 'unknown';
        $quota = isset($decoded['quota']) && is_scalar($decoded['quota'])
            ? (string)$decoded['quota'] : 'unknown';
        $fallback = isset($decoded['fallback']) && is_scalar($decoded['fallback'])
            ? (string)$decoded['fallback'] : 'memory';
        return array(
            'status' => in_array($status, array('available', 'unavailable'), true) ? $status : 'unknown',
            'accessible' => is_bool($decoded['accessible'] ?? null) ? $decoded['accessible'] : null,
            'writable' => is_bool($decoded['writable'] ?? null) ? $decoded['writable'] : null,
            'quota' => in_array($quota, array('ok', 'exceeded', 'unknown'), true) ? $quota : 'unknown',
            'last_write_ok' => is_bool($decoded['last_write_ok'] ?? null)
                ? $decoded['last_write_ok'] : null,
            'fallback' => in_array($fallback, array('none', 'memory'), true) ? $fallback : 'memory',
        );
    }

    /**
     * The request reader, straight from the container.
     *
     * Resolved here rather than through
     * ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader(), which
     * returns this same service and belongs to the endpoint layer. Reading
     * request parameters is not an endpoint-only need, and routing through
     * that class made a recorder depend on the presentation surface it exists
     * to observe.
     *
     * @return ABJ_404_Solution_RequestInputNormalizer
     */
    private static function requestReader() {
        /** @var ABJ_404_Solution_RequestInputNormalizer $requestReader */
        $requestReader = abj_service('request_input_normalizer');
        return $requestReader;
    }

    /**
     * The decoded client report, or null when none was sent. Returns a
     * diagnostic stand-in (never null) when a report was sent but could not be
     * decoded: "the client sent something unparseable" is itself a finding
     * about the transport and must not be silently dropped.
     *
     * @param ABJ_404_Solution_RequestInputNormalizer $reader Docblock-typed only:
     *   tests substitute request-reader doubles that are not literally that class.
     * @return array<string, mixed>|null
     */
    private static function readReport($reader): ?array {
        $raw = $reader->getPostOrGetSanitize('clientReport', '');
        if (!is_scalar($raw) || (string)$raw === '') {
            return null;
        }
        $raw = (string)$raw;
        $truncated = strlen($raw) > self::MAX_REPORT_BYTES;
        $decoded = json_decode(substr($raw, 0, self::MAX_REPORT_BYTES), true);
        if (!is_array($decoded)) {
            return array(
                'decoded' => false,
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($raw),
                'raw_head' => substr($raw, 0, 200),
            );
        }
        // Rebuilt key by key rather than passed through: the decoded value is
        // whatever the browser sent, so its keys are only assumed to be
        // strings until they are made so here.
        $report = array();
        foreach ($decoded as $key => $value) {
            $report[(string)$key] = $value;
        }
        $report['decoded'] = true;
        $report['truncated_on_arrival'] = $truncated;
        return $report;
    }
}
