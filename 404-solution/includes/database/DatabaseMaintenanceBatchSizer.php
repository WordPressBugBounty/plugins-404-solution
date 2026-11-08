<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure pass-local policy for bounded adaptive maintenance batches.
 *
 * Callers retain ownership of clocks, deadlines, SQL shape, and cursors. This
 * class only chooses a bounded next SELECT size from completed work and its
 * measured elapsed time; it performs no I/O and reads no clock.
 */
class ABJ_404_Solution_DatabaseMaintenanceBatchSizer {

    /** One-second target for each successful batch. */
    private const TARGET_SECONDS_PER_BATCH = 1.0;

    /** @var positive-int */
    private $maximumSize;

    /** @var positive-int */
    private $maximumSparseSize;

    /** @var positive-int */
    private $currentSize;

    /**
     * @param array{initial_size:int,maximum_size:int,maximum_sparse_size?:int} $options
     */
    public function __construct(array $options) {
        $this->maximumSize = max(1, (int)($options['maximum_size'] ?? 1));
        $initialSize = (int)($options['initial_size'] ?? 1);
        $this->currentSize = max(1, min($this->maximumSize, $initialSize));
        $maximumSparseSize = array_key_exists('maximum_sparse_size', $options)
            ? (int)$options['maximum_sparse_size']
            : $this->maximumSize;
        $this->maximumSparseSize = max(1, min($this->maximumSize, $maximumSparseSize));
    }

    /** @return positive-int Current bounded SELECT size. */
    public function limit(): int {
        return $this->currentSize;
    }

    /**
     * Bound an ordered positive-id selection without dropping its prefix.
     * Dense selections use the normal maximum; selections containing holes use
     * the optional sparse maximum so an explicit IN write remains bounded.
     *
     * @param non-empty-array<int,int> $ids Sorted positive IDs.
     * @return non-empty-list<int>
     */
    public function boundedIds(array $ids): array {
        $ids = array_values($ids);
        $maximum = $this->isDense($ids) ? $this->maximumSize : $this->maximumSparseSize;
        return count($ids) > $maximum ? array_slice($ids, 0, $maximum) : $ids;
    }

    /**
     * Observe a successful batch and choose its next bounded size.
     *
     * Empty work is ignored. Callers should pass the IDs actually written and
     * elapsed time covering their SELECT, write, and checkpoint; deadline
     * ownership remains with the caller.
     *
     * @param array<int,int> $successfulIds IDs actually written.
     * @param float $elapsedSeconds Measured elapsed time for the completed pass.
     * @return void
     */
    public function observe(array $successfulIds, float $elapsedSeconds): void {
        $count = count($successfulIds);
        if ($count === 0) {
            return;
        }
        $predicted = (int)floor(
            $count * self::TARGET_SECONDS_PER_BATCH / max(0.001, $elapsedSeconds)
        );
        $next = max(1, min($this->maximumSize, 2 * $count, $predicted));
        if (!$this->isDense($successfulIds)) {
            $next = min($next, $this->maximumSparseSize);
        }
        $this->currentSize = $next;
    }

    /**
     * @param array<int,int> $ids
     * @return bool
     */
    private function isDense(array $ids): bool {
        $count = count($ids);
        if ($count < 2) {
            return true;
        }
        $ids = array_values($ids);
        return ((int)$ids[$count - 1] - (int)$ids[0]) === $count - 1;
    }
}
