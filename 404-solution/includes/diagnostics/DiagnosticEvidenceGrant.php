<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The running account of one support-excerpt allocation: which journal lines
 * have been granted, which requests have any line granted, and how many bytes
 * of the hard ceiling are left.
 *
 * ABJ_404_Solution_DiagnosticEvidenceBudget decides the order and the
 * policy; this owns the bookkeeping, so the rule that a kept line costs its
 * bytes plus one newline exists in exactly one place. A drifted copy of that
 * rule would break the "hard budget" promise the excerpt makes.
 */
final class ABJ_404_Solution_DiagnosticEvidenceGrant {

    /** @var array<int, string> */
    private $lines;

    /** @var int */
    private $ceiling;

    /** @var int */
    private $remaining;

    /** @var array<int, bool> Granted line indexes. */
    private $kept = array();

    /** @var array<string, bool> Request ids with at least one granted line. */
    private $included = array();

    /**
     * @param array<int, string> $lines The whole journal stream, by line index.
     * @param int $budgetBytes Hard ceiling, newlines included.
     */
    public function __construct(array $lines, int $budgetBytes) {
        $this->lines = $lines;
        $this->ceiling = max(0, $budgetBytes);
        $this->remaining = $this->ceiling;
    }

    /** Bytes one line costs when shipped: the line plus its newline. */
    public function lineCost(int $index): int {
        return strlen($this->lines[$index]) + 1;
    }

    public function remaining(): int {
        return $this->remaining;
    }

    public function spent(): int {
        return $this->ceiling - $this->remaining;
    }

    public function isKept(int $index): bool {
        return isset($this->kept[$index]);
    }

    public function hasIncluded(string $requestId): bool {
        return isset($this->included[$requestId]);
    }

    /** @return array<string, bool> */
    public function includedIds(): array {
        return $this->included;
    }

    /**
     * @param array<int, int> $indexes One request's line indexes, in stream order.
     * @return array<int, int> Those not granted yet, in stream order.
     */
    public function unkeptIndexes(array $indexes): array {
        $unkept = array();
        foreach ($indexes as $index) {
            if (!isset($this->kept[$index])) {
                $unkept[] = $index;
            }
        }
        return $unkept;
    }

    /**
     * @param array<int, int> $indexes One request's line indexes.
     * @return int Bytes the not-yet-granted ones still cost.
     */
    public function unkeptBytes(array $indexes): int {
        $bytes = 0;
        foreach ($this->unkeptIndexes($indexes) as $index) {
            $bytes += $this->lineCost($index);
        }
        return $bytes;
    }

    /**
     * Grant one line that fits in what is left. Skipped lines are not an
     * error: an anchor that does not fit is simply not shipped.
     */
    public function keepIfItFits(string $requestId, int $index): void {
        $cost = $this->lineCost($index);
        if (isset($this->kept[$index]) || $cost > $this->remaining) {
            return;
        }
        $this->keep($requestId, array($index), $cost);
    }

    /**
     * Grant lines already priced by the caller (a pick also pays for the
     * elision note that will declare what it left out).
     *
     * The ceiling is this class's promise, so a pick that costs more than is
     * left, or that carries no lines, is refused and changes nothing rather
     * than trusting every caller to have checked.
     *
     * @param array<int, int> $indexes
     * @return bool Whether the pick was granted.
     */
    public function keep(string $requestId, array $indexes, int $bytes): bool {
        if ($indexes === array() || $bytes > $this->remaining) {
            return false;
        }
        foreach ($indexes as $index) {
            $this->kept[$index] = true;
        }
        $this->remaining -= $bytes;
        $this->included[$requestId] = true;
        return true;
    }

    /**
     * @return array<int, int> Granted line indexes, in stream order.
     */
    public function keptIndexes(): array {
        $kept = $this->kept;
        ksort($kept);
        return array_keys($kept);
    }

    /**
     * The highest granted line among these, which is where the reader
     * finishes the request, or null when none of them was granted.
     *
     * @param array<int, int> $indexes
     */
    public function lastKeptOf(array $indexes): ?int {
        $last = null;
        foreach ($indexes as $index) {
            if (isset($this->kept[$index]) && ($last === null || $index > $last)) {
                $last = $index;
            }
        }
        return $last;
    }
}
