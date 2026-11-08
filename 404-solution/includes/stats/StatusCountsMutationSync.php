<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the cached admin status counts consistent with a redirect mutation.
 *
 * Foreground count reads are cache-only: the SUM(CASE) aggregate behind the
 * tab badges is a full scan of the redirects table, so it was moved to cron.
 * That left every mutation's own effect invisible to the user who caused it --
 * trash a captured 404 and the Trash badge kept its old number until a
 * background recompute landed, which on a host with a cron backlog is minutes.
 *
 * The fix is not to re-run the aggregate but to apply the delta the mutation
 * caused. A mutation is bracketed by two reads of the affected rows'
 * (status, disabled) distribution; the difference is the exact delta, so
 * inserts, status changes, trash, restore and deletes all work through the
 * same path without this class needing to know which one happened. The reads
 * are scoped to the rows the mutation already touches, never table-wide.
 *
 * Any residual drift is corrected by the next full recompute, so a failed
 * read degrades to the previous invalidate-only behavior rather than to a
 * wrong number.
 *
 * The same class applies the delta to the cached counts (applyDelta()), so
 * "work out what a mutation changed" and "write that into the cache" have one
 * owner; the recompute that replaces those counts wholesale stays with
 * ABJ_404_Solution_StatusCountsRepository.
 */
class ABJ_404_Solution_StatusCountsMutationSync {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseQueryInterface $dbCore */
    public function __construct($dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * The (status, disabled) distribution of the rows a condition selects.
     * Take this immediately before a mutation and hand it to syncSince().
     *
     * @param string $whereClause SQL WHERE body. Plugin-controlled text only:
     *        every caller-supplied value must travel in $params.
     * @param array<int, mixed> $params Bound parameters for $whereClause.
     * @return array<int, array{status:int,disabled:int,count:int}>|null Null when the
     *         read failed, which makes the paired sync a no-op.
     */
    public function snapshot(string $whereClause, array $params = array()): ?array {
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $query = "SELECT status, disabled, COUNT(*) as row_count FROM `" . $redirectsTable . "`"
            . " WHERE " . $whereClause . " GROUP BY status, disabled";
        $options = array();
        if ($params !== array()) {
            $options['query_params'] = $params;
        }
        $result = $this->dbCore->queryAndGetResults($query, $options);
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true
                || !empty($result['last_error']) || !empty($result['timed_out'])) {
            return null;
        }

        $histogram = array();
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $histogram[] = array(
                'status' => isset($row['status']) && is_scalar($row['status']) ? (int)$row['status'] : -1,
                'disabled' => isset($row['disabled']) && is_scalar($row['disabled']) ? (int)$row['disabled'] : -1,
                'count' => isset($row['row_count']) && is_scalar($row['row_count']) ? (int)$row['row_count'] : 0,
            );
        }
        return $histogram;
    }

    /**
     * Apply the delta between a snapshot and the current state of the same
     * rows. A no-op when either read failed, so a mutation never publishes a
     * count it could not derive.
     *
     * @param array<int, array{status:int,disabled:int,count:int}>|null $before From snapshot().
     * @param string $whereClause Same condition the snapshot used.
     * @param array<int, mixed> $params Same parameters the snapshot used.
     * @return void
     */
    public function syncSince(?array $before, string $whereClause, array $params = array()): void {
        if ($before === null) {
            return;
        }
        $after = $this->snapshot($whereClause, $params);
        if ($after === null) {
            return;
        }
        self::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::delta($before, $after)
        );
    }

    /**
     * Apply the delta for rows that are simply gone, for a mutation whose
     * after-state needs no second read (an unconditional DELETE of exactly
     * the snapshotted set).
     *
     * @param array<int, array{status:int,disabled:int,count:int}>|null $removed From snapshot().
     * @return void
     */
    public function syncRemoved(?array $removed): void {
        if ($removed === null || $removed === array()) {
            return;
        }
        self::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::delta($removed, array())
        );
    }

    /**
     * Apply the delta for one freshly inserted row. An insert needs no reads
     * at all: the row that just landed is the whole delta, which matters on
     * the frontend capture hot path where an extra query per 404 is not free.
     *
     * @param int $status
     * @param int $disabled
     * @return void
     */
    public function syncInserted(int $status, int $disabled): void {
        self::applyDelta(
            ABJ_404_Solution_StatusCountBuckets::deltaForRows($status, $disabled, 1, 1)
        );
    }

    /**
     * Adjust the cached bucket counts by a signed delta instead of recomputing
     * them.
     *
     * Foreground status-count reads are cache-only (the aggregate is a full
     * scan of the redirects table and is deferred to cron), so without this a
     * user who trashes a row watches the Trash tab keep its old number until
     * a background recompute lands minutes later. A mutation knows exactly
     * which rows it moved between buckets, so it can keep the cache correct
     * for free.
     *
     * Both the current and the last-known keys are adjusted: an invalidation
     * has usually just demoted the current value to last-known, and the
     * last-known copy is what a stale read actually serves. Buckets are
     * clamped at zero, since a negative tally is never a truthful answer even
     * if a concurrent writer made the delta double-count. Any residual drift
     * is corrected by the next full recompute.
     *
     * Static and dependency-free (pure transient reads and writes, no DB) for
     * the same reason ViewCacheInvalidator's debounced captured invalidation
     * is: the mutation paths that need it, including the frontend capture hot
     * path, must be able to call it without wiring up a repository.
     *
     * @param array<string, array<string, int>> $delta scope => bucket => signed delta.
     * @return void
     */
    public static function applyDelta(array $delta): void {
        foreach ($delta as $scope => $buckets) {
            if (!is_array($buckets) || count(array_filter($buckets)) === 0) {
                continue;
            }
            foreach (self::cacheKeysForScope((string)$scope) as $key => $ttl) {
                self::applyDeltaToTransient($key, $ttl, $buckets);
            }
        }
    }

    /**
     * Where one scope's counts are cached, and for how long. The key names and
     * TTLs are the ones ABJ_404_Solution_StatusCountsRepository reads and
     * recomputes under, so a delta lands on the entry a read will serve.
     *
     * @param string $scope
     * @return array<string, int> cache key => TTL seconds.
     */
    private static function cacheKeysForScope(string $scope): array {
        if ($scope === ABJ_404_Solution_StatusCountBuckets::SCOPE_REDIRECTS) {
            return array(
                ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS
                    => ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL,
                ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN
                    => ABJ_404_Solution_ViewReadRuntimeState::STATUS_LAST_KNOWN_CACHE_TTL,
            );
        }
        if ($scope === ABJ_404_Solution_StatusCountBuckets::SCOPE_CAPTURED) {
            return array(
                ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS
                    => ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL,
                ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN
                    => ABJ_404_Solution_ViewReadRuntimeState::STATUS_LAST_KNOWN_CACHE_TTL,
            );
        }
        return array();
    }

    /**
     * Apply bucket deltas to one cached count array, leaving a missing or
     * non-array cache alone (there is nothing to keep consistent, and
     * inventing counts from a delta would report a total that was never
     * measured).
     *
     * @param string $key
     * @param int $ttl
     * @param array<string, int> $buckets
     */
    private static function applyDeltaToTransient(string $key, int $ttl, array $buckets): void {
        $cached = get_transient($key);
        if (!is_array($cached)) {
            return;
        }
        foreach ($buckets as $bucket => $change) {
            if (!is_scalar($change) || (int)$change === 0) {
                continue;
            }
            $current = isset($cached[$bucket]) && is_scalar($cached[$bucket]) ? (int)$cached[$bucket] : 0;
            $cached[$bucket] = max(0, $current + (int)$change);
        }
        // allow-cache-empty: an all-zero-but-shaped count array is a real measured result, not an empty cache.
        set_transient($key, $cached, $ttl);
    }
}
