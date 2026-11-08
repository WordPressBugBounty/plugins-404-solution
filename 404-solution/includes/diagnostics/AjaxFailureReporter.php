<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: authorized AJAX, reaper, HTTP and mail paths covered by AjaxFailureReportingRealDbTest and AjaxFailureReportingWorkerKillRealDbTest.

/**
 * Promotes accepted AJAX failures on any admin-ajax action the plugin owns
 * (ABJ_404_Solution_PluginAjaxActions, the list registration also uses) into
 * the existing developer error-report workflow; a failure on any other action
 * is recorded as a WARN only. Counts are independent of the hourly log gate. Evidence is captured
 * while the journals still exist, so daily maintenance can report a killed
 * worker even after subsequent traffic has rotated its files away.
 */
final class ABJ_404_Solution_AjaxFailureReporter {

    const EVIDENCE_OPTION = 'abj404_ajax_failure_evidence';
    const COOLDOWN_SECONDS = 3600;

    /**
     * Keep the released intake ABI for a worker already running older ClientTransportReport code.
     *
     * @param array{report?: array<string, mixed>, reported_attempt_id?: mixed, subpage?: mixed, retry_count?: mixed} $client
     */
    public static function recordClientTimeout(array $client): void {
        if (is_array($client['report'] ?? null) && ($client['report']['outcome'] ?? '') === 'timeout') {
            self::recordClientFailure($client);
        }
    }

    /**
     * Promote a parsed timeout from the authorized shared client-report intake.
     * The intake journals first, so the snapshot includes its client verdict.
     * Both piggybacked reports and report-only beacons use this path.
     *
     * @param array<string, mixed> $client Parsed report and carrier context.
     * @return void
     */
    public static function recordClientFailure(array $client): void {
        $report = $client['report'] ?? null;
        if (!is_array($report)) {
            return;
        }
        $observation = ABJ_404_Solution_RequestFailureFrame::clientObservation($report);
        if ($observation === null) { return; }
        $context = self::clientFailureContext($client, $report, $observation);
        if ($context === null) {
            return;
        }
        $rid = $context['request_id'];
        $row = $context['row'];
        $kind = $context['observation']['parser'] === 'failed' ? 'client_parsererror'
            : (($observation['outcome'] ?? '') === 'timeout' ? 'client_timeout' : 'client_transport');
        self::record(array(
            'kind' => $kind,
            'action' => ($report['part'] ?? '') === 'health' ? 'ajaxRefreshHealthBar' : 'ajaxUpdatePaginationLinks',
            'phase' => $row['phase'] ?? 'unrecorded',
            'age_ms' => $report['durationMs'] ?? 0,
            'request_id' => $rid,
            'subpage' => self::allowed($report['subpage'] ?? '',
                array('abj404_redirects', 'abj404_captured', 'abj404_logs'),
                self::allowed($client['subpage'] ?? '',
                    array('abj404_redirects', 'abj404_captured', 'abj404_logs'), 'unknown')),
            'retry_count' => ABJ_404_Solution_ExactInteger::read($report['attempt'] ?? null, 0)
                ?? ABJ_404_Solution_ExactInteger::readOr($client['retry_count'] ?? null, 0, 0),
        ));
    }

    /**
     * Persist the client observation beside the matching request timeline.
     *
     * @param array<string, mixed> $client
     * @param array<mixed, mixed> $report
     * @param array<string, mixed> $observation
     * @return array{request_id: string, row: array<string, int|string|null>|null, observation: array<string, mixed>}|null
     */
    private static function clientFailureContext(array $client, array $report, array $observation): ?array {
        $named = ABJ_404_Solution_AjaxRequestLedger::normalizeId($client['reported_attempt_id'] ?? '', '');
        $rid = $named !== '' ? $named
            : ABJ_404_Solution_AjaxRequestLedger::normalizeId($report['id'] ?? '', '');
        if ($rid === '') {
            return null;
        }
        $found = ABJ_404_Solution_SameSiteRequestRegistry::findTimeline($rid);
        $row = ABJ_404_Solution_SameSiteRequestRowCodec::decodeRow(array(
            'option_name' => ABJ_404_Solution_SameSiteRequestRegistry::OPTION_PREFIX . 'reported',
            'option_value' => $found['value'],
        ));
        $timeline = ABJ_404_Solution_RequestPhaseTimeline::decode(
            ABJ_404_Solution_SameSiteRequestRowCodec::timelineOfValue($found['value']));
        $timeline = $timeline ?? array('v' => 1, 'rid' => $rid,
            'failure_frame_status' => 'unavailable',
            'failure_frame_reason' => 'never_reached_or_write_failed_or_expired');
        $timeline['client_observation'] = $observation;
        ABJ_404_Solution_StrandedRequestLedger::recordTimeline($timeline, array(
            'reason' => ABJ_404_Solution_StrandedRequestLedger::REASON_CLIENT_REPORTED, 'state' => $found['state']));
        return array('request_id' => $rid, 'row' => $row, 'observation' => $observation);
    }

    /**
     * Record one authorized client timeout or successfully saved reaped row.
     * Untrusted strings never become log text unless they match a vocabulary.
     *
     * @param array<string, mixed> $event kind, action, phase, age_ms,
     *   subpage, request_id and retry_count.
     * @return void
     */
    public static function record(array $event): void {
        try {
            $kind = $event['kind'] ?? '';
            if (!in_array($kind, array('client_timeout', 'client_parsererror', 'client_transport', 'stranded'), true)) {
                return;
            }
            // The owned-action list is both the ownership test and the log
            // vocabulary: a name outside it is written as `other`, never raw.
            $action = self::allowed($event['action'] ?? '',
                ABJ_404_Solution_PluginAjaxActions::ACTIONS, 'other');
            $phase = self::allowed($event['phase'] ?? '',
                ABJ_404_Solution_SameSiteRequestCensus::PHASES, 'unrecorded');
            $age = ABJ_404_Solution_ExactInteger::readOr($event['age_ms'] ?? null, 0, 0);
            $normalized = array('kind' => $kind, 'action' => $action, 'phase' => $phase, 'age_ms' => $age);
            // The heartbeat wire keeps its three fixed buckets (report.schema.json):
            // HeartbeatFailureCounts folds every other owned action into `other`.
            if (class_exists('ABJ_404_Solution_HeartbeatWindowAggregate')) {
                ABJ_404_Solution_HeartbeatWindowAggregate::record($normalized);
            }
            if (!self::claimHourlySlot($action, $phase)) {
                return;
            }
            $rid = ABJ_404_Solution_AjaxRequestLedger::normalizeId($event['request_id'] ?? '', 'unknown00');
            $subpage = self::allowed($event['subpage'] ?? '',
                array('abj404_redirects', 'abj404_captured', 'abj404_logs'), 'unknown');
            $retry = ABJ_404_Solution_ExactInteger::readOr($event['retry_count'] ?? null, 0, 0);
            $message = 'AJAX failure kind=' . $kind . ' action=' . $action
                . ' subpage=' . $subpage . ' phase=' . $phase . ' age_ms=' . $age
                . ' request_id=' . $rid . ' retry_count=' . $retry;
            if ($action === 'other') {
                // An action the plugin does not own is, in practice, a host
                // killing another plugin's worker (Action Scheduler, a page
                // builder, a backup job). The plugin's pages keep working,
                // so it is a WARN (defensive rule 8): durable, counted above,
                // but never an ERROR or evidence in the developer report.
                abj_service('logging')->warn($message);
                return;
            }
            abj_service('logging')->errorMessage($message);
            self::captureEvidence($rid, $message);
        } catch (Throwable $e) {
            abj404_logPhpFallback('ajax-failure-reporter',
                'AJAX failure reporting failed (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    /** Return retained event evidence for the opt-in error payload. */
    public static function evidence(): string {
        $stored = ABJ_404_Solution_OptionShapeRead::scalar(self::EVIDENCE_OPTION, '');
        $record = is_string($stored) ? self::decodeEvidenceRecord($stored) : null;
        $excerpt = $record['excerpt'] ?? '';
        return is_string($excerpt) ? $excerpt : '';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $values
     * @return string
     */
    private static function allowed($value, array $values, string $fallback): string {
        return is_string($value) && in_array($value, $values, true) ? $value : $fallback;
    }

    /**
     * Atomic options-row claim, optionally mirrored into the object cache. A
     * get/set pair alone lets simultaneous beacons both write an ERROR, so the
     * `_transient_<key>` row (value = expiry) is the authority on every site.
     *
     * Without a persistent object cache WordPress stores a transient in that
     * same options row, and set_transient() on a value row lacking its timeout
     * row DELETES and re-adds it: the claim vanishes for a moment and a second
     * beacon claims the slot. get_transient() can likewise delete the claim
     * when a leftover timeout row has expired. So on such sites the transient
     * API is never touched; the row's own expiry value is the whole contract.
     * With an external object cache the transient lives in the cache, a store
     * the claim row does not share, so it is only a cheap pre-check there.
     */
    private static function claimHourlySlot(string $action, string $phase): bool {
        $key = 'abj404_ajax_failure_' . md5($action . ':' . $phase);
        $now = abj_clock()->now();
        $mirrored = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        if ($mirrored) {
            $cachedExpiry = ABJ_404_Solution_ExactInteger::read(get_transient($key), 0);
            if ($cachedExpiry !== null && $cachedExpiry > $now) {
                return false;
            }
        }
        $expires = $now + self::COOLDOWN_SECONDS;
        $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => '_transient_' . $key,
            'compute' => static function (?string $current) use ($now, $expires): ?string {
                $currentExpiry = ABJ_404_Solution_ExactInteger::read($current, 0);
                return $currentExpiry !== null && $currentExpiry > $now ? null : (string)$expires;
            },
        ));
        if ($result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED) {
            return false;
        }
        if ($mirrored) {
            ABJ_404_Solution_TransientStore::store($key, $expires, self::COOLDOWN_SECONDS);
        }
        return true;
    }

    /**
     * Keep complete ranked excerpts, newest last, within the existing wire
     * bound. Any loss of older records is stated explicitly. Never cut JSONL
     * halfway through a record.
     */
    private static function captureEvidence(string $rid, string $message): void {
        $excerpt = $message . "\n" . ABJ_404_Solution_SupportEvidenceExcerpt::assembleAjaxFailure(array($rid));
        $refusal = '';
        $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => self::EVIDENCE_OPTION,
            'compute' => static function (?string $current) use ($excerpt, &$refusal): ?string {
                $refusal = '';
                $record = $current === null ? null : self::decodeEvidenceRecord($current);
                if ($current !== null && $record === null) {
                    $refusal = 'existing evidence has a malformed or unsupported schema; preserved it';
                    return null;
                }
                if (isset($record['version']) && $record['version'] === PHP_INT_MAX) {
                    $refusal = 'existing evidence version cannot be incremented; preserved it';
                    return null;
                }
                $previous = is_array($record) && is_string($record['excerpt'] ?? null)
                    ? $record['excerpt'] : '';
                $combined = $previous === '' ? $excerpt : $previous . "\n\n" . $excerpt;
                $max = ABJ_404_Solution_SupportEvidenceExcerpt::MAX_DEBUG_LOG_EXCERPT_BYTES;
                if (strlen($combined) > $max) {
                    $notice = "[Older AJAX failure evidence omitted to fit the report budget.]\n";
                    $combined = substr($combined, -($max - strlen($notice)));
                    $break = strpos($combined, "\n");
                    $combined = $notice . ($break === false ? '' : substr($combined, $break + 1));
                }
                $now = abj_clock()->now();
                $version = is_array($record) && is_int($record['version'] ?? null)
                    ? $record['version'] + 1 : 1;
                return ABJ_404_Solution_Utf8SafeRecord::encode(array_replace($record ?? array(), array(
                    'schema_version' => 1,
                    'version' => $version,
                    'type' => 'ajax_failure_evidence',
                    'created_at' => $record['created_at'] ?? $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'excerpt' => $combined,
                )), 'AJAX failure evidence');
            },
        ));
        if ($result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_UNCHANGED && $refusal !== '') {
            self::logEvidencePersistenceIssue('persistence refused: ' . $refusal);
        } elseif ($result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED
                && $result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_UNCHANGED) {
            self::logEvidencePersistenceIssue('persistence failed: option-row compare-and-swap returned ' . $result);
        }
    }

    private static function logEvidencePersistenceIssue(string $reason): void {
        $message = 'AJAX failure evidence ' . $reason;
        try {
            abj_service('logging')->warn($message);
        } catch (Throwable $e) {
            abj404_logPhpFallback('ajax-failure-reporter', $message);
        }
    }

    /**
     * Decode only the known record schema. Unknown keys are retained by update
     * callers but never needed to interpret the excerpt.
     *
     * @return (array{schema_version: 1, type: 'ajax_failure_evidence', created_at: int,
     *   updated_at: int, deleted_at: int|null, excerpt: string, version?: int}&array<string, mixed>)|null
     */
    private static function decodeEvidenceRecord(string $raw): ?array {
        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            return null;
        }
        $record = get_object_vars($decoded);
        if (($record['schema_version'] ?? null) !== 1
                || ($record['type'] ?? null) !== 'ajax_failure_evidence'
                || !is_int($record['created_at'] ?? null)
                || !is_int($record['updated_at'] ?? null)
                || !(is_int($record['deleted_at'] ?? null) || ($record['deleted_at'] ?? null) === null)
                || !is_string($record['excerpt'] ?? null)
                || (array_key_exists('version', $record)
                    && (!is_int($record['version']) || $record['version'] < 1))) {
            return null;
        }
        $known = array(
            'schema_version' => 1,
            'type' => 'ajax_failure_evidence',
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
            'deleted_at' => $record['deleted_at'],
            'excerpt' => $record['excerpt'],
        );
        if (array_key_exists('version', $record)) {
            return array('schema_version' => 1, 'type' => 'ajax_failure_evidence',
                'created_at' => $record['created_at'], 'updated_at' => $record['updated_at'],
                'deleted_at' => $record['deleted_at'], 'excerpt' => $record['excerpt'],
                'version' => $record['version']) + $record;
        }
        return $known + $record;
    }
}
