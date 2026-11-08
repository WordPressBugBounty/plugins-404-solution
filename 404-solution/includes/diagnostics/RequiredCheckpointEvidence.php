<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selects checkpoint records that must survive bounded support ranking.
 *
 * Ordinary request-group ranking may omit an early census, a row-progress
 * sample, or a completed browser report even though each is the only record
 * carrying its discriminator fields. This policy reserves one complete record
 * for every such evidence class before the remaining byte budget is ranked.
 *
 * The reservation is not free: the reader charges it to the checkpoint budget
 * ahead of the ranked failing-request groups. Each reserved record therefore
 * carries a tier saying what is lost if it has to be dropped, and the reader
 * drops from the least valuable tier up (see
 * ABJ_404_Solution_RequiredCheckpointEvidenceBudget). The tiers are declared here
 * because this class owns what each record means.
 */
final class ABJ_404_Solution_RequiredCheckpointEvidence {

    /**
     * Dropped last: a corrupt sample proves the sink was reached, and an
     * unmatched intent is the only record naming the checkpoint a killed
     * worker was about to write. An unmatched intent is removed from ranking
     * once reserved, so dropping it here loses it outright; that is why this
     * tier is admitted before every other and newest first (the interrupted
     * worker's intent is the newest). It is still subject to the byte
     * allowance: a journal that lost its ordinary writes has one unmatched
     * intent per checkpoint, and a count set by the sink cannot be exempt from
     * a ceiling that protects the ranked section.
     */
    const TIER_PROTECTED = 0;

    /**
     * State of an operation that has not finished: the active-operation
     * breadcrumb, the armed durable record, an unmatched start, the last query
     * probe of a request whose timeline never closed. These name the culprit.
     */
    const TIER_UNRESOLVED = 1;

    /** The latest complete record of each discriminator identity (census, row progress, ...). */
    const TIER_IDENTITY = 2;

    /** The last full boundary of each request that never terminated. */
    const TIER_BOUNDARY = 3;

    /**
     * A completed start/end pair, kept as a positive control that a normal
     * operation looks like this. The one tier whose absence loses no
     * failure evidence, so it is the first to go. A unit of two: half a pair
     * would read as an unmatched start.
     */
    const TIER_EXEMPLAR = 4;

    /**
     * Latest complete record for every required evidence identity.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, string>
     */
    public static function select(array $lines): array {
        $selected = array();
        foreach (self::selectUnits($lines) as $unit) {
            foreach ($unit['lines'] as $line) {
                $selected[] = $line;
            }
        }
        return array_values(array_unique($selected));
    }

    /**
     * The same selection as select(), grouped into units that are kept or
     * dropped whole, each labelled with its tier. Output order is the order
     * the lines read best in; priority is the tier, not the position.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, array{tier: int, lines: array<int, string>}>
     */
    public static function selectUnits(array $lines): array {
        if (class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbState')) {
            $lines = ABJ_404_Solution_ActiveOperationBreadcrumbState::compactSupportLines($lines);
        }
        $operationState = array();
        foreach (array_reverse($lines) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $operationKey = self::activeOperationKey($record);
            if ($operationKey !== '' && !isset($operationState[$operationKey])) {
                $operationState[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        $units = array();
        foreach (ABJ_404_Solution_MalformedCheckpointEvidence::select($lines) as $line) {
            $units[] = self::unit(self::TIER_PROTECTED, array($line));
        }
        foreach (ABJ_404_Solution_RequiredCheckpointIdentityEvidence::select($lines) as $line) {
            $units[] = self::unit(self::TIER_IDENTITY, array($line));
        }
        foreach ($operationState as $latest) {
            $record = $latest['record'];
            $line = $latest['line'];
            if (self::isReservedActiveOperation($record)) {
                $units[] = self::unit(self::TIER_UNRESOLVED, array($line));
            }
        }
        foreach (self::reservedDurableOperationLines($lines) as $line) {
            $units[] = self::unit(self::TIER_UNRESOLVED, array($line));
        }
        foreach (self::operationEvidenceUnits($lines) as $unit) {
            $units[] = $unit;
        }
        foreach (self::unmatchedQueryLines($lines) as $line) {
            $units[] = self::unit(self::TIER_UNRESOLVED, array($line));
        }
        foreach (ABJ_404_Solution_CheckpointIntentCorrelation::unmatchedIntentLines($lines)
            as $line) {
            $units[] = self::unit(self::TIER_PROTECTED, array($line));
        }
        foreach (self::lastFullBoundaryLinesForIncompleteRequests($lines) as $line) {
            $units[] = self::unit(self::TIER_BOUNDARY, array($line));
        }
        return self::withoutRepeatedLines($units);
    }

    /**
     * @param array<int, string> $lines
     * @return array{tier: int, lines: array<int, string>}
     */
    private static function unit(int $tier, array $lines): array {
        return array('tier' => $tier, 'lines' => $lines);
    }

    /**
     * A line reserved by two rules is kept once, by the first rule in output
     * order; a unit left with no lines disappears.
     *
     * @param array<int, array{tier: int, lines: array<int, string>}> $units
     * @return array<int, array{tier: int, lines: array<int, string>}>
     */
    private static function withoutRepeatedLines(array $units): array {
        $seen = array();
        $distinct = array();
        foreach ($units as $unit) {
            $fresh = array();
            foreach ($unit['lines'] as $line) {
                if (!isset($seen[$line])) {
                    $seen[$line] = true;
                    $fresh[] = $line;
                }
            }
            if ($fresh !== array()) {
                $distinct[] = self::unit($unit['tier'], $fresh);
            }
        }
        return $distinct;
    }

    /**
     * The final full-envelope boundary reached by each unterminated request.
     *
     * High-frequency query and row records can follow the last lifecycle
     * boundary before a worker stalls. Raw head/tail byte trimming therefore
     * treats that boundary as middle content and can discard the exact
     * pre-stall location when host-pressure counters widen the full envelope.
     * Reserve one boundary per incomplete request so variable environment
     * values cannot change whether the failure remains attributable.
     *
     * A request with only frequent records has no boundary to invent. A
     * request with any terminal event is complete and stays in ordinary
     * request-group ranking.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function lastFullBoundaryLinesForIncompleteRequests(array $lines): array {
        $requests = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || !is_scalar($record['request_id'] ?? null)
                    || !is_scalar($record['event'] ?? null)) {
                continue;
            }
            $requestId = (string)$record['request_id'];
            $event = (string)$record['event'];
            if ($requestId === '' || $event === '') {
                continue;
            }
            if (!isset($requests[$requestId])) {
                $requests[$requestId] = array(
                    'terminal' => false,
                    'boundary' => '',
                    'boundary_count' => 0,
                );
            }
            if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::TERMINAL_EVENTS, true)) {
                $requests[$requestId]['terminal'] = true;
            }
            if (($record['envelope'] ?? '')
                    === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL) {
                $requests[$requestId]['boundary'] = $line;
                $requests[$requestId]['boundary_count']++;
            }
        }

        $selected = array();
        foreach ($requests as $request) {
            // A lone full record is already both the head and tail of its
            // request group, so raw head/tail ranking cannot hide it. Requiring
            // a preceding boundary also keeps a standalone partial evidence
            // sample from being promoted merely because it lacks a terminal.
            if ($request['terminal'] === false && $request['boundary_count'] > 1) {
                $selected[] = $request['boundary'];
            }
        }
        return $selected;
    }

    /**
     * Select the latest unresolved fixed-sink state for each operation.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function reservedDurableOperationLines(array $lines): array {
        $latestByOperation = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $operationKey = is_array($record) ? self::durableOperationKey($record) : '';
            if ($operationKey !== '') {
                $latestByOperation[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        $selected = array();
        foreach ($latestByOperation as $latest) {
            $record = $latest['record'] ?? null;
            $line = $latest['line'] ?? null;
            if (is_array($record) && is_string($line)
                    && self::isReservedDurableOperation($record)) {
                $selected[] = $line;
            }
        }
        return $selected;
    }

    /**
     * Reserved operation evidence derived from the manifest's start/end pairs,
     * oldest start first.
     *
     * Every unmatched start remains reserved, as an unresolved single. A
     * completed operation is an exemplar pair: only the latest pair in each
     * request/family namespace is kept, so bounded support can identify a
     * normal callback or operation without reserving every routine pair, and
     * the pair is one unit so trimming can never leave a start that looks
     * unmatched. Matching remains request + operation ID + start-event
     * namespace; orphan and cross-family/cross-request ends cannot close a
     * start. Repeated operation IDs keep the most recent start, as the prior
     * collector did.
     *
     * @param array<int, string> $lines
     * @return array<int, array{tier: int, lines: array<int, string>}>
     */
    private static function operationEvidenceUnits(array $lines): array {
        $startEvents = array();
        $endToStart = array();
        foreach (ABJ_404_Solution_DecisiveRecordManifest::reservedOperationPairs() as $pair) {
            $startEvents[$pair['start']] = true;
            $endToStart[$pair['end']] = $pair['start'];
        }

        $openStarts = array();
        $openMeta = array();
        $completedByFamilyRequest = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            $isStart = isset($startEvents[$event]);
            if (!$isStart && !isset($endToStart[$event])) {
                continue;
            }
            $key = self::operationKey($record, $isStart ? $event : $endToStart[$event]);
            if ($key === '') {
                continue;
            }
            if ($isStart) {
                $requestId = is_scalar($record['request_id'] ?? null)
                    ? (string)$record['request_id'] : '';
                $openStarts[$key] = array('index' => $index, 'line' => $line);
                $openMeta[$key] = array(
                    'family' => $event,
                    'request_id' => $requestId,
                );
                continue;
            }
            if (isset($openStarts[$key], $openMeta[$key])) {
                $meta = $openMeta[$key];
                $familyRequest = $meta['family'] . '|' . $meta['request_id'];
                $completedByFamilyRequest[$familyRequest] = array(
                    'start' => $openStarts[$key],
                    'end' => array('index' => $index, 'line' => $line),
                );
                unset($openStarts[$key]);
                unset($openMeta[$key]);
            }
        }

        $byStartIndex = array();
        foreach ($openStarts as $start) {
            $byStartIndex[$start['index']] = self::unit(self::TIER_UNRESOLVED, array($start['line']));
        }
        foreach ($completedByFamilyRequest as $pair) {
            $byStartIndex[$pair['start']['index']] = self::unit(
                self::TIER_EXEMPLAR,
                array($pair['start']['line'], $pair['end']['line'])
            );
        }
        ksort($byStartIndex, SORT_NUMERIC);
        return array_values($byStartIndex);
    }

    /**
     * The reservation key for a start/end record: its start-event namespace
     * plus request_id and operation_id. Empty when either identifier is absent,
     * so an unattributable record is never reserved.
     *
     * @param array<mixed, mixed> $record
     */
    private static function operationKey(array $record, string $startEvent): string {
        $requestId = is_scalar($record['request_id'] ?? null) ? (string)$record['request_id'] : '';
        $operationId = is_scalar($record['operation_id'] ?? null) ? (string)$record['operation_id'] : '';
        if ($requestId === '' || $operationId === '') {
            return '';
        }
        return $startEvent . '|' . $requestId . '|' . $operationId;
    }

    /**
     * The last query probe per request whose timeline never closed (no summary
     * with open_query === null). A killed worker mid-query leaves exactly this.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedQueryLines(array $lines): array {
        $lastQueries = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            if ($event === 'query_probe' && $requestId !== '') {
                $lastQueries[$requestId] = $line;
            } elseif ($event === 'query_timeline_summary' && $requestId !== ''
                    && ($record['open_query'] ?? null) === null) {
                unset($lastQueries[$requestId]);
            }
        }
        return array_values($lastQueries);
    }

    /** @param array<mixed, mixed> $record */
    private static function activeOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $state = is_scalar($record['state'] ?? null) ? (string)$record['state'] : '';
        $manifest = self::activeBoundaryManifest();
        if ($requestId === ''
                || !array_key_exists($boundary, $manifest)
                || !in_array($state, array('active', 'complete'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /** @param array<mixed, mixed> $record */
    private static function durableOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'durable_operation_state') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $checkpointId = is_scalar($record['operation_checkpoint_id'] ?? null)
            ? (string)$record['operation_checkpoint_id'] : '';
        return $requestId === '' || $checkpointId === ''
            ? ''
            : $requestId . '|' . $checkpointId;
    }

    /** @param array<mixed, mixed> $record */
    private static function isReservedActiveOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $manifest = self::activeBoundaryManifest();
        $requiredFields = $manifest[$boundary]['required_evidence_fields'] ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields);
    }

    /** @param array<mixed, mixed> $record */
    private static function isReservedDurableOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'durable_operation_state'
                || !in_array($record['operation_state'] ?? '', array('intent', 'armed'), true)) {
            return false;
        }
        $operationEvent = is_scalar($record['operation_event'] ?? null)
            ? (string)$record['operation_event'] : '';
        if ($operationEvent === 'cache_metrics_probe_start') {
            return self::hasNonEmptyScalarKeys(
                $record,
                array('operation_id', 'source', 'phase', 'operation_checkpoint_id')
            );
        }
        if ($operationEvent !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $requiredFields = self::activeBoundaryManifest()[$boundary]['required_evidence_fields']
            ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields)
            && self::hasNonEmptyScalarKeys($record, array('operation_checkpoint_id'));
    }

    /**
     * Missing diagnostics files must degrade to no reserved active evidence
     * instead of breaking the support-request path on a corrupt install.
     *
     * @return array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    private static function activeBoundaryManifest(): array {
        return class_exists('ABJ_404_Solution_ActiveOperationBoundaryManifest')
            ? ABJ_404_Solution_ActiveOperationBoundaryManifest::boundaries()
            : array();
    }

    /**
     * @param array<mixed, mixed> $record
     * @param array<int, string> $fields
     */
    private static function hasNonEmptyScalarKeys(array $record, array $fields): bool {
        foreach ($fields as $field) {
            $value = $record[$field] ?? null;
            if (!is_scalar($value) || (string)$value === '') {
                return false;
            }
        }
        return true;
    }
}
