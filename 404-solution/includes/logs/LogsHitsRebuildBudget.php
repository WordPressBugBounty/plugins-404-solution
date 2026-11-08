<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signals that a rebuild slice has no safe query time left.
 *
 * The timeout is a liveness budget, not a security boundary. Callers should
 * catch this exception and yield the rebuild to a later request.
 */
class ABJ_404_Solution_LogsHitsRebuildYield extends RuntimeException {
}

/**
 * Pure wall-clock policy for one logs-hits rebuild slice.
 *
 * The clock is injected so callers and tests use the same monotonic virtual
 * time. The five-second slice and request deadline are scheduling limits, not
 * a guarantee that a database server will cancel work at those boundaries.
 */
class ABJ_404_Solution_LogsHitsRebuildBudget {

    const SLICE_SECONDS = 5.0;
    const REQUEST_SECONDS = 20.0;
    const BOOKKEEPING_RESERVE_SECONDS = 0.5;
    const MAX_TIMEOUT_SECONDS = 4;
    // Dispatch stops before the nominal slice ends. An admitted statement
    // retains its full allowance, finishing by 7.5s plus checkpoint overhead.
    const MIN_SLICE_HEADROOM_SECONDS = 1.5;
    const MAX_WORK_UNITS = 12;
    const INITIAL_LOG_CHUNK_SIZE = 5000;
    const TARGET_LOG_UNIT_SECONDS = 0.5;

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    /** @var float */
    private $deadline;

    /** @var float Hard request deadline, including time before this callback. */
    private $requestDeadline;

    /** @var int Bounds SQL and checkpoint overhead even on a very fast host. */
    private $workUnitsLeft = self::MAX_WORK_UNITS;

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @param array<string,mixed> $options
     */
    public function __construct(ABJ_404_Solution_Clock $clock, array $options = array()) {
        $this->clock = $clock;
        $now = $clock->nowFloat();
        $this->deadline = $now + self::SLICE_SECONDS;
        $this->requestDeadline = $now + self::REQUEST_SECONDS;

        $startedAt = isset($options['request_started_at'])
            ? $options['request_started_at']
            : null;
        if (is_numeric($startedAt) && is_finite((float)$startedAt)) {
            $startedAt = (float)$startedAt;
            if ($startedAt > 0.0 && $startedAt <= $now) {
                $this->requestDeadline = min($this->requestDeadline, $startedAt + self::REQUEST_SECONDS);
            }
        }
        $this->deadline = min($this->deadline, $this->requestDeadline);
    }

    /** @return float */
    public function remainingSeconds(): float {
        return max(0.0, $this->deadline - $this->clock->nowFloat());
    }

    /**
     * Whether another unit has scheduling and full atomic SQL headroom.
     *
     * @return bool
     */
    public function canStart(): bool {
        if ($this->workUnitsLeft <= 0) { return false; }
        return $this->hasQueryHeadroom();
    }

    /** Account for one successfully completed replay-safe phase unit. */
    public function completeUnit(): void {
        $this->workUnitsLeft--;
    }

    /** Size the next log unit from successful work, with bounded growth. */
    public function nextLogChunkSize(int $current, float $elapsed): int {
        $seconds = max(0.001, $elapsed);
        $predicted = (int)floor($current * self::TARGET_LOG_UNIT_SECONDS / $seconds);
        return max(100, min(100000, $current * 2, $predicted));
    }

    /**
     * Return a bounded SQL timeout, reserving time to persist the checkpoint.
     *
     * @return int
     * @throws ABJ_404_Solution_LogsHitsRebuildYield
     */
    public function timeoutSeconds(): int {
        // Recheck after lease checks and earlier statements in the same unit.
        if (!$this->hasQueryHeadroom()) {
            throw new ABJ_404_Solution_LogsHitsRebuildYield(
                'Logs-hits rebuild budget exhausted before the next query.',
                408
            );
        }
        return self::MAX_TIMEOUT_SECONDS;
    }

    /** Keep the scheduling target separate from the atomic statement limit. */
    private function hasQueryHeadroom(): bool {
        $now = $this->clock->nowFloat();
        return $this->deadline - $now >= self::MIN_SLICE_HEADROOM_SECONDS
            && $this->requestDeadline - $now >= self::MAX_TIMEOUT_SECONDS
                + self::BOOKKEEPING_RESERVE_SECONDS;
    }
}
