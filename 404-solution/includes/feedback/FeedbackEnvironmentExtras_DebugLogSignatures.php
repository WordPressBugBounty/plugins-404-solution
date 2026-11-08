<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-log tail scanner + PII-stripping signature normalizer for the
 * feedback payload's `recent_error_signatures` field.
 *
 * Two responsibilities both anchored to the debug log:
 *   1. probeRecentErrorSignatures(): tail-read the plugin debug log
 *      (capped at 256 KB), parse lines matching the canonical
 *      "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): ..." shape that Logging.php emits,
 *      keep only [ERROR]/[WARN] entries within the last 7 days, group
 *      by coarse signature, return the top 5 by count.
 *   2. normalizeErrorSignature(): the PII-stripping transform that
 *      makes the grouping correct. Cuts the per-request/environment
 *      envelope the plugin's own writer appends (referrer, requested
 *      URL, versions, query duration), folds body-embedded wall-clock
 *      stamps, strips absolute paths to basenames, collapses memory
 *      addresses, hex literals, multi-digit numbers, and whitespace runs
 *      so different incident timestamps and addresses fold into the same
 *      signature key. This is the unit pinned by
 *      tests/F6UrlFragmentLeakIntoErrorSignatureTest, and the grouping
 *      POLICY it implements is pinned against the shared corpus in
 *      tests/ErrorSignatureGroupingCorpusTest -- shared because the
 *      report server implements the same policy a second time, in JS
 *      (404-solution-server src/lib/fingerprint.js).
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition;
 * see that class's collect() method for the recordProbe() wrapper that
 * converts a thrown scan failure into a recent_error_signatures_error
 * marker slug.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures {

    /** Bound a physical log line before the tail scanner discards it. */
    const MAX_PHYSICAL_LINE_BYTES = 65537;

    /**
     * Severity words that must never be consumed as a timezone abbreviation
     * while folding an embedded wall-clock stamp.
     */
    const LOG_LEVEL_WORDS = 'ERROR|FATAL|WARN|WARNING|INFO|DEBUG|NOTICE|TRACE';

    /**
     * The per-request/environment tail the plugin's own writer appends, which
     * is not part of the defect. Two anchors, both requiring the ", <field>:"
     * shape so prose that merely mentions the words cannot trigger a cut:
     *
     *   ", PHP version: X, WP ver: ..." -- ABJ_404_Solution_LoggingMessageWriter
     *   ::writeErrorMessage() appends this to EVERY error line, and the
     *   "Referrer:" / "Requested URL:" fields inside it change on every hit.
     *   The anchor spans two adjacent fields, so a message whose own body says
     *   "PHP version: 8.3.33 is below the minimum" keeps it.
     *
     *   ", Execution time: ..." / ", execution_time: ..." --
     *   ABJ_404_Solution_DatabaseSqlErrorReporter puts the query duration
     *   there, followed only by DB ver, the server-variable dump and the
     *   stripped query. Everything identifying the failure (the driver error,
     *   the SQL file, the route) sits before it.
     *
     * Every value cut here already travels in its own payload field, so no
     * triage information is lost -- and the referrer/requested URL leave the
     * telemetry payload entirely, which shrinks its PII surface.
     */
    const REQUEST_ENVELOPE_PATTERN =
        '/,\s*(?:execution[ _]time\s*:|PHP version\s*:\s*\S+\s*,\s*WP ver\s*:).*$/is';

    /**
     * A wall-clock stamp embedded in the message BODY, in any zone form the
     * plugin or the host can emit (named abbreviation, abbreviation plus
     * offset, bare offset, ISO "Z", or none). At most ONE zone token is
     * consumed and it may not be a severity word, so
     * "... 03:20:01 ERROR ..." cannot lose its level.
     *
     * The leading log stamp never reaches here (probeRecentErrorSignatures()
     * consumes it as capture group 1), but a body can carry its own: see
     * ABJ_404_Solution_OldPermalinkPostResolver, which warns with the offending
     * post_date interpolated. The pre-existing "\d{4,}" fold only collapsed
     * such a value's YEAR ("2026-08-17 03:22:41" -> "N-08-17 03:22:41"), which
     * left a per-occurrence splitter behind.
     */
    const EMBEDDED_DATETIME_PATTERN =
        '/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?'
        . '(?:Z\b|\s+(?:(?!(?:' . self::LOG_LEVEL_WORDS . ')\b)[A-Z]{2,5}(?:[+-]\d{2}:?\d{2})?'
        . '|[+-]\d{2}(?::?\d{2})?))?/';

    /**
     * Top distinct recurring error signatures from the plugin's debug
     * log file over the last 7 days, capped at 5 entries. The triggering
     * error is captured by the report itself ('error_signature' on the
     * payload); this probe captures the recurring error which is often
     * different and would never reach the email-on-first-error path.
     *
     * Bounded cost: reads the tail 256 KB of the debug file, parses
     * lines matching the canonical "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): ..."
     * shape, keeps only [ERROR]/[WARN] entries within the last 7 days,
     * groups by a coarse signature (first 200 chars after the level),
     * keeps the top 5 by count. Returns an empty array on any read
     * failure.
     *
     * Shape:
     *   [ {signature: string, count: int, last_seen_at: int}, ... ]
     *
     * An absent, unreadable, or error-free log yields an empty array: on a
     * healthy site that is the truthful answer. A MISSING LOGGING SERVICE
     * throws instead, so the caller's recordProbe() wrapper writes a
     * `recent_error_signatures_error` marker. Those two cases used to be
     * indistinguishable in the payload -- the same "silently degrade to empty
     * after the thing I observe is refactored away" shape that left
     * `view_build_state` looking healthy-but-empty for seven weeks
     * (t_260801_071502_922). A probe that cannot look must never report
     * "nothing to see".
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeRecentErrorSignatures(): array {
        $out = array();
        $log = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (!is_object($log) || !method_exists($log, 'getDebugFilePath')) {
            throw new \RuntimeException('logging service unavailable for recent_error_signatures probe');
        }
        $path = (string)$log->getDebugFilePath();
        if ($path === '') {
            return $out;
        }
        $size = $this->readableLogSize($path);
        if ($size === null || $size === 0) {
            return $out;
        }
        $tail = $this->readBoundedLogTail($path, $size);
        if ($tail === null) {
            return $out;
        }
        if ($tail === '') {
            return $out;
        }
        return $this->aggregateRecentSignatures($tail, abj_clock()->now() - 7 * 86400, $log);
    }

    /** @return int|null Null means an admitted filesystem probe failed. */
    private function readableLogSize(string $path): ?int {
        $fileResult = ABJ_404_Solution_HostFilesystemCall::isFile($path);
        if ($fileResult['warning'] !== null) {
            $this->reportFilesystemWarning('debug log file check', $path, $fileResult['warning']);
            return null;
        }
        if (!$fileResult['result']) {
            return 0;
        }
        $readableResult = ABJ_404_Solution_HostFilesystemCall::isReadable($path);
        if ($readableResult['warning'] !== null) {
            $this->reportFilesystemWarning('debug log readability check', $path, $readableResult['warning']);
            return null;
        }
        if (!$readableResult['result']) {
            return 0;
        }
        $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($path);
        if ($sizeResult['warning'] !== null) {
            $this->reportFilesystemWarning('debug log size read', $path, $sizeResult['warning']);
            return null;
        }
        return is_int($sizeResult['result']) ? $sizeResult['result'] : null;
    }

    /**
     * Read at most the bounded tail, retaining all native warning causes and
     * closing an acquired stream even when seek or read fails.
     */
    private function readBoundedLogTail(string $path, int $size): ?string {
        $readBytes = 262144; // 256 KB
        $offset = $size > $readBytes ? $size - $readBytes : 0;
        $openResult = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'rb');
        if ($openResult['warning'] !== null) {
            $this->reportFilesystemWarning('debug log open', $path, $openResult['warning']);
            if (is_resource($openResult['result'])) {
                $closeResult = ABJ_404_Solution_HostFilesystemCall::fclose($openResult['result']);
                if ($closeResult['warning'] !== null) {
                    $this->reportFilesystemWarning('debug log close', $path, $closeResult['warning']);
                }
            }
            return null;
        }
        $fh = $openResult['result'];
        if (!is_resource($fh)) {
            return null;
        }
        $tail = '';
        $readWarning = null;
        try {
            if ($offset > 0) {
                $seekResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                    static function () use ($fh, $offset) { return fseek($fh, $offset); }
                );
                if ($seekResult['warning'] !== null || $seekResult['result'] !== 0) {
                    $readWarning = $seekResult['warning'] ?? 'fseek returned false';
                }
                // Discard the partial first line so we only group on whole records.
                if ($readWarning === null) {
                    $firstLineResult = ABJ_404_Solution_HostFilesystemCall::fgets(
                        $fh,
                        self::MAX_PHYSICAL_LINE_BYTES
                    );
                    if ($firstLineResult['warning'] !== null) {
                        $readWarning = $firstLineResult['warning'];
                    }
                }
            }
            $chunkResult = $readWarning === null
                ? ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                    static function () use ($fh, $readBytes) { return fread($fh, $readBytes); }
                )
                : array('result' => false, 'warning' => null);
            if ($chunkResult['warning'] !== null) {
                $readWarning = $chunkResult['warning'];
            }
            if ($readWarning === null && is_string($chunkResult['result'])) {
                $tail = $chunkResult['result'];
            }
        } finally {
            $closeResult = ABJ_404_Solution_HostFilesystemCall::fclose($fh);
            if ($closeResult['warning'] !== null) {
                $readWarning = $readWarning === null ? $closeResult['warning']
                    : $readWarning . '; ' . $closeResult['warning'];
            }
        }
        if ($readWarning !== null) {
            $this->reportFilesystemWarning('debug log read', $path, $readWarning);
            return null;
        }
        return $tail;
    }

    /**
     * Group admitted tail lines by normalized signature. Keeping timestamp
     * admission and signature aggregation separate makes the bounded-read
     * stage auditable without changing its seven-day or top-five policy.
     *
     * @param object $log
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRecentSignatures(string $tail, int $cutoff, $log): array {
        $byKey = array();
        $lines = preg_split('/\r?\n/', $tail);
        if (!is_array($lines)) {
            return array();
        }
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '') { continue; }
            // Match "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): tail..." per
            // LogTimestampFormatter's actual 'Y-m-d H:i:s T' output (the
            // trailing timezone abbreviation/offset is optional in the
            // pattern so older or hand-edited log lines without one still
            // match).
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+\S+)?\s+\((ERROR|WARN)\):\s*(.*)$/', $line, $m)) {
                continue;
            }
            // The captured datetime is in the WP site's configured timezone
            // (LogTimestampFormatter's contract), not PHP's default timezone,
            // so it must be interpreted the same way here before comparing
            // against the true-UTC $cutoff -- otherwise a non-UTC site's
            // recent errors are silently miscounted as too old (or too new).
            try {
                $ts = (new DateTimeImmutable($m[1], ABJ_404_Solution_SiteTimezone::resolve()))->getTimestamp();
            } catch (Exception $e) {
                // The regex above validates digit *shape* (\d{4}-\d{2}-\d{2}
                // \d{2}:\d{2}:\d{2}) but not calendar validity, so a
                // corrupted or hand-edited log line (e.g. month 13) can still
                // reach here. Rare, but a run of these would indicate log
                // corruption or a LogTimestampFormatter regression, so make
                // it visible in diagnostics instead of dropping it with zero
                // trace. Delegated to a helper (rather than inlined here) to
                // keep this already-complex parsing loop's branch count from
                // growing further.
                $this->logUnparseableTimestamp($log, $m[1], $e);
                continue;
            }
            if ($ts < $cutoff) { continue; }
            $msg = trim($m[3]);
            if ($msg === '') { continue; }
            $sig = $m[2] . ':' . substr($this->normalizeErrorSignature($msg), 0, 200);
            if (!isset($byKey[$sig])) {
                $byKey[$sig] = array('signature' => $sig, 'count' => 0, 'last_seen_at' => 0);
            }
            $byKey[$sig]['count']++;
            if ($ts > $byKey[$sig]['last_seen_at']) {
                $byKey[$sig]['last_seen_at'] = $ts;
            }
        }
        $list = array_values($byKey);
        usort($list, function ($a, $b) {
            $cmp = $b['count'] - $a['count'];
            return $cmp !== 0 ? $cmp : $b['last_seen_at'] - $a['last_seen_at'];
        });
        return array_slice($list, 0, 5);
    }

    private function reportFilesystemWarning(string $operation, string $path, string $warning): void {
        $message = $operation . ' failed for ' . $path . ': ' . $warning;
        if (class_exists('ABJ_404_Solution_FeedbackTransportLog')) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', $message);
        } elseif (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('feedback-fallback', $message);
        }
    }

    /**
     * Report a log line whose timestamp matched the digit-shape regex but
     * failed to parse as a real calendar date/time (e.g. month 13). Debug
     * mode gated (via Logging::debugMessage()'s own contract) since this can
     * run once per matched line in the tail and a corrupted log could
     * otherwise flood the debug log on every probe call.
     *
     * @param object $log Already validated as an object with getDebugFilePath()
     *                     by the caller; debugMessage() itself is checked here
     *                     since not every logger double implements it.
     * @param string $rawTimestamp The unparseable captured group, for context.
     * @param \Exception $e
     * @return void
     */
    private function logUnparseableTimestamp($log, string $rawTimestamp, \Exception $e): void {
        if (method_exists($log, 'debugMessage')) {
            $log->debugMessage('FeedbackEnvironmentExtras_DebugLogSignatures: unparseable log timestamp "' .
                $rawTimestamp . '": ' . $e->getMessage());
        }
    }

    /**
     * Coarse-grain an error message so different incident timestamps,
     * memory addresses, file paths, and line numbers fold into the same
     * signature. Used by probeRecentErrorSignatures to group recurring
     * errors. Exposed (public) so the unit test
     * tests/F6UrlFragmentLeakIntoErrorSignatureTest can pin the
     * PII-stripping behavior directly.
     *
     * Mirrors ABJ_404_Solution_CrashBeacon::lightRedact() (the cheap
     * capture-time version run inside the fatal handler) and is also run
     * again on an already-lightRedact'd message at report time
     * (CrashBeaconReporter::buildSignature()), so the digit fold here must
     * use the same "bytes" exemption or the capture-time fix has no effect
     * on the final reported text. See lightRedact()'s docblock: a byte
     * count is never PII and is the memory_limit/allocation-size
     * diagnostic the crash-beacon feature exists to report. It is also
     * stable per site (memory_limit does not change between requests), so
     * exempting it does not hurt the grouping this function exists for.
     *
     * DELIBERATELY not normalized, and each one is a divergence from the
     * server-side twin (404-solution-server src/lib/fingerprint.js) that the
     * shared corpus records rather than tries to reconcile:
     *   - Runs of 4 or 5 digits stay folded to "N" (the server keeps them and
     *     folds only 6+). This output ships as readable text in the telemetry
     *     payload, so folding post / redirect / user ids is a PII control, not
     *     a grouping choice, and loosening it would make previously redacted
     *     values visible.
     *   - Source line numbers. The only carrier here is the crash beacon,
     *     which already reports file and line as their own fields.
     *
     * @param string $msg
     * @return string
     */
    public function normalizeErrorSignature(string $msg): string {
        // Local probe logs retain native details; historical tails cross the same
        // outbound boundary as freshly collected logs. Never re-export their
        // exception text, table names or paths through the signature channel.
        $probeFailure = '/^(?:FeedbackEnvironmentExtras probe '
            . '|collectPluginTableSizes probe failed |probeIndexCardinality failed |probeDbCollation probe failed '
            . '|probeActiveConnectionCount failed:|serverVersionString probe failed:'
            . '|probeTimezone offset probe failed:|probePluginLifecycle options_repository probe failed:'
            . '|probeMultisiteRole network-activated check failed:|probeHtaccessWritable failed for '
            . '|disk_(?:free|total)_space failed for '
            . '|debug log (?:file check|readability check|size read|open|close|read) failed for '
            . '|Cache drop-in (?:header|presence) probe failed:'
            . '|FeedbackTransport (?:SELECT VERSION\(\)|db_version|PDO driver|wp_get_db_schema|db_server_info) probe failed:)/';
        $s = preg_match($probeFailure, $msg) === 1 ? 'Feedback environment probe failed' : $msg;
        // Cut the per-request/environment envelope first: it is the largest
        // and most variable part, and removing it also keeps the caller's
        // 200-char signature window for message text that discriminates.
        $s = preg_replace(self::REQUEST_ENVELOPE_PATTERN, '', $s) ?? $s;
        // Fold body-embedded wall-clock stamps before the digit fold below,
        // which would otherwise eat the year and leave the rest standing.
        $s = preg_replace(self::EMBEDDED_DATETIME_PATTERN, '[TS]', $s) ?? $s;
        // Strip absolute paths to just the basename.
        $s = preg_replace('#/[A-Za-z0-9_\-\./]+/([A-Za-z0-9_\-]+\.php)#', '$1', $s) ?? $s;
        // Collapse memory addresses, hex, and digit sequences.
        $s = preg_replace('/\b0x[0-9a-fA-F]+\b/', '0xN', $s) ?? $s;
        $s = preg_replace('/\b\d{4,}\b(?!\s*bytes\b)/i', 'N', $s) ?? $s;
        // Collapse runs of whitespace.
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
