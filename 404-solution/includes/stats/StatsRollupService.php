<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded incremental statistics producer on the logs-hits lease/budget/watchdog.
 *
 * @phpstan-type State array{version:2,kind:'stats_rollup',generation:string,data_generation:string,data_created_at:int,created_at:int,updated_at:int,phase:string,step:int,cursor:int,high_water:int,min_id:int,oldest:int,oldest_count:int,audit_after:int,chunk_size:int,window_end:int,window_seconds?:float,thresholds:array<string,int>,timezone:string,data:array{periods:array<string,array<string,int>>,redirects?:array<string,int>,captured?:array<string,int>,confidence?:mixed}}
 *
 * Publications cover IDs through high_water. Later appends wait for the next
 * generation. Window audits detect cardinality loss; same-count SQL updates
 * to old rows converge on daily shadow recertification while the pipeline runs.
 */
class ABJ_404_Solution_StatsRollupService {
    /** @var ABJ_404_Solution_DatabaseCore */
    private $db;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_StatsRollupRepository */
    private $storage;
    /** @var ABJ_404_Solution_StatsDashboardSnapshotCache */
    private $snapshots;
    /** @var ABJ_404_Solution_StatsReadRepository */
    private $reads;
    /** @var ABJ_404_Solution_LogsHitsRebuildLock */
    private $lease;
    /** @var ABJ_404_Solution_LogsHitsRebuildProgress */
    private $progress;
    /** @var ABJ_404_Solution_LogsHitsRollupStallWatchdog */
    private $watchdog;

    public function __construct(ABJ_404_Solution_DatabaseCore $db,
            ABJ_404_Solution_StatsDashboardSnapshotCache $snapshots,
            ABJ_404_Solution_StatsReadRepository $reads, ABJ_404_Solution_Logging $logger) {
        $this->db = $db;
        $this->snapshots = $snapshots;
        $this->reads = $reads;
        $this->logger = $logger;
        $this->storage = new ABJ_404_Solution_StatsRollupRepository($db);
        $this->lease = new ABJ_404_Solution_LogsHitsRebuildLock($db);
        $this->progress = $this->checkpointStore();
        $this->watchdog = new ABJ_404_Solution_LogsHitsRollupStallWatchdog($db->noticeState(),
            function (): bool { return $this->continueRefresh(); }, array('logger' => $logger,
                'hasPendingRebuild' => function (): bool { return $this->hasOutstandingWork(); },
                'flagNamespace' => '_stats'));
    }

    /** Schedule only; the shared watchdog detaches admin-render continuations. */
    public function schedule(): void {
        if (!abj_cron_scheduler()->scheduleSingleIfMissing(ABJ_404_Solution_CronScheduler::HOOK_UPDATE_LOGS_HITS_TABLE, 5)) {
            $this->logger->warn('Could not schedule statistics continuation: ' . abj_cron_scheduler()->lastFailureDetail());
        }
        $this->watchdog->armDeferredRebuildIfCronIsNotExecuting();
    }

    /** Run at most the existing slice/unit/request budgets; AJAX only arms its shutdown callback. */
    public function continueRefresh(): bool {
        if (!$this->hasOutstandingWork() || $this->db->noticeState()->shouldSkipNonEssentialDbWrites()) { return false; }
        $budget = new ABJ_404_Solution_LogsHitsRebuildBudget(abj_clock(), array(
            'request_started_at' => PHP_SAPI === 'cli' ? null : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null)));
        if (!$this->lease->acquire()) { return false; }
        $state = null;
        try {
            if (!$this->storage->available()) { $this->logger->warn('Statistics rollup tables are unavailable; bootstrap repair will retry.'); return false; }
            $this->progress = $this->checkpointStore();
            $record = $this->progress->readRecord();
            if ($record !== null && !array_key_exists('oldest_count', $record)) { $record['oldest_count'] = -1; }
            $state = $record !== null && $this->validState($record) ? $record : null;
            if ($state === null || $state['phase'] === 'complete') { $state = $this->start($state, $budget); }
            while ($budget->canStart()) {
                $this->renew();
                $started = abj_clock()->nowFloat();
                $state = $this->advance($state, $budget);
                $this->renew();
                $state['updated_at'] = max($state['created_at'], abj_clock()->now());
                $this->progress->writeRecord($state);
                $budget->completeUnit();
                if (function_exists('do_action')) {
                    // Integrations can observe unit timing and cursor progress without log insert work.
                    do_action('abj404_stats_rollup_unit_completed', $state, (abj_clock()->nowFloat() - $started) * 1000);
                }
                if ($state['phase'] === 'complete') {
                    if ($this->snapshots->pending() !== null) { $this->schedule(); }
                    return true;
                }
            }
        } catch (ABJ_404_Solution_LogsHitsRebuildYield $e) {
            $this->logger->infoMessage('Statistics rollup yielded: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->warnCaught('Statistics generation remains pending; prior publication retained.', $e);
            $this->backOffFailedWindow($state);
        } finally { $this->lease->release(); }
        $this->schedule();
        return false;
    }

    /**
     * Arm one bounded post-response continuation for the authenticated stats
     * refresh endpoint, without opening the path to other AJAX actions.
     *
     * @return void
     */
    public function armAuthenticatedAjaxContinuation(): void {
        if (ABJ_404_Solution_RequestKind::currentAjaxAction() !== 'ajaxRefreshStatsDashboard'
            || !ABJ_404_Solution_RequestKind::isAjax() || !$this->hasOutstandingWork()) {
            return;
        }
        $this->watchdog->armDeferredRebuildIfCronIsNotExecuting(true);
    }

    /** @param State|null $state */
    private function backOffFailedWindow(?array $state): void {
        if ($state === null || $state['phase'] !== 'append' || $state['chunk_size'] <= 100) { return; }
        $state['chunk_size'] = max(100, (int)floor($state['chunk_size'] / 2));
        $state['window_end'] = min($state['high_water'], $state['cursor'] + $state['chunk_size']);
        $state['step'] = 0;
        try { $this->renew(); $this->progress->writeRecord($state); }
        catch (Throwable $checkpointError) { $this->logger->warnCaught('Statistics retry checkpoint failed.', $checkpointError); }
    }

    /**
     *
     * @param State|null $previous
     * @return State
     */
    private function start(?array $previous, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $request = $this->snapshots->pending();
        if ($request === null) { throw new RuntimeException('Statistics request disappeared before admission.', 409); }
        $bounds = $this->storage->sourceBounds($budget);
        $reuse = $previous !== null && $previous['cursor'] <= $bounds['max_id']
            && abj_clock()->now() - $previous['data_created_at'] < 86400;
        $generation = $request['generation'];
        $state = array('version' => 2, 'kind' => 'stats_rollup', 'generation' => $generation,
            'data_generation' => $reuse ? $previous['data_generation'] : substr(hash('sha256',
                ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue('stats')), 0, 16),
            'data_created_at' => $reuse ? $previous['data_created_at'] : abj_clock()->now(),
            'created_at' => abj_clock()->now(), 'updated_at' => abj_clock()->now(),
            'phase' => $reuse ? 'prune' : 'append', 'step' => 0,
            'cursor' => $reuse ? $previous['cursor'] : 0, 'high_water' => $bounds['max_id'],
            'min_id' => $bounds['min_id'], 'oldest' => $bounds['oldest'],
            'oldest_count' => $reuse ? $previous['oldest_count'] : $bounds['oldest_count'],
            'audit_after' => 0,
            'chunk_size' => ABJ_404_Solution_LogsHitsRebuildBudget::INITIAL_LOG_CHUNK_SIZE,
            'window_end' => 0, 'thresholds' => $this->reads->computePeriodicThresholds(abj_clock()->now()),
            'timezone' => ABJ_404_Solution_SiteTimezone::resolve()->getName(), 'data' => array('periods' => array()));
        foreach ($request['extra_thresholds'] ?? array() as $threshold) {
            if (is_int($threshold) && $threshold >= 0) { $state['thresholds']['since_' . $threshold] = $threshold; }
        }
        foreach ($state['thresholds'] as $period => $threshold) { $state['data']['periods'][$period] = $this->zero(); }
        $state['window_end'] = min($state['high_water'], $state['cursor'] + $state['chunk_size']);
        $this->progress->writeRecord($state);
        return $state;
    }

    /**
     * Phase registry keeps each unit replay-safe and its checkpoint after SQL.
     * @param State $state
     * @return State
     */
    private function advance(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $handlers = array('prune' => 'prune', 'audit' => 'audit', 'append' => 'append',
            'count' => 'counts', 'redirects' => 'redirects', 'publish' => 'publish', 'cleanup' => 'cleanup');
        return $this->{$handlers[$state['phase']]}($state, $budget);
    }

    /**
     * @param State $state
     * @return State
     */
    private function prune(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        if ($this->storage->pruneUnit($state, $budget) && ++$state['step'] === 8) {
            $state['phase'] = 'audit'; $state['step'] = 0;
        }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function audit(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $audit = $this->storage->auditWindow($state, $budget);
        if ($audit === null) { $state['phase'] = 'append'; }
        elseif ($audit['above_changed']) {
            // Retention may have crossed more than one timestamp between prune and audit.
            // Re-read the bounded floor before treating the higher-range mismatch as an arbitrary deletion.
            $bounds = $this->storage->sourceBounds($budget, $state['high_water']);
            if ($bounds['max_id'] < $state['high_water']) { return $this->start(null, $budget); }
            if ($bounds['oldest'] !== $state['oldest'] || $bounds['oldest_count'] !== $state['oldest_count']) {
                $state['oldest'] = $bounds['oldest']; $state['oldest_count'] = $bounds['oldest_count'];
                $state['phase'] = 'prune'; $state['step'] = 0; $state['audit_after'] = 0;
                return $state;
            }
            // Only a publication option switches readers; the old payload survives the shadow rebuild.
            $state['data_generation'] = substr(hash('sha256', ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue('shadow')), 0, 16);
            $state['data_created_at'] = abj_clock()->now();
            $state['cursor'] = 0; $state['phase'] = 'append';
            $state['window_end'] = min($state['high_water'], $state['chunk_size']);
        } else {
            if ($audit['boundary_changed']) { $this->storage->repairBoundaryWindow($state, $audit, $budget); }
            $state['audit_after'] = $audit['next'];
        }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function append(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        if ($state['cursor'] >= $state['high_water']) { $state['phase'] = 'count'; $state['step'] = 0; return $state; }
        $started = abj_clock()->nowFloat();
        $this->storage->appendUnit($state, $budget);
        if (++$state['step'] === 6) {
            $state['cursor'] = $state['window_end']; $state['step'] = 0;
            $state['chunk_size'] = $budget->nextLogChunkSize($state['chunk_size'], max(0.001, $state['window_seconds'] ?? 0.001));
            $state['window_seconds'] = 0.0;
            $state['window_end'] = min($state['high_water'], $state['cursor'] + $state['chunk_size']);
        } else {
            $state['window_seconds'] = max($state['window_seconds'] ?? 0.0, abj_clock()->nowFloat() - $started);
        }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function counts(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $period = array_keys($state['thresholds'])[(int)floor($state['step'] / 7)];
        $state['data']['periods'][$period] = array_replace($state['data']['periods'][$period], $this->storage->countUnit($state, $budget));
        if (++$state['step'] === count($state['thresholds']) * 7) { $state['phase'] = 'redirects'; $state['step'] = 0; }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function redirects(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $aggregate = $this->reads->readDashboardRedirectAggregate($this->db->doTableNameReplacements('{wp_abj404_redirects}'), $budget->timeoutSeconds());
        $state['data'] = array_merge($state['data'], $aggregate);
        $state['phase'] = 'publish';
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function publish(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        foreach (($this->snapshots->pending()['extra_thresholds'] ?? array()) as $threshold) {
            if (is_int($threshold) && $threshold >= 0 && !isset($state['thresholds']['since_' . $threshold])) {
                $state['thresholds']['since_' . $threshold] = $threshold;
                $state['data']['periods']['since_' . $threshold] = $this->zero();
                $state['phase'] = 'count'; $state['step'] = 0;
            }
        }
        if ($state['phase'] === 'count') { return $state; }
        $bounds = $this->storage->sourceBounds($budget, $state['high_water']);
        if ($bounds['max_id'] < $state['high_water'] || $bounds['oldest'] !== $state['oldest']
            || $state['oldest_count'] < 0 || $bounds['oldest_count'] !== $state['oldest_count']) {
            $this->logger->infoMessage('Retained log bounds changed during statistics build; reconciling before publication.');
            $state['phase'] = 'prune'; $state['step'] = 0; $state['audit_after'] = 0;
            if ($bounds['max_id'] < $state['high_water']) { return $this->start(null, $budget); }
            $state['oldest'] = $bounds['oldest']; $state['oldest_count'] = $bounds['oldest_count'];
            return $state;
        }
        $this->renew();
        $this->snapshots->publish(array_intersect_key($state, array_flip(array(
            'generation', 'data_generation', 'data_created_at', 'created_at', 'high_water', 'min_id', 'oldest', 'thresholds', 'timezone', 'data'))));
        $state['phase'] = 'cleanup'; $state['step'] = 0;
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function cleanup(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        if ($this->storage->cleanupUnit($state['data_generation'], $state['step'], $budget) && ++$state['step'] === 2) { $state['phase'] = 'complete'; }
        return $state;
    }

    private function renew(): void {
        if (!$this->lease->checkAndRenew()) { throw new RuntimeException('Statistics worker lost the logs-hits lease.', 409); }
    }

    private function checkpointStore(): ABJ_404_Solution_LogsHitsRebuildProgress {
        return new ABJ_404_Solution_LogsHitsRebuildProgress($this->logger, array(
            'option_name' => $this->snapshots->requestKey() . '_progress', 'validator' => array($this, 'validState')));
    }

    private function hasOutstandingWork(): bool {
        return $this->snapshots->pending() !== null || $this->checkpointStore()->hasPending();
    }

    /**
     * Shared progress storage rejects malformed state before SQL or destruction.
     * @param array<string,mixed> $state
     * @phpstan-assert-if-true State $state
     */
    public function validState(array $state): bool {
        return $this->validIdentity($state) && $this->validPeriods($state)
            && $this->validStoredCounts($state) && $this->validCursors($state);
    }

    /** @param array<string,mixed> $state */
    private function validIdentity(array $state): bool {
        if (($state['version'] ?? null) !== 2 || ($state['kind'] ?? null) !== 'stats_rollup') { return false; }
        foreach (array('generation', 'data_generation') as $key) {
            if (!is_string($state[$key] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $state[$key])) { return false; }
        }
        return is_string($state['timezone'] ?? null) && is_string($state['phase'] ?? null);
    }

    /** @param array<string,mixed> $state */
    private function validPeriods(array $state): bool {
        $thresholds = $state['thresholds'] ?? null;
        $data = $state['data'] ?? null;
        if (!is_array($thresholds) || !is_array($data) || !is_array($data['periods'] ?? null)) { return false; }
        if (array_keys($thresholds) !== array_keys($data['periods'])) { return false; }
        foreach (array('today', 'month', 'year', 'all') as $period) {
            if (!isset($thresholds[$period])) { return false; }
        }
        foreach ($thresholds as $period => $threshold) {
            if (!is_string($period) || !preg_match('/^(today|month|year|all|since_[0-9]+)$/D', $period)
                || !is_int($threshold) || $threshold < 0 || !$this->validPeriodCounts($data['periods'][$period] ?? null)) { return false; }
        }
        return true;
    }

    /** @param mixed $counts */
    private function validPeriodCounts($counts): bool {
        if (!is_array($counts) || count($counts) !== count($this->zero())) { return false; }
        foreach ($this->zero() as $key => $unused) {
            if (!is_int($counts[$key] ?? null) || $counts[$key] < 0) { return false; }
        }
        return true;
    }

    /** @param array<string,mixed> $state */
    private function validStoredCounts(array $state): bool {
        $data = $state['data'] ?? null;
        if (!is_array($data)) { return false; }
        foreach (array('redirects', 'captured') as $field) {
            if (!isset($data[$field])) { continue; }
            if (!is_array($data[$field])) { return false; }
            foreach ($data[$field] as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 0) { return false; }
            }
        }
        if (array_key_exists('oldest_count', $state)
            && (!is_int($state['oldest_count']) || $state['oldest_count'] < -1)) { return false; }
        return !isset($state['window_seconds']) || (is_float($state['window_seconds'])
            && is_finite($state['window_seconds']) && $state['window_seconds'] >= 0);
    }

    /** @param array<string,mixed> $state */
    private function validCursors(array $state): bool {
        $bounds = array();
        foreach (array('data_created_at', 'created_at', 'updated_at', 'step', 'cursor', 'high_water', 'min_id', 'oldest', 'audit_after', 'chunk_size', 'window_end') as $key) {
            $value = $state[$key] ?? null;
            if (!is_int($value) || $value < 0) { return false; }
            $bounds[$key] = $value;
        }
        $phase = $state['phase'] ?? null;
        if (!is_string($phase) || !is_array($state['thresholds'] ?? null)) { return false; }
        $steps = array('prune' => 7, 'audit' => 0, 'append' => 5, 'count' => count($state['thresholds']) * 7 - 1,
            'redirects' => 0, 'publish' => 0, 'cleanup' => 1, 'complete' => 2);
        return isset($steps[$phase]) && $bounds['step'] <= $steps[$phase]
            && $bounds['created_at'] > 0 && $bounds['updated_at'] >= $bounds['created_at']
            && $bounds['cursor'] <= $bounds['high_water'] && $bounds['window_end'] <= $bounds['high_water']
            && $bounds['cursor'] <= $bounds['window_end'] && $bounds['window_end'] - $bounds['cursor'] <= 100000
            && $bounds['chunk_size'] >= 100 && $bounds['chunk_size'] <= 100000;
    }

    /** @return array<string,int> */
    private function zero(): array {
        return array_fill_keys(array('disp404', 'distinct404', 'visitors404', 'refer404',
            'redirected', 'distinctredirected', 'distinctvisitors', 'distinctrefer'), 0);
    }
}
