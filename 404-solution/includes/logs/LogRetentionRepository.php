<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: real oldest-first retention, changed-cap and committed-retry coverage in tests/MaintenanceContinuationIntegrationTest.php.
/**
 * Bounded data access for log age and count retention.
 */
class ABJ_404_Solution_LogRetentionRepository {

    /** @var ABJ_404_Solution_CleanupQuerySession */
    private $querySession;

    /** @var ABJ_404_Solution_RedirectsRetentionPolicy */
    private $retentionPolicy;

    public function __construct(
        ABJ_404_Solution_CleanupQuerySession $querySession,
        ABJ_404_Solution_RedirectsRetentionPolicy $retentionPolicy
    ) {
        $this->querySession = $querySession;
        $this->retentionPolicy = $retentionPolicy;
    }

    /** @param array<string,mixed> $limits */
    public function deleteOldLogsByAge(int $daysToKeep, int $now, array $limits = array()): int {
        $limits = $this->querySession->begin($limits);
        $cutoffTimestamp = $this->retentionPolicy->cutoffForDays($daysToKeep, $now);
        if ($cutoffTimestamp === null) {
            return 0;
        }

        $removed = 0;
        for ($batch = 0; $batch < $limits['max_batches']; $batch++) {
            $query = "DELETE FROM {wp_abj404_logsv2} WHERE timestamp <= %d " .
                "ORDER BY timestamp ASC, id ASC LIMIT " . $limits['batch_size'];
            $result = $this->querySession->query($query, array(
                'query_params' => array($cutoffTimestamp),
                'log_errors' => true,
            ), 'deleteOldLogsByAge DELETE');
            if ($this->querySession->failure() !== '') {
                break;
            }
            $affected = ABJ_404_Solution_ExactInteger::read($result['rows_affected'] ?? null, 0);
            if ($affected === null) {
                $this->querySession->noteFailure('deleteOldLogsByAge DELETE: result omitted a valid rows_affected count');
                break;
            }
            $removed += $affected;
            if ($affected < $limits['batch_size']) {
                break;
            }
        }
        return $removed;
    }

    /**
     * Remove one bounded slice of oldest log rows until the live count reaches
     * the caller's persisted target. Repeating the call derives remaining work
     * from the database after interruption instead of replaying a stale count.
     *
     * @param int $targetKeep Minimum number of log rows to retain.
     * @param array<string, mixed> $limits
     * @return array{removed:int,complete:bool}
     */
    public function trimOldestLogsToCount(int $targetKeep, array $limits = array()): array {
        if ($targetKeep < 0) {
            throw new InvalidArgumentException('Invalid targetKeep=' . $targetKeep . '; expected an integer greater than or equal to 0.');
        }
        $limits = $this->querySession->begin($limits);

        // allow-unbounded-select: COUNT(*) aggregate; returns a single row
        $countResult = $this->querySession->query(
            'SELECT COUNT(*) AS row_count FROM {wp_abj404_logsv2}',
            array(),
            'trimOldestLogsToCount COUNT'
        );
        if ($this->querySession->failure() !== '') {
            return array('removed' => 0, 'complete' => false);
        }
        $countRows = is_array($countResult['rows'] ?? null) ? $countResult['rows'] : array();
        $countRow = $countRows[0] ?? null;
        $liveCount = ABJ_404_Solution_ExactInteger::read(is_array($countRow) ? ($countRow['row_count'] ?? null) : null, 0);
        if ($liveCount === null) {
            $this->querySession->noteFailure('trimOldestLogsToCount COUNT: result omitted a valid row_count');
            return array('removed' => 0, 'complete' => false);
        }
        $excess = max(0, $liveCount - $targetKeep);
        if ($excess === 0) {
            return array('removed' => 0, 'complete' => true);
        }

        $deleteLimit = min($limits['batch_size'], $excess);
        $deleteResult = $this->querySession->query(
            'DELETE FROM {wp_abj404_logsv2} ORDER BY timestamp ASC, id ASC LIMIT ' . $deleteLimit,
            array('log_errors' => true),
            'trimOldestLogsToCount DELETE'
        );
        if ($this->querySession->failure() !== '') {
            return array('removed' => 0, 'complete' => false);
        }
        $removed = ABJ_404_Solution_ExactInteger::read($deleteResult['rows_affected'] ?? null, 0);
        if ($removed === null) {
            $this->querySession->noteFailure('trimOldestLogsToCount DELETE: result omitted a valid rows_affected count');
            return array('removed' => 0, 'complete' => false);
        }
        return array('removed' => $removed, 'complete' => $removed >= $excess);
    }
}
