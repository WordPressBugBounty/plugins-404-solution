<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded duplicate redirect scanning and deletion for maintenance continuations.
 *
 * The scan cursor is a primary-key boundary. A URL group may be replayed when
 * its deletion reaches the per-slice limit; choosing the survivor again is
 * deliberate and keeps a committed delete safe before checkpoint persistence.
 *
 * @phpstan-type DuplicateScan array{after_id:int,max_id:int}&array<string,mixed>
 * @phpstan-type DuplicateLimits array{batch_size:int,max_batches:int,timeout:int|null}
 * @phpstan-type DuplicateCandidate array{id:int,url:string}
 */
// allow-no-test-found: public daily-cron duplicate convergence and replay coverage lives in tests/MaintenanceContinuationDuplicateCleanupIntegrationTest.php.
class ABJ_404_Solution_RedirectDuplicatesRepository {

    /** Empty read ranges share one checkpoint without enlarging a destructive slice. */
    private const MAX_SCAN_RANGES_PER_SLICE = 20;

    /** @var ABJ_404_Solution_CleanupQuerySession */
    private $querySession;

    /** @var bool DELETE execution/result failure requires conservative invalidation. */
    private $destructiveUncertain = false;

    public function __construct(
        ABJ_404_Solution_CleanupQuerySession $querySession
    ) {
        $this->querySession = $querySession;
    }

    /**
     * Preserve the legacy uncheckpointed API while using bounded primary-key
     * slices. The raw limits are passed back to begin(), never its normalized
     * timeout=null value.
     *
     * @param array<string,mixed> $limits
     */
    public function removeDuplicates(array $limits = array()): int {
        $normalized = $this->querySession->begin($limits);
        $scan = null;
        $removed = 0;
        for ($batch = 0; $batch < $normalized['max_batches']; $batch++) {
            $outcome = $this->slice(array('scan' => $scan, 'limits' => $limits));
            $removed += $outcome['removed'];
            $failure = $outcome['failure'];
            if ($failure !== '') {
                break;
            }
            $scan = $outcome['duplicate_scan'];
            if ($outcome['complete']) {
                break;
            }
        }
        return $removed;
    }

    /**
     * Execute one replay-safe duplicate scan slice.
     *
     * @param array<string,mixed> $request
     * @return array{removed:int,complete:bool,duplicate_scan:(DuplicateScan|null),failure:string}
     */
    public function slice(array $request): array {
        $rawLimits = $request['limits'] ?? array();
        $parsedLimits = $this->parseLimits($rawLimits);
        if ($parsedLimits === null) {
            return $this->result(0, false, null, 'duplicate scan limits were not an array');
        }
        try {
            $limits = $this->querySession->begin($parsedLimits);
        } catch (InvalidArgumentException $e) {
            return $this->result(0, false, null, 'duplicate scan limits were invalid: ' . $e->getMessage());
        }
        $this->destructiveUncertain = false;
        $requestedScan = array_key_exists('scan', $request) ? $request['scan'] : null;
        $fresh = $requestedScan === null;
        if ($fresh) {
            $maxId = $this->snapshotMaxId();
            if ($maxId === null) {
                return $this->result(0, false, null, $this->querySession->failure());
            }
            $scan = array('after_id' => 0, 'max_id' => $maxId);
        } else {
            $scan = $this->validScan($requestedScan);
            if ($scan === null) {
                return $this->result(0, false, null, 'duplicate scan cursor was malformed');
            }
        }
        $originalScan = $scan;
        if ($scan['after_id'] >= $scan['max_id']) {
            return $this->result(0, true, $scan, '');
        }

        $range = $this->readCandidateRange($scan, $limits);
        if ($range === null) {
            return $this->result(0, false, $fresh ? null : $originalScan, $this->querySession->failure());
        }
        $reconciled = $this->reconcileCandidates(array(
            'scan' => $range['scan'],
            'last_id' => $range['last_id'],
            'candidates' => $range['candidates'],
            'limits' => $limits,
        ));
        $resultScan = $fresh && $reconciled['failure'] !== '' ? null : $reconciled['scan'];
        return $this->result(
            $reconciled['removed'],
            $reconciled['complete'],
            $resultScan,
            $reconciled['failure']
        );
    }

    /**
     * Keep the query-session input string-keyed before its strict limit
     * validator receives it. Values remain untouched so begin() owns range
     * and unknown-key errors.
     *
     * @param mixed $rawLimits
     * @return array<string,mixed>|null
     */
    private function parseLimits($rawLimits): ?array {
        if (!is_array($rawLimits)) {
            return null;
        }
        $limits = array();
        foreach ($rawLimits as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $limits[$key] = $value;
        }
        return $limits;
    }

    /**
     * Read ahead across empty primary-key ranges before spending a continuation
     * and durable checkpoint. Each query still fetches at most 1000 IDs or one
     * candidate batch; the first nonempty candidate range ends the read-ahead.
     * A time budget and range ceiling also bound duplicate-free tables.
     *
     * @param DuplicateScan $scan
     * @param DuplicateLimits $limits
     * @return array{scan:DuplicateScan,last_id:int,candidates:list<DuplicateCandidate>}|null
     */
    private function readCandidateRange(array $scan, array $limits): ?array {
        $deadline = abj_clock()->nowFloat() + ($limits['timeout'] ?? 5);
        for ($range = 0; $range < self::MAX_SCAN_RANGES_PER_SLICE; $range++) {
            $ids = $this->scanIds($scan['after_id'], $scan['max_id']);
            if ($ids === null) { return null; }
            if (count($ids) === 0) {
                return ['scan' => $scan, 'last_id' => $scan['max_id'], 'candidates' => []];
            }
            $lastId = $ids[count($ids) - 1];
            $candidates = $this->duplicateCandidates([
                'after_id' => $scan['after_id'], 'last_id' => $lastId, 'batch_size' => $limits['batch_size'],
            ]);
            if ($candidates === null) { return null; }
            $result = ['scan' => $scan, 'last_id' => $lastId, 'candidates' => $candidates];
            if (count($candidates) > 0 || $lastId >= $scan['max_id']
                    || abj_clock()->nowFloat() >= $deadline) {
                return $result;
            }
            $scan['after_id'] = $lastId;
        }
        return $result;
    }

    /**
     * Reconcile candidate URL groups after the bounded primary-key reads.
     *
     * @param array{scan:DuplicateScan,last_id:int,candidates:list<DuplicateCandidate>,limits:DuplicateLimits} $work
     * @return array{removed:int,complete:bool,scan:DuplicateScan,failure:string}
     */
    private function reconcileCandidates(array $work): array {
        $scan = $work['scan'];
        $lastId = $work['last_id'];
        $candidates = $work['candidates'];
        $limits = $work['limits'];
        $removed = 0;
        $candidateAfter = $scan['after_id'];
        foreach ($candidates as $candidate) {
            $cursorBeforeCandidate = $candidateAfter;
            $survivorId = null;
            $affected = null;
            $lockFailure = $this->querySession->withRedirectGraphOwnership(
                function (string $ownershipPredicate) use ($candidate, $limits, &$survivorId, &$affected): void {
                    $survivorId = $this->survivorId($candidate['url']);
                    if ($survivorId === null) {
                        return;
                    }
                    $affected = $this->deleteExtras(array(
                        'url' => $candidate['url'],
                        'survivor_id' => $survivorId,
                        'batch_size' => $limits['batch_size'],
                        'ownership_predicate' => $ownershipPredicate,
                    ));
                }
            );
            if ($affected === null) {
                return array(
                    'removed' => $removed,
                    'complete' => false,
                    'scan' => $scan,
                    'failure' => $this->querySession->failure(),
                );
            }
            // A committed delete still receives cache/count completion when
            // ownership is discovered lost only after the SQL returns.
            $removed += $affected;
            if ($lockFailure !== '' || $this->querySession->failure() !== '') {
                return array(
                    'removed' => $removed,
                    'complete' => false,
                    'scan' => $scan,
                    'failure' => $this->querySession->failure() !== ''
                        ? $this->querySession->failure() : $lockFailure,
                );
            }
            if ($affected >= $limits['batch_size']) {
                $heldScan = $scan;
                $heldScan['after_id'] = $cursorBeforeCandidate;
                return array(
                    'removed' => $removed,
                    'complete' => false,
                    'scan' => $heldScan,
                    'failure' => '',
                );
            }
            $candidateAfter = $candidate['id'];
        }

        $nextAfter = count($candidates) < $limits['batch_size'] ? $lastId : $candidateAfter;
        $nextScan = $scan;
        $nextScan['after_id'] = $nextAfter;
        return array(
            'removed' => $removed,
            'complete' => $nextAfter >= $scan['max_id'],
            'scan' => $nextScan,
            'failure' => '',
        );
    }

    /** @return int|null */
    private function snapshotMaxId(): ?int {
        $result = $this->querySession->query(
            'SELECT id FROM {wp_abj404_redirects} ORDER BY id DESC LIMIT 1',
            array(),
            'duplicate scan snapshot'
        );
        if ($this->querySession->failure() !== '') {
            return null;
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            $this->querySession->noteFailure('duplicate scan snapshot omitted rows');
            return null;
        }
        if (count($rows) === 0) {
            return 0;
        }
        $row = $rows[0] ?? null;
        $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 0) : null;
        if ($id === null) {
            $this->querySession->noteFailure('duplicate scan snapshot omitted a valid id');
            return null;
        }
        return $id;
    }

    /** @return array<int,int>|null */
    private function scanIds(int $afterId, int $maxId): ?array {
        $result = $this->querySession->query(
            'SELECT id FROM {wp_abj404_redirects} FORCE INDEX (PRIMARY) WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 1000',
            array('query_params' => array($afterId, $maxId)),
            'duplicate scan primary-key range'
        );
        if ($this->querySession->failure() !== '') {
            return null;
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            $this->querySession->noteFailure('duplicate scan primary-key range omitted rows');
            return null;
        }
        $ids = array();
        $previous = $afterId;
        foreach ($rows as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
            if ($id === null || $id <= $previous || $id > $maxId) {
                $this->querySession->noteFailure('duplicate scan primary-key range returned an invalid id');
                return null;
            }
            $ids[] = $id;
            $previous = $id;
        }
        return $ids;
    }

    /**
     * @param array{after_id:int,last_id:int,batch_size:int} $range
     * @return list<DuplicateCandidate>|null
     */
    private function duplicateCandidates(array $range): ?array {
        // A scalar subquery keeps the URL probe dependent on this bounded PK
        // range. EXISTS can become a hash semijoin that scans the whole inner
        // table before returning even one candidate.
        // allow-unbounded-select: the statement ends with "ORDER BY r.id ASC LIMIT %d" and is also bounded by the PK range (the LIMIT lands in a separate concatenated string than SELECT/FROM)
        $result = $this->querySession->query(
            'SELECT r.id, r.url FROM {wp_abj404_redirects} r '
            . 'FORCE INDEX (PRIMARY) '
            . 'WHERE r.id > %d AND r.id <= %d '
            . 'AND (SELECT duplicate.id FROM {wp_abj404_redirects} duplicate WHERE duplicate.url = r.url AND duplicate.id <> r.id LIMIT 1) IS NOT NULL '
            . 'ORDER BY r.id ASC LIMIT %d',
            array('query_params' => array($range['after_id'], $range['last_id'], $range['batch_size'])),
            'duplicate scan candidate range'
        );
        if ($this->querySession->failure() !== '') {
            return null;
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            $this->querySession->noteFailure('duplicate scan candidate range omitted rows');
            return null;
        }
        return $this->validateCandidates($rows, $range);
    }

    /**
     * Parse candidate boundaries before any survivor lookup or deletion.
     *
     * @param array<mixed> $rows
     * @param array{after_id:int,last_id:int,batch_size:int} $range
     * @return list<DuplicateCandidate>|null
     */
    private function validateCandidates(array $rows, array $range): ?array {
        $candidates = array();
        $candidateAfter = $range['after_id'];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $this->querySession->noteFailure('duplicate scan candidate range returned a malformed row');
                return null;
            }
            $candidateId = ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1);
            $url = $row['url'] ?? null;
            if ($candidateId === null || $candidateId <= $candidateAfter || $candidateId > $range['last_id']
                    || !is_string($url)) {
                $this->querySession->noteFailure('duplicate scan candidate was malformed or outside its primary-key range');
                return null;
            }
            $candidates[] = array('id' => $candidateId, 'url' => $url);
            $candidateAfter = $candidateId;
        }
        return $candidates;
    }

    /** @return int|null */
    private function survivorId(string $url): ?int {
        // allow-unbounded-select: the statement ends with "LIMIT 1" (the LIMIT lands in a separate concatenated string than SELECT/FROM)
        $result = $this->querySession->query(
            'SELECT id FROM {wp_abj404_redirects} WHERE url = %s ORDER BY CASE status '
            . 'WHEN ' . (int)ABJ404_STATUS_MANUAL . ' THEN 0 '
            . 'WHEN ' . (int)ABJ404_STATUS_REGEX . ' THEN 1 '
            . 'WHEN ' . (int)ABJ404_STATUS_AUTO . ' THEN 2 '
            . 'WHEN ' . (int)ABJ404_STATUS_CAPTURED . ' THEN 3 '
            . 'ELSE 4 END ASC, timestamp DESC, id DESC LIMIT 1',
            array('query_params' => array($url)),
            'duplicate survivor lookup'
        );
        if ($this->querySession->failure() !== '') {
            return null;
        }
        $rows = $result['rows'] ?? null;
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
        if ($id === null) {
            $this->querySession->noteFailure('duplicate survivor lookup omitted a valid id');
            return null;
        }
        return $id;
    }

    /**
     * @param array{url:string,survivor_id:int,batch_size:int,ownership_predicate:string} $operation
     * @return int|null
     */
    private function deleteExtras(array $operation): ?int {
        $url = $operation['url'];
        $survivorId = $operation['survivor_id'];
        $batchSize = $operation['batch_size'];
        $ownershipPredicate = $operation['ownership_predicate'];
        // The statement may commit before reconnect/result harvesting fails;
        // queue before execution so an uncertain write cannot leave stale page
        // cache state behind.
        ABJ_404_Solution_RedirectPageCachePurge::queueUrls(array($url));
        $result = $this->querySession->query(
            'DELETE FROM {wp_abj404_redirects} WHERE url = %s AND id <> %d AND ('
                . $ownershipPredicate . ') LIMIT %d',
            array('query_params' => array($url, $survivorId, $batchSize)),
            'duplicate extras deletion'
        );
        if ($this->querySession->failure() !== '') {
            $this->destructiveUncertain = true;
            return null;
        }
        $affected = ABJ_404_Solution_ExactInteger::read($result['rows_affected'] ?? null, 0);
        if ($affected === null) {
            $this->destructiveUncertain = true;
            $this->querySession->noteFailure('duplicate extras deletion omitted a valid affected count');
            return null;
        }
        return $affected;
    }

    /**
     * @param mixed $scan
     * @return DuplicateScan|null
     */
    private function validScan($scan): ?array {
        if (!is_array($scan)) {
            return null;
        }
        $afterId = $scan['after_id'] ?? null;
        $maxId = $scan['max_id'] ?? null;
        if (!is_int($afterId) || !is_int($maxId) || $afterId < 0 || $maxId < $afterId) {
            return null;
        }
        $validated = array();
        foreach ($scan as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $validated[$key] = $value;
        }
        $validated['after_id'] = $afterId;
        $validated['max_id'] = $maxId;
        return $validated;
    }

    /**
     * @param DuplicateScan|null $scan
     * @return array{removed:int,complete:bool,duplicate_scan:(DuplicateScan|null),failure:string}
     */
    private function result(int $removed, bool $complete, ?array $scan, string $failure): array {
        // The slice's own failure is recorded whether or not the invalidation
        // that follows a deletion succeeds; a failed invalidation then
        // propagates to the caller unchanged.
        try {
            if ($removed > 0 || $this->destructiveUncertain) {
                abj_service('view_read_service')->invalidateStatusCountsCache();
            }
        } finally {
            if ($failure !== '') {
                $this->querySession->noteFailure($failure);
            }
        }
        return array(
            'removed' => $removed,
            'complete' => $complete,
            'duplicate_scan' => $scan,
            'failure' => $failure,
        );
    }
}
