<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admits tiered required-evidence units into a fixed byte allowance.
 *
 * ABJ_404_Solution_RequiredCheckpointEvidence decides what must survive
 * ranking and how much each record is worth; this class decides what fits.
 * The split exists because the reservation is charged to the checkpoint
 * budget ahead of the ranked failing-request groups, so an unbounded
 * reservation starves the very section it was meant to complement. A session
 * of a few dozen requests used to reserve more bytes than the whole budget,
 * leaving the ranking none and letting the report cap cut the block's tail.
 *
 * Rules, in order of precedence:
 *  - Units are admitted by tier (most valuable first, TIER_PROTECTED before
 *    all), newest first within a tier, while they still fit. A unit that does
 *    not fit is omitted; smaller units behind it may still be admitted.
 *  - The allowance binds every tier, the protected one included. Protected
 *    records are few when a worker dies, but their number is set by the intent
 *    sink, not by the evidence: when ordinary checkpoint writes are lost
 *    (lock contention, rotation, a cleared journal) every intent is unmatched,
 *    and E2E run 6 transmitted 631 of them (172 KB). An exemption from the
 *    ceiling would let exactly that starve the ranked section again.
 *  - A unit is admitted or omitted whole, so a completed start/end pair is
 *    never split into a start that reads as unmatched.
 *  - Admitted lines keep their original order, and anything omitted is counted
 *    in a trailing line, so the loss is readable from the report itself.
 */
final class ABJ_404_Solution_RequiredCheckpointEvidenceBudget {

    /** Bytes held back for the trailing "trimmed" line, so stating the loss cannot cause one. */
    const OMISSION_NOTICE_RESERVE_BYTES = 192;

    /**
     * @param array<int, array{tier: int, lines: array<int, string>}> $units In output order.
     * @param int $allowanceBytes Bytes the lines (one newline each) may occupy.
     * @return array<int, string> The admitted lines, plus the omission notice when any unit was left out.
     */
    public static function fit(array $units, int $allowanceBytes): array {
        $remaining = $allowanceBytes - self::OMISSION_NOTICE_RESERVE_BYTES;
        $admitted = array();
        foreach (self::admissionOrder($units) as $position) {
            $unit = $units[$position];
            $bytes = self::bytesOf($unit['lines']);
            if ($bytes <= $remaining) {
                $admitted[$position] = true;
                $remaining -= $bytes;
            }
        }

        $lines = array();
        $omittedRecords = 0;
        $omittedBytes = 0;
        foreach ($units as $position => $unit) {
            if (isset($admitted[$position])) {
                foreach ($unit['lines'] as $line) {
                    $lines[] = $line;
                }
                continue;
            }
            $omittedRecords += count($unit['lines']);
            $omittedBytes += self::bytesOf($unit['lines']);
        }
        if ($omittedRecords > 0) {
            $lines[] = sprintf(
                'Required checkpoint evidence trimmed: %d lower-priority record%s (%d bytes) omitted'
                . ' to keep room for the failing-request ranking.',
                $omittedRecords,
                $omittedRecords === 1 ? '' : 's',
                $omittedBytes
            );
        }
        return $lines;
    }

    /**
     * Positions in the order units compete for room: tier ascending, then the
     * later position (the newer evidence) first.
     *
     * @param array<int, array{tier: int, lines: array<int, string>}> $units
     * @return array<int, int>
     */
    private static function admissionOrder(array $units): array {
        $positions = array_keys($units);
        usort($positions, static function (int $a, int $b) use ($units): int {
            return ($units[$a]['tier'] <=> $units[$b]['tier']) ?: ($b <=> $a);
        });
        return $positions;
    }

    /** @param array<int, string> $lines */
    private static function bytesOf(array $lines): int {
        $bytes = 0;
        foreach ($lines as $line) {
            $bytes += strlen($line) + 1;
        }
        return $bytes;
    }
}
