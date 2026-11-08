<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/QueuedCronEventAdminContinuation.php';
require_once __DIR__ . '/CronDispatchStallEvidence.php';

/**
 * Coordinates foreground, cron and post-response slices of one minimum-log pass.
 * SQL and cursor persistence stay with the migration component. The queued
 * no-args event remains queued until completion; on a site whose WP-Cron is
 * proved stalled, ordinary admin views consume it through
 * ABJ_404_Solution_QueuedCronEventAdminContinuation, each running batches
 * until that continuation's deadline rather than stopping at the cron slice's
 * batch cap.
 */
final class ABJ_404_Solution_MinLogIdBackfillContinuation {
    private const BUDGET_SECONDS = 1.0;
    private const MAX_BATCHES = 20;
    private ABJ_404_Solution_SynchronizationUtils $sync;
    private ABJ_404_Solution_Clock $clock;
    private ABJ_404_Solution_CronScheduler $cron;
    private ABJ_404_Solution_Logging $logger;
    /** @var callable():array{complete:bool,progressed:bool} */
    private $batch;
    /** @var callable():(array{complete:bool}|null) */
    private $state;
    /** @var callable():void */
    private $schedule;
    /** @var string */
    private $lockKey;
    /** @var ABJ_404_Solution_QueuedCronEventAdminContinuation|null */
    private $adminContinuation = null;

    /**
     * @param array{table:string,sync:ABJ_404_Solution_SynchronizationUtils,
     * clock:ABJ_404_Solution_Clock,cron:ABJ_404_Solution_CronScheduler,
     * logger:ABJ_404_Solution_Logging,batch:callable():array{complete:bool,progressed:bool},
     * state:callable():(array{complete:bool}|null),schedule:callable():void} $dependencies
     */
    public function __construct(array $dependencies) {
        $this->sync = $dependencies['sync'];
        $this->clock = $dependencies['clock'];
        $this->cron = $dependencies['cron'];
        $this->logger = $dependencies['logger'];
        $this->batch = $dependencies['batch'];
        $this->state = $dependencies['state'];
        $this->schedule = $dependencies['schedule'];
        $this->lockKey = 'min_log_id_backfill:' . $dependencies['table'];
    }

    /** Preserve the foreground upgrade's single-batch contract. */
    public function runForeground(): void {
        if (!$this->withLock(function (): void {
            $outcome = ($this->batch)();
            if (!$outcome['complete'] && !$outcome['progressed']) {
                ($this->schedule)();
            }
        })) {
            ($this->schedule)();
        }
    }

    /** Cron retains its bounded slice and cannot race a foreground/page worker. */
    public function runScheduled(): void {
        if (!$this->withLock(function (): void { $this->runScheduledLocked(); })) {
            ($this->schedule)();
        }
    }

    /** Admit the queued pass on an authorized ordinary admin view of a cron-stalled site. */
    public function arm(): bool {
        return $this->adminContinuation()->arm();
    }

    /** Post-response slice of the pass admitted by arm(). */
    public function runAfterResponse(): void {
        $this->adminContinuation()->runAfterResponse();
    }

    private function adminContinuation(): ABJ_404_Solution_QueuedCronEventAdminContinuation {
        if ($this->adminContinuation === null) {
            $this->adminContinuation = new ABJ_404_Solution_QueuedCronEventAdminContinuation(array(
                'hook' => ABJ_404_Solution_CronScheduler::HOOK_LOGSV2_MIN_LOG_ID_BACKFILL,
                'lockKey' => $this->lockKey,
                'label' => 'min_log_id',
                'sync' => $this->sync,
                'clock' => $this->clock,
                'cron' => $this->cron,
                'logger' => $this->logger,
                'pending' => function (): bool {
                    $state = ($this->state)();
                    return $state !== null && !$state['complete'];
                },
                'slice' => function (float $deadline): array {
                    return $this->runUntil($deadline);
                },
                'describe' => function (): array {
                    $state = ($this->state)();
                    return $state === null ? array('state' => 'missing') : $state;
                },
            ));
        }
        return $this->adminContinuation;
    }

    /**
     * Admin-view slice: batches until the deadline, at least one.
     *
     * @return array{complete:bool,progressed:bool}
     */
    private function runUntil(float $deadline): array {
        $progressed = false;
        do {
            $outcome = ($this->batch)();
            if ($outcome['complete']) {
                return array('complete' => true, 'progressed' => true);
            }
            if (!$outcome['progressed']) {
                ($this->schedule)();
                return array('complete' => false, 'progressed' => $progressed);
            }
            $progressed = true;
        } while ($this->clock->nowFloat() < $deadline);
        ($this->schedule)();
        return array('complete' => false, 'progressed' => true);
    }

    private function runScheduledLocked(): void {
        $startedAt = $this->clock->nowFloat();
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            if ($batch > 0 && $this->clock->nowFloat() - $startedAt >= self::BUDGET_SECONDS) {
                break;
            }
            $outcome = ($this->batch)();
            if ($outcome['complete']) { return; }
            if (!$outcome['progressed']) {
                ($this->schedule)();
                return;
            }
        }
        ($this->schedule)();
    }

    /** @param callable():void $work */
    private function withLock(callable $work): bool {
        $sync = $this->sync;
        $lock = $sync->synchronizerAcquireLockTry($this->lockKey);
        if (empty($lock)) { return false; }
        try { $work(); }
        finally { $sync->synchronizerReleaseLock($lock, $this->lockKey); }
        return true;
    }
}
