<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Disposes of the census rows that hard-killed requests leave behind.
 *
 * A census row is deleted by its own request's shutdown function, which a
 * hard kill (an LVE or LiteSpeed worker kill, a SIGKILL, a fatal past the
 * shutdown stage) skips. Until the nightly sweep existed the only disposer was
 * ABJ_404_Solution_SameSiteCensusReading, and every caller of a reading is a
 * 404 Solution admin screen, so on a site whose administrator never opens the
 * plugin the leftovers stayed forever: bicode.eu (4.3.6, Hostinger shared
 * hosting, LiteSpeed) kept eleven Action Scheduler `boot` rows long past
 * ABJ_404_Solution_SameSiteRequestCensus::ENTRY_TTL_MS.
 *
 * There are two disposers and they share everything that decides what happens
 * to a row. partition() is the single definition of "abandoned", dispose() is
 * the single way a row is claimed and recorded, and the callers differ only in
 * when they run: a reading disposes of what it happened to see, and the daily
 * maintenance stage (ABJ_404_Solution_DatabaseUpgradeDailyMaintenance,
 * reached by the `abj404_cleanupCronAction` pass) sweeps the whole registry.
 * Nothing on the path of an ordinary request reaps: the census join and leave
 * stay one write and one delete.
 *
 * Every abandoned row is handed off in one pinned InnoDB transaction: the
 * source is locked and renamed under its exact name/value fence, the bounded
 * ledger is appended, and the pending row is deleted before commit. A failed
 * statement or commit rolls back all three mutations. Pending rows are read
 * first only for recovery of an older generation's already-persisted handoff.
 *
 * @phpstan-import-type Entry from ABJ_404_Solution_SameSiteRequestRowCodec
 * @phpstan-type AgedEntry array{option_name: string, raw_value: string, started_at_ms: int, channel: string, action: string, pid: int|null, phase: string, timeline: string, age_ms: int}
 * @phpstan-type HandoffEntry array{option_name: string, raw_value: string, started_at_ms: int, channel: string, action: string, pid: int|null, phase: string, timeline: string, age_ms?: int}
 * @phpstan-type SweepOutcome array{complete: bool, reaped: int, recorded: int, pruned: int}
 */
final class ABJ_404_Solution_SameSiteCensusReaper {

    /**
     * Wall-clock seconds one nightly run may spend sweeping. A run that spends
     * it reports `complete: false` and is run again by the maintenance pass's
     * own continuation; the next night is never the only catch-up. In line with
     * the other time-budgeted daily stages (25 s for the canonical-url backfill).
     */
    const NIGHTLY_BUDGET_SEC = 20.0;

    /**
     * Split registry entries into rows that may belong to a running request and
     * leftovers. The single definition of "abandoned" for every disposer: an
     * entry older than any request that could still be running.
     *
     * @param array<int, Entry> $entries
     * @return array{live: array<int, AgedEntry>, stale: array<int, AgedEntry>}
     */
    public static function partition(array $entries, int $nowMs): array {
        $split = array('live' => array(), 'stale' => array());
        foreach ($entries as $entry) {
            $entry['age_ms'] = max(0, $nowMs - $entry['started_at_ms']);
            $split[$entry['age_ms'] > ABJ_404_Solution_SameSiteRequestCensus::ENTRY_TTL_MS
                ? 'stale' : 'live'][] = $entry;
        }
        return $split;
    }

    /**
     * Hand off and dispose abandoned rows, at most MAX_REMOVED_PER_CALL per call.
     * A row another disposer locked first is neither deleted nor recorded here.
     *
     * @param array<int, AgedEntry> $stale whole decoded rows, so the account of
     *   a request that never deregistered survives its own deletion.
     * @return array{recorded: int, reaped: int}
     */
    public static function dispose(array $stale): array {
        if (!self::durableHandoffAvailable()) {
            return array('recorded' => 0, 'reaped' => 0);
        }
        return self::disposePending(array_slice($stale, 0,
            ABJ_404_Solution_SameSiteRequestRegistry::MAX_REMOVED_PER_CALL));
    }

    /**
     * Complete pending handoffs from an older generation. This recovery path
     * is deliberately separate from ordinary request join/leave work.
     *
     * @param array<int, HandoffEntry> $pending
     * @return array{recorded: int, reaped: int}
     */
    private static function disposePending(array $pending, ?int $nowMs = null): array {
        $outcome = array('recorded' => 0, 'reaped' => 0);
        foreach (array_slice($pending, 0, ABJ_404_Solution_SameSiteRequestRegistry::MAX_REMOVED_PER_CALL) as $entry) {
            if (strpos((string)($entry['option_name'] ?? ''),
                    ABJ_404_Solution_SameSiteRequestRegistry::PENDING_PREFIX) === 0) {
                if ($nowMs === null) {
                    continue;
                }
                $entry['age_ms'] = max(0, $nowMs - (int)($entry['started_at_ms'] ?? 0));
            }
            $status = ABJ_404_Solution_SameSiteCensusHandoff::run($entry);
            if ($status === 'recorded') {
                $outcome['recorded']++;
            }
            if ($status === 'recorded' || $status === 'already_durable') {
                $outcome['reaped']++;
            }
        }
        return $outcome;
    }

    /**
     * Dispose of every abandoned row in the registry and prune the retired rows
     * past their retention, in bounded batches, within a wall-clock budget.
     *
     * The registry is walked by option name, one read page at a time, so the
     * rows a page holds are decided by what the page contains and never by how
     * many live rows sort before them. Each page's leftovers go through
     * dispose() MAX_REMOVED_PER_CALL at a time, and retired rows through
     * pruneRetired() MAX_PRUNED_PER_CALL at a time. The budget is checked
     * between batches and at truncated page boundaries, including pages that
     * decode to no batches, and only when work remains. A run always finishes
     * at least one batch when one exists.
     *
     * @param float $budgetSec wall-clock seconds this run may spend.
     * @return SweepOutcome `complete` is false only when the budget ran out with
     *   work left AND this run removed something, so running it again is known to
     *   converge. A registry that could not be read, or a run in which every
     *   delete was refused (a read-only replica, a row another disposer got to
     *   first), reports complete: asking for another run straight away would
     *   only spin, and the maintenance pass would be held on this phase while
     *   every later phase waits.
     */
    public static function sweep(float $budgetSec): array {
        $outcome = array('complete' => true, 'reaped' => 0, 'recorded' => 0, 'pruned' => 0);
        if (!self::durableHandoffAvailable()) {
            return $outcome;
        }
        $startedAt = ABJ_404_Solution_SameSiteRequestCensus::nowFloat();
        if ($startedAt === null) {
            return $outcome;
        }
        $deadline = $startedAt + max(0.0, $budgetSec);
        $nowMs = (int)round($startedAt * 1000);

        // Recover durable handoffs first. They are outside the live namespace,
        // so a cursor over in-flight rows can never discover them.
        $cursor = '';
        do {
            $page = ABJ_404_Solution_SameSiteRequestRegistry::readPending($cursor);
            if ($page['status'] !== 'available') {
                return $outcome;
            }
            $cursor = $page['last_option_name'];
            $batches = array_chunk($page['entries'],
                ABJ_404_Solution_SameSiteRequestRegistry::MAX_REMOVED_PER_CALL);
            foreach ($batches as $index => $batch) {
                $disposed = self::disposePending($batch, $nowMs);
                $outcome['reaped'] += $disposed['reaped'];
                $outcome['recorded'] += $disposed['recorded'];
                $moreToDo = $index + 1 < count($batches);
                if (self::outOfTime($deadline, $moreToDo)) {
                    return self::budgetSpent($outcome);
                }
            }
            if (self::outOfTime($deadline, $page['truncated'] && $cursor !== '')) {
                return self::budgetSpent($outcome);
            }
        } while ($page['truncated'] && $cursor !== '');

        $cursor = '';
        do {
            $page = ABJ_404_Solution_SameSiteRequestRegistry::readAll($cursor);
            if ($page['status'] !== 'available') {
                return $outcome;
            }
            $cursor = $page['last_option_name'];
            $batches = array_chunk(self::partition($page['entries'], $nowMs)['stale'],
                ABJ_404_Solution_SameSiteRequestRegistry::MAX_REMOVED_PER_CALL);
            foreach ($batches as $index => $batch) {
                $disposed = self::dispose($batch);
                $outcome['reaped'] += $disposed['reaped'];
                $outcome['recorded'] += $disposed['recorded'];
                $moreToDo = $index + 1 < count($batches);
                if (self::outOfTime($deadline, $moreToDo)) {
                    return self::budgetSpent($outcome);
                }
            }
            if (self::outOfTime($deadline, $page['truncated'] && $cursor !== '')) {
                return self::budgetSpent($outcome);
            }
        } while ($page['truncated'] && $cursor !== '');

        $cutoffMs = $nowMs - ABJ_404_Solution_SameSiteRequestCensus::RETIRED_TTL_MS;
        do {
            $pruned = ABJ_404_Solution_SameSiteRequestRegistry::pruneRetired($cutoffMs);
            $outcome['pruned'] += $pruned;
            $moreToDo = $pruned >= ABJ_404_Solution_SameSiteRequestRegistry::MAX_PRUNED_PER_CALL;
            if (self::outOfTime($deadline, $moreToDo)) {
                return self::budgetSpent($outcome);
            }
        } while ($moreToDo);
        return $outcome;
    }

    /**
     * The outcome of a run that stopped on its budget with work left: incomplete
     * only if the run itself removed something (see sweep()).
     *
     * @param SweepOutcome $outcome
     * @return SweepOutcome
     */
    private static function budgetSpent(array $outcome): array {
        $outcome['complete'] = $outcome['reaped'] + $outcome['pruned'] === 0;
        return $outcome;
    }

    /** Whether work remains and the census clock has reached the deadline. */
    private static function outOfTime(float $deadline, bool $workRemains = true): bool {
        if (!$workRemains) {
            return false;
        }
        $now = ABJ_404_Solution_SameSiteRequestCensus::nowFloat();
        return $now === null || $now >= $deadline;
    }

    /** Capability gate for mixed-generation deployments during file replacement. */
    private static function durableHandoffAvailable(): bool {
        return class_exists('ABJ_404_Solution_SameSiteRequestRegistry')
            && defined('ABJ_404_Solution_SameSiteRequestRegistry::PENDING_PREFIX')
            && is_callable(array('ABJ_404_Solution_SameSiteRequestRegistry', 'readPending'))
            && is_callable(array('ABJ_404_Solution_SameSiteCensusHandoff', 'run'));
    }
}
