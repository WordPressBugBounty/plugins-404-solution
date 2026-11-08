<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsHitsRollupServiceInterface.php';
require_once __DIR__ . '/LogsReadResult.php';
require_once __DIR__ . '/LogsHitsCanonicalUrlJoinHelper.php';
require_once __DIR__ . '/LogsHitsTableRebuilder.php';
require_once __DIR__ . '/LogsHitsRebuildLock.php';
require_once __DIR__ . '/LogsHitsRollupStallWatchdog.php';
require_once __DIR__ . '/LogsHitsRebuildProgress.php';
require_once __DIR__ . '/LogsHitsTableMetadata.php';

/**
 * wp_abj404_logs_hits rollup lifecycle (existence checks, scheduling,
 * locking, rebuild pipelines, staleness signaling, max/min id reads,
 * runtime-flag state).
 *
 * Extracted from LogsRepository under M201. LogsRepository now forwards
 * the rollup-related interface methods to this service. Internal log read
 * and write paths reach the rollup via LogsRepository's facade as well.
 *
 * @phpstan-import-type State from ABJ_404_Solution_LogsHitsRebuildProgress
 */
class ABJ_404_Solution_LogsHitsRollupService implements ABJ_404_Solution_LogsHitsRollupServiceInterface {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300;
    /** @var int Minimum interval between hits-table rebuild schedules (server-side dedupe). */
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = 30;
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. Canonical home is the rebuild lock; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = ABJ_404_Solution_LogsHitsRebuildLock::TTL_SECONDS;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. Canonical home is the rebuild engine; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = ABJ_404_Solution_LogsHitsTableRebuilder::HITS_TABLE_PREAGG_CHUNK_SIZE;
    /** @var int Direct-path threshold for hits-table rebuild. Canonical home is the rebuild engine; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = ABJ_404_Solution_LogsHitsTableRebuilder::HITS_TABLE_DIRECT_PATH_THRESHOLD;

    /** @var string Runtime flag: last time we scheduled a rebuild. */
    const HITS_TABLE_LAST_SCHEDULED_FLAG = 'abj404_logs_hits_last_scheduled_at';
    /** @var string Runtime flag: last successful hits-table rebuild completion. */
    const HITS_TABLE_LAST_REFRESHED_FLAG = 'abj404_logs_hits_last_refreshed_at';
    /** @var string Durable flag: first stale detection timestamp. Canonical home is the stall watchdog. */
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = ABJ_404_Solution_LogsHitsRollupStallWatchdog::FIRST_STALL_FLAG;
    /** @var string Notice-payload type for a stalled logs_hits rollup. Canonical home is the stall watchdog. */
    const HITS_TABLE_STALE_NOTICE_TYPE = ABJ_404_Solution_LogsHitsRollupStallWatchdog::NOTICE_TYPE;
    /** @var int Minimum age (seconds) of stale gap before surfacing admin notice. Canonical home is the stall watchdog. */
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = ABJ_404_Solution_LogsHitsRollupStallWatchdog::NOTICE_THRESHOLD_SECONDS;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /**
     * Reset per-request scheduling state. Test seam: lets tests run multiple
     * scenarios in a single process without the static cooldown flag leaking
     * between them.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$hitsTableRebuildScheduled = false;
    }

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper */
    private $joinHelper;

    /** @var ABJ_404_Solution_LogsHitsRebuildLock */
    private $rebuildLock;

    /** @var ABJ_404_Solution_LogsHitsTableRebuilder */
    private $rebuilder;

    /** @var ABJ_404_Solution_LogsHitsRollupStallWatchdog */
    private $stallWatchdog;

    /** @var ABJ_404_Solution_LogsHitsRebuildProgress */
    private $progress;

    /** @var ABJ_404_Solution_LogsHitsTableMetadata */
    private $metadata;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder|null $noticeState
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logging = null,
        $rebuildHealth = null,
        $noticeState = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->noticeState = $noticeState !== null ? $noticeState : $dbCore->noticeState();
        $this->progress = new ABJ_404_Solution_LogsHitsRebuildProgress($this->logger);
        $this->metadata = new ABJ_404_Solution_LogsHitsTableMetadata($dbCore, $this->logger);
        $this->rebuildLock = new ABJ_404_Solution_LogsHitsRebuildLock($dbCore);
        $this->joinHelper = new ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper($dbCore);
        $this->rebuilder = new ABJ_404_Solution_LogsHitsTableRebuilder(
            $this->dbCore,
            $this->logger,
            $this->rebuildHealth,
            $this->joinHelper,
            array($this->rebuildLock, 'checkAndRenew')
        );
        $this->stallWatchdog = new ABJ_404_Solution_LogsHitsRollupStallWatchdog(
            $this->noticeState,
            // The watchdog decides WHEN a rebuild may happen outside cron; the
            // gate/lock protocol that decides whether it CAN stays here.
            function (): bool {
                return $this->hitsTableNeedsRebuild()
                    ? $this->createRedirectsForViewHitsTable()
                    : false;
            },
            array('logger' => $this->logger,
                'hasPendingRebuild' => function (): bool { return $this->progress->hasPending(); })
        );
    }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    private function resolveRebuildHealthState(): ?ABJ_404_Solution_RebuildHealthState {
        if (function_exists('abj_service_optional')) {
            $service = abj_service_optional('rebuild_health');
            if ($service instanceof ABJ_404_Solution_RebuildHealthState) {
                return $service;
            }
        }
        return null;
    }

    /**
     * Resolve the collation from the abj404_redirects.canonical_url column,
     * the actual join partner for the hits rebuild phase2 JOIN. Delegates
     * to the canonical-url join helper.
     *
     * @return string Sanitized collation identifier.
     */
    public function resolveHitsJoinCollation(): string {
        return $this->joinHelper->resolveHitsJoinCollation();
    }

    // =========================================================================
    // Staleness signaling
    // =========================================================================

    /** @inheritDoc */
    public function recordLogsHitsRollupStalenessSignal(): void {
        $this->stallWatchdog->observe($this->getMaxLogId(), $this->getStoredMaxLogId());
    }

    // =========================================================================
    // Rebuild readiness queries
    // =========================================================================

    /** @inheritDoc */
    public function hitsTableNeedsRebuild() {
        if ($this->progress->hasPending()) { return true; }
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();
        if ($currentMaxId != $storedMaxId) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)"); return true; }
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) { $age = abj_clock()->now() - $lastUpdated; if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)"); return true; } }
        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;
        $schemaTimestamp = $this->metadata->freshnessEpoch();
        if ($schemaTimestamp === null) { return $runtimeRefreshedAt; }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) { return $runtimeRefreshedAt; }
        return $schemaTimestamp;
    }

    // =========================================================================
    // Rebuild pipeline (direct + chunked)
    // =========================================================================

    /**
     * @inheritDoc
     *
     * Coordinates a rollup rebuild: enforces the health gate, write cooldown,
     * and cross-request lock, snapshots the logsv2 id range (so the tracking
     * test subclass and the pre-insert watermark both observe the same values),
     * then delegates the materialize-and-swap SQL to the rebuild engine. On a
     * successful refresh it stamps the freshness flag, clears the staleness
     * signal, and writes the denorm hit columns back onto the redirects rows.
     */
    public function createRedirectsForViewHitsTable(): bool {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed.");
            return false;
        }
        // CLI callbacks have an invocation boundary, not the SAPI's process
        // start instant. Web callbacks share their original request deadline,
        // including the time spent rendering before shutdown detached it.
        $budget = new ABJ_404_Solution_LogsHitsRebuildBudget(abj_clock(), array(
            'request_started_at' => PHP_SAPI === 'cli' ? null : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null),
        ));
        try {
            if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); return false; }
            if (!$this->rebuildLock->acquire()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held."); return false; }
            $result = $this->runRebuildSlice($budget);
            if ($result['pending']) {
                $state = $result['state'];
                $state['status'] = 'ready';
                $this->checkpointRebuild($state);
                $this->armRebuildContinuation();
            }
            if (!$result['refreshed']) {
                return false;
            }
            $this->checkpointRebuild($result['state']);
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, abj_clock()->now(), 86400);
            $this->stallWatchdog->clearStallState();
            $this->renewRebuildLease();
            $this->progress->clear();
            return true;
        } catch (ABJ_404_Solution_LogsHitsRebuildYield $e) {
            // Admission/recovery can exhaust the request before a SQL unit
            // starts. Preserve its checkpoint and arm the same continuation.
            // A yield is the healthy liveness signal of a budgeted drain, not
            // a failure: it is recorded as a durable INFO line (a WARN would
            // read as a fault on every healthy slice, and a debug line is lost
            // while debug_mode is off).
            $this->logger->infoMessage('Logs-hits rebuild yielded before a SQL unit started (code '
                . $e->getCode() . '): ' . $e->getMessage()
                . ' Checkpoint preserved; continuation armed.');
            $this->armRebuildContinuation();
            return false;
        } catch (Throwable $e) {
            // The rebuild engine self-handles its own SQL/Throwable failures and
            // signals them via the return value; reaching here means an
            // admission check or bookkeeping step (freshness flag, staleness
            // clear, denorm write-back) threw. Return false rather than letting
            // the exception escape the cron / shutdown listener.
            $this->logger->warn(__FUNCTION__ . ' rebuild coordination failed: '
                . get_class($e) . ' (' . $e->getCode() . '): ' . $e->getMessage());
            if ($this->progress->hasPending()) {
                $this->armRebuildContinuation();
            }
            return false;
        } finally {
            try {
                $this->rebuildLock->release();
            } finally {
                if ($this->rebuildHealth !== null) {
                    $this->rebuildHealth->endExpensiveRebuildAttempt();
                }
            }
        }
    }

    /**
     * Adopt only an intentionally yielded generation. A dead worker's private
     * scratch is discarded; an already-published generation instead resumes
     * its idempotent derived-column fan-out.
     * @return array{refreshed:bool,pending:bool,state:State,elapsed_time:float,error:string}
     */
    private function runRebuildSlice(ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $state = $this->progress->read();
        $afterPublication = $state !== null
            && in_array($state['phase'], array('denormalize', 'cleanup', 'complete'), true);
        if ($state !== null && ($state['status'] === 'running' || $afterPublication)) {
            if ($this->rebuilder->isPublishedGeneration($state, $budget)) {
                if (!$afterPublication) { $state['phase'] = 'denormalize'; }
            } else {
                if ($afterPublication) {
                    $this->logger->warn('Logs-hits checkpoint has no matching published generation; rebuilding its snapshot.');
                }
                $this->rebuilder->discard($state, $budget);
                $state = null;
            }
        }
        if ($state === null) {
            // Snapshot acquisition is rebuild work too. Defer before either
            // bounded aggregate if admission or the preceding read used up
            // this callback's scheduling window.
            $budget->timeoutSeconds();
            $maxId = $this->getMaxLogId();
            $budget->timeoutSeconds();
            $minId = $this->getMinLogId();
            $state = $this->progress->start(array('min_log_id' => $minId, 'max_log_id' => $maxId,
                'chunk_size' => $this->rebuilder->getHitsRebuildChunkSize($maxId - $minId)));
        }
        $state['status'] = 'running';
        $this->checkpointRebuild($state);
        return $this->rebuilder->rebuildAndSwap($state['min_log_id'], $state['max_log_id'], array(
            'state' => $state, 'budget' => $budget,
            'checkpoint' => Closure::fromCallable(array($this, 'checkpointRebuild')),
        ));
    }

    /** @param State $state */
    private function checkpointRebuild(array $state): void {
        $this->renewRebuildLease();
        $this->progress->write($state);
        $this->renewRebuildLease();
    }

    /** Fence checkpoint writes and publication bookkeeping against a new owner. */
    private function renewRebuildLease(): void {
        if (!$this->rebuildLock->checkAndRenew()) {
            throw new RuntimeException('Logs-hits coordinator lost its exclusive lease.', 409);
        }
    }

    /** Schedule an actual continuation without replacing an overdue cron event. */
    private function armRebuildContinuation(): void {
        $scheduler = abj_cron_scheduler();
        if (!$scheduler->scheduleSingleIfMissing(self::UPDATE_LOGS_HITS_TABLE_HOOK, 5)) {
            $this->logger->warn('Could not schedule logs-hits rebuild continuation: ' . $scheduler->lastFailureDetail());
        }
    }

    // =========================================================================
    // Table existence + scheduling
    // =========================================================================

    /** @inheritDoc */
    public function logsHitsTableExists() { return $this->metadata->exists(); }

    /** @inheritDoc */
    public function scheduleHitsTableRebuild(): void {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->mayStartExpensiveRebuild()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed."); return; }
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); return; }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->rebuildLock->isHeld()) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running."); return; }
            $rawScheduledFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (abj_clock()->now() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown.");
            } else {
                self::$hitsTableRebuildScheduled = true;
                $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, abj_clock()->now(), 86400);
                // A full rollup rebuild is background work in every request
                // context. PHP/LSAPI servers may send response headers early but
                // buffer the body until WordPress shutdown callbacks finish, so a
                // shutdown rebuild can keep the admin page blank for the entire
                // database operation. WP-Cron is therefore where this pipeline
                // is meant to run.
                $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron.");
                abj_cron_scheduler()->scheduleSingle(
                    ABJ_404_Solution_CronScheduler::HOOK_UPDATE_LOGS_HITS_TABLE,
                    5
                );
            }
        }
        // Armed is not executed. A host whose wp-cron.php loopback is refused
        // stores the event and never runs it, and this method would otherwise
        // re-arm it forever while the watermark stays where it was, which is
        // production report 395. The watchdog opens a post-response path on
        // evidence of exactly that, and detaches the response before using it,
        // so the buffered-body hazard above cannot occur on that path either.
        $this->stallWatchdog->armDeferredRebuildIfCronIsNotExecuting();
    }


    // =========================================================================
    // logsv2 / logs_hits id queries
    // =========================================================================

    /** @inheritDoc */
    public function getMaxLogId() {
        // allow-unbounded-select: MAX(id) aggregate; returns a single row
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query, array(
            'timeout' => ABJ_404_Solution_LogsHitsRebuildBudget::MAX_TIMEOUT_SECONDS,
        ));
        return (new ABJ_404_Solution_LogsReadResult($results, 'getMaxLogId'))->integerAggregate(true);
    }

    /** @inheritDoc */
    public function getMinLogId() {
        // allow-unbounded-select: MIN(id) aggregate; returns a single row
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query, array(
            'timeout' => ABJ_404_Solution_LogsHitsRebuildBudget::MAX_TIMEOUT_SECONDS,
        ));
        return (new ABJ_404_Solution_LogsReadResult($results, 'getMinLogId'))->integerAggregate(true);
    }

    /** @inheritDoc */
    public function getStoredMaxLogId() { return $this->metadata->storedMaxLogId(); }

    /** @inheritDoc */
    public function getLogsHitsTableLastScheduledAt() { $rawTsFlag2 = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG); $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0; return $ts > 0 ? $ts : null; }
}
