<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: public retention status and coherence coverage in tests/DataAccessDeletionByStatusTest.php and tests/DataAccessDeletionMixedScopeTest.php.
/**
 * Bounded redirect deletion with the same cache and status-count coherence as
 * the individual redirect write path.
 *
 * @phpstan-type BatchLimits array{batch_size:int,max_batches:int,timeout:int|null}
 * @phpstan-type BatchOperation array{
 *   query:string,
 *   log_row:callable(array<string,mixed>):void,
 *   limits:BatchLimits,
 *   ownership_predicate:string,
 *   counts:ABJ_404_Solution_StatusCountsMutationSync,
 *   handled:int
 * }
 */
class ABJ_404_Solution_RedirectBatchDeletion {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_CleanupQuerySession */
    private $querySession;

    /**
     * @param array{dbCore: ABJ_404_Solution_DatabaseCore,
     *   redirectsRepo: ABJ_404_Solution_RedirectsRepositoryInterface,
     *   querySession: ABJ_404_Solution_CleanupQuerySession} $dependencies
     */
    public function __construct(array $dependencies) {
        $this->dbCore = $dependencies['dbCore'];
        $this->redirectsRepo = $dependencies['redirectsRepo'];
        $this->querySession = $dependencies['querySession'];
    }

    /** Delete each selected redirect page in one SQL write instead of one
     * query per redirect, while preserving the same status-count/cache updates
     * as RedirectWriteService::deleteRedirect().
     * @param string $query A cleanup SELECT whose rows include a numeric id.
     * @param callable(array<string,mixed>):void $logRow
     * @param array{batch_size:int,max_batches:int,timeout:int|null} $limits
     */
    public function deleteMatches(string $query, callable $logRow, array $limits): int {
        $handled = 0;
        $counts = new ABJ_404_Solution_StatusCountsMutationSync($this->dbCore);
        for ($batch = 0; $batch < $limits['max_batches']; $batch++) {
            $continue = true;
            $lockFailure = $this->querySession->withRedirectGraphOwnership(
                function (string $ownershipPredicate) use (
                    $query, $limits, $logRow, $counts, &$handled, &$continue
                ): void {
                    /** @var BatchOperation $operation */
                    $operation = array(
                        'query' => $query,
                        'log_row' => $logRow,
                        'limits' => $limits,
                        'ownership_predicate' => $ownershipPredicate,
                        'counts' => $counts,
                        'handled' => $handled,
                    );
                    $continue = $this->deleteOneBatch($operation);
                    $handled = $operation['handled'];
                }
            );
            if ($lockFailure !== '' || $this->querySession->failure() !== '' || !$continue) {
                break;
            }
        }
        return $handled;
    }

    /** Execute one bounded SELECT/read/DELETE sequence while graph-owned. */
    /**
     * @param BatchOperation $operation
     */
    private function deleteOneBatch(array &$operation): bool {
        $query = $operation['query'];
        $logRow = $operation['log_row'];
        $limits = $operation['limits'];
        $ownershipPredicate = $operation['ownership_predicate'];
        $counts = $operation['counts'];
        $result = $this->querySession->query($query . ' LIMIT ' . $limits['batch_size'],
            array('log_errors' => true), 'redirect cleanup SELECT');
        if ($this->querySession->failure() !== '') {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if ($rows === array()) {
            return false;
        }

        $ids = array();
        $validRows = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $id = ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1);
            if ($id === null) { continue; }
            $ids[] = $id;
            $validRows[] = $row;
        }
        if ($ids === array()) {
            return false;
        }

        $idList = implode(',', array_map('intval', $ids));
        $where = 'id IN (' . $idList . ')';
        $before = $counts->snapshot($where);
        $storedRead = $this->redirectsRepo->readRedirectsByIDs($ids);
        $deleted = $this->querySession->query(
            'DELETE FROM {wp_abj404_redirects} WHERE ' . $where . ' AND (' . $ownershipPredicate . ')',
            array('log_errors' => true),
            'redirect cleanup DELETE'
        );
        $failure = $this->querySession->failure();
        $affected = ABJ_404_Solution_ExactInteger::read($deleted['rows_affected'] ?? null, 0);
        if ($failure !== '' || $affected === null) {
            if ($failure === '') {
                $this->querySession->noteFailure(
                    'redirect cleanup DELETE: result omitted a valid rows_affected count'
                );
            }
            $this->completeUncertainBatch($storedRead, $before, $where, $counts);
            return false;
        }
        if ($affected <= 0) {
            $this->completeUncertainBatch($storedRead, $before, $where, $counts);
            return false;
        }

        $operation['handled'] += $affected;
        ABJ_404_Solution_RedirectPageCachePurge::queueRead($storedRead);
        // Report row-level success only when the whole selected set was
        // deleted. A failed or partial write must remain retriable.
        if ($affected === count($ids)) {
            foreach ($validRows as $row) {
                $logRow($row);
            }
        }
        $this->completeBatchMutation($before, $where, $counts);
        return $affected >= count($ids) && count($rows) >= $limits['batch_size'];
    }

    /** Complete cache/count work after a confirmed batch mutation.
     * @param array<int,array{status:int,disabled:int,count:int}>|null $before
     */
    private function completeBatchMutation($before, string $where,
            ABJ_404_Solution_StatusCountsMutationSync $counts): void {
        ABJ_404_Solution_RedirectMutationCompletion::complete(array(
            'status-count-invalidation' => static function (): void {
                abj_service('view_read_service')->invalidateStatusCountsCache();
            },
            'regex-cache' => function (): void { $this->redirectsRepo->clearRegexRedirectsCache(); },
            'count-sync' => function () use ($counts, $before, $where): void {
                $counts->syncSince($before, $where);
            },
        ));
    }

    /** Complete conservatively when DELETE execution or its count is uncertain.
     * @param array{rows:array<int,array<string,mixed>>,last_error:string} $storedRead
     * @param array<int,array{status:int,disabled:int,count:int}>|null $before
     */
    private function completeUncertainBatch($storedRead, $before, string $where,
            ABJ_404_Solution_StatusCountsMutationSync $counts): void {
        ABJ_404_Solution_RedirectPageCachePurge::queueRead($storedRead);
        try {
            $this->completeBatchMutation($before, $where, $counts);
        } catch (Throwable $completionFailure) {
            // Preserve the first native SQL failure at the cleanup boundary.
            if ($this->querySession->failure() === '') {
                throw $completionFailure;
            }
            $causeChain = array();
            for ($cause = $completionFailure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                $causeChain[] = get_class($cause) . ' (code ' . (string)$cause->getCode() . '): ' . $cause->getMessage();
            }
            abj_service('logging')->warnCaught(
                'RedirectBatchDeletion: conservative completion failed after an uncertain DELETE; '
                    . 'the primary SQL failure remains authoritative. Secondary cause chain: '
                    . implode(' <- ', $causeChain),
                $completionFailure
            );
        }
    }
}
