<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped time budget for the suggestion pipeline.
 *
 * Best-effort suggestion work (matching engines, n-gram decode/scoring loops,
 * Levenshtein batch scans) runs inline in requests that carry a hard host
 * time cap. This object anchors a deadline to script start and lets every
 * instrumented loop check how much of the request it may still spend. On
 * exhaustion the request degrades to the plain 404 it was already serving.
 *
 * The budget is wall-clock anchored (REQUEST_TIME_FLOAT), not monotonic: the
 * host cap it respects is itself wall-clock time.
 *
 * Request scoping follows the house singleton idiom: the orchestrator arms
 * one instance per request via setCurrent(), consumers read current(), and
 * the test base class clears it centrally between tests.
 */
class ABJ_404_Solution_MatchingTimeBudget {

    /** @var self|null The request-scoped armed budget, if any. */
    private static $current = null;

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    /** @var float Wall-clock epoch at which the budget runs out. */
    private $deadlineEpoch;

    /** @var int Total budget in whole seconds (for diagnostics). */
    private $totalBudgetSeconds;

    /** @var bool Sticky exhaustion flag; once set, never clears this request. */
    private $exhausted = false;

    /**
     * @param ABJ_404_Solution_Clock $clock Time source for remaining() checks.
     * @param float $deadlineEpoch Wall-clock epoch at which the budget runs out.
     * @param int $totalBudgetSeconds Total budget in whole seconds.
     */
    public function __construct(ABJ_404_Solution_Clock $clock, float $deadlineEpoch, int $totalBudgetSeconds) {
        $this->clock = $clock;
        $this->deadlineEpoch = $deadlineEpoch;
        $this->totalBudgetSeconds = $totalBudgetSeconds;
    }

    /**
     * The armed request-scoped budget, or null when no budget is armed (unit
     * drivers, admin contexts that never ran the orchestrator).
     *
     * @return self|null
     */
    public static function current(): ?self {
        return self::$current;
    }

    /**
     * Arm the request-scoped budget. Called once per request by the
     * orchestrator at run() start.
     *
     * @param self $budget
     * @return void
     */
    public static function setCurrent(self $budget): void {
        self::$current = $budget;
    }

    /** Clear the request-scoped budget (test hygiene). @return void */
    public static function clearCurrent(): void {
        self::$current = null;
    }

    /**
     * Resolve the script-start anchor for elapsed-time math.
     *
     * Prefers $_SERVER['REQUEST_TIME_FLOAT'] (is_numeric + cast: some
     * SAPIs/harnesses populate $_SERVER from strings). Falls back to the
     * request context's process_start_time. Returns null only when neither
     * source resolves; callers substitute their own last-resort anchor.
     *
     * Shared by the budget factory and the fatal-error diagnostic so the
     * anchor logic exists once and cannot drift between them.
     *
     * @return float|null Script-start epoch, or null when unresolvable.
     */
    public static function resolveAnchor(): ?float {
        $rawStart = isset($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : null;
        $start = is_numeric($rawStart) ? (float)$rawStart : null;
        if ($start !== null) {
            return $start;
        }
        $ctx = abj_service_optional('request_context');
        $ctxTyped = $ctx instanceof ABJ_404_Solution_RequestContext ? $ctx : null;
        if ($ctxTyped === null) {
            return null;
        }
        $ctxStart = $ctxTyped->process_start_time;
        return is_numeric($ctxStart) ? (float)$ctxStart : null;
    }

    /**
     * Parse a max_execution_time ini value into a host limit in seconds.
     * Refuses non-integer spellings rather than truncating them (an ini
     * value that spells a fraction is corrupt, and 0's 15s cap is the safe
     * read of it).
     *
     * @param mixed $raw The ini_get('max_execution_time') value (string|false).
     * @return int Host limit in seconds; 0 means unlimited.
     */
    public static function parseHostLimit($raw): int {
        return ABJ_404_Solution_ExactInteger::readOr($raw, 1, 0);
    }

    /**
     * Build a budget from the current environment: the shared script-start
     * anchor and the host's max_execution_time. Never throws; a missing
     * anchor falls back to now (budget starts at arm time).
     *
     * @return self
     */
    public static function fromEnvironment(): self {
        $clock = abj_clock();
        $anchor = self::resolveAnchor();
        $start = $anchor !== null ? $anchor : $clock->nowFloat();
        $hostLimit = self::parseHostLimit(ini_get('max_execution_time'));
        $total = ($hostLimit === 0) ? 15 : min(15, max($hostLimit - 5, 1));
        return new self($clock, $start + $total, $total);
    }

    /**
     * Seconds left before the deadline. Negative once past it.
     *
     * @return float
     */
    public function remaining(): float {
        return $this->deadlineEpoch - $this->clock->nowFloat();
    }

    /**
     * Whether at least $minSeconds remain and the budget is not exhausted.
     *
     * @param float $minSeconds Minimum remaining time required.
     * @return bool
     */
    public function hasTimeFor(float $minSeconds): bool {
        return !$this->exhausted && $this->remaining() >= $minSeconds;
    }

    /** @return int Total budget in whole seconds. */
    public function totalBudgetSeconds(): int {
        return $this->totalBudgetSeconds;
    }

    /** @return string One-line human-readable budget state for DEBUG/trace lines. */
    public function describe(): string {
        return sprintf(
            '%.2fs remaining of %ds budget%s',
            max(0.0, $this->remaining()),
            $this->totalBudgetSeconds,
            $this->exhausted ? ' (exhausted)' : ''
        );
    }

    /** Set the sticky exhaustion flag. @return void */
    public function markExhausted(): void {
        $this->exhausted = true;
    }

    /**
     * Whether the sticky exhaustion flag is set. The flag lives on shared
     * request state that engines mutate between reads, so the result must not
     * be memoized across calls.
     *
     * @return bool
     * @phpstan-impure
     */
    public function isExhausted(): bool {
        return $this->exhausted;
    }
}
