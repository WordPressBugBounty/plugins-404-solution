<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: public daily-listener convergence, replay and refusal coverage in tests/MaintenanceContinuationIntegrationTest.php.
/**
 * Runs one maintenance phase per WP-Cron dispatch, under a shared pass lock.
 * A durable continuation is armed BEFORE work. If a worker dies after a SQL
 * commit, the same phase resumes against live data and cannot replay a saved
 * deletion count. Finished phases are checkpointed before the lock is released.
 * @phpstan-import-type LogRetentionPlan from ABJ_404_Solution_RedirectsRetentionPolicy
 * @phpstan-type MaintenancePass array{version:1,type:'maintenance',id:non-empty-string,
 * created_at:int,updated_at:int,deleted_at:int|null,deadline:int,runs:non-negative-int,
 * phase:string,removed:array<array-key,non-negative-int>,log_plan:LogRetentionPlan|null,manual:bool,
 * duplicate_scan?:array{after_id:non-negative-int,max_id:non-negative-int}}
 */
final class ABJ_404_Solution_MaintenanceDrain {
    private const MAX_RUNS = 4096;
    private const LOCK = 'maintenance_pass';
    private ABJ_404_Solution_MaintenancePassRepository $store;
    private ABJ_404_Solution_SynchronizationUtils $sync;
    private ABJ_404_Solution_CronScheduler $cron;
    private ABJ_404_Solution_Logging $logger;
    /** @var array<string,callable(array<string,mixed>):array<string,mixed>> */
    private array $stages;
    private ABJ_404_Solution_RedirectsRetentionService $retention;
    /** @var ABJ_404_Solution_DatabaseUpgradeDailyMaintenance */
    private $database;

    /**
     * @param array{store:ABJ_404_Solution_MaintenancePassRepository,sync:ABJ_404_Solution_SynchronizationUtils,
     * cron:ABJ_404_Solution_CronScheduler,logger:ABJ_404_Solution_Logging,
     * retention:ABJ_404_Solution_RedirectsRetentionService,database:ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} $dependencies
     */
    public function __construct(array $dependencies) {
        $this->store = $dependencies['store'];
        $this->sync = $dependencies['sync'];
        $this->cron = $dependencies['cron'];
        $this->logger = $dependencies['logger'];
        $this->retention = $dependencies['retention'];
        $this->database = $dependencies['database'];
        $this->stages = $this->maintenanceStages();
    }

    /**
     * Join retention and DB phase registries into one owned pass.
     *
     * A database stage is one run of its phase and, by default, the whole phase:
     * whatever it returns, the pass moves on. A stage that bounds its own work
     * and has more to do returns `['complete' => false]`, and the pass runs the
     * same phase again through the continuation it already arms before every
     * phase, so a bounded stage is never left for the next night to finish.
     *
     * @return array<string,callable>
     */
    private function maintenanceStages(): array {
        $retention = $this->retention->maintenanceStages();
        $database = [];
        foreach ($this->database->maintenanceStages() as $name => $stage) {
            $database[$name] = static function () use ($stage): array {
                $result = $stage();
                return ['complete' => !(is_array($result) && ($result['complete'] ?? null) === false)];
            };
        }
        // Repair schema before a retention predicate can delete rows.
        return array_merge(['tables' => $retention['tables']],
            array_intersect_key($database, ['schema_insurance' => true]), $retention, $database, [
                // Calls the recovery class, not the cron listener function: a listener
                // added in a later release is undefined in a request whose boot is an
                // earlier one (BootDefinedFunctionConsumersTest). A failure is logged
                // and the phase still completes, as the listener's own catch did.
                'legacy_pages' => static function (): array {
                    try {
                        (new ABJ_404_Solution_LegacySuggestionPageRecovery())->continuePendingBatch();
                    } catch (\Throwable $e) {
                        abj404_logRuntimeWarning('Maintenance legacy suggestion page recovery failed', $e);
                    }
                    return ['complete' => true];
                },
            ]);
    }

    /**
     * A no-args daily event may start a pass. A continuation must identify the
     * active, valid pass exactly; untrusted/malformed arguments never start work.
     * @param mixed $expectedId
     * @param array{manual?:bool} $context
     * @return MaintenancePass|null
     */
    public function tick($expectedId = null, array $context = []): ?array {
        if ($expectedId !== null && (!is_string($expectedId) || $expectedId === '')) {
            $this->logger->warn('Maintenance continuation rejected an invalid pass ID of type ' . gettype($expectedId));
            return null;
        }
        $lock = $this->sync->synchronizerAcquireLockTry(self::LOCK);
        if (empty($lock)) { return null; }
        try {
            return $this->runLockedTick($expectedId, $context);
        } finally {
            $this->sync->synchronizerReleaseLock($lock, self::LOCK);
        }
    }

    /**
     * @param array{manual?:bool} $context
     * @return MaintenancePass|null
     */
    private function runLockedTick(?string $expectedId, array $context): ?array {
        $stored = $this->store->read();
        if ($stored !== null && !$this->validPass($stored)) {
            $this->logger->warn('Maintenance saved pass is invalid or uses an unsupported version; preserving data.');
            return null;
        }
        $pass = $this->admitPass($stored, $expectedId, $context);
        if ($pass === null) { return null; }
        $pass = $this->retention->prepareMaintenanceStage($pass['phase'], $pass);
        $pass['runs']++;
        $pass['updated_at'] = $this->cron->now();
        $previousId = $stored === null ? null : $stored['id'];
        if (!$this->store->save($pass, $previousId)) {
            $this->logger->warn('Maintenance pass checkpoint refused before work: ' . $pass['id']
                . ': ' . $this->store->lastFailureDetail());
            return null;
        }
        if (!$this->arm($pass)) { return null; }
        $phasePass = $pass;
        $notificationConsumed = $phasePass['phase'] === 'notifications';
        $consumedRecord = null;
        if ($notificationConsumed) {
            // Mail is an informational, non-replayable callout. Consume this
            // phase while its original pass is still captured, so a checkpoint
            // failure after mail cannot make a later continuation send it again.
            $consumedRecord = $this->recordPhaseOutcome($pass, ['complete' => true, 'removed' => 0]);
            $pass = $consumedRecord['pass'];
            if (!$this->store->save($pass, $phasePass['id'])) {
                $this->logger->warn('Maintenance checkpoint refused before notification callout: ' . $phasePass['id']
                    . ': ' . $this->store->lastFailureDetail());
                return $phasePass;
            }
        }
        $outcome = ($this->stages[$phasePass['phase']])($phasePass);
        if (!is_array($outcome) || !isset($outcome['complete']) || !is_bool($outcome['complete'])) {
            throw new UnexpectedValueException('Maintenance phase returned no completion outcome: ' . $phasePass['phase']);
        }
        $failure = $outcome['failure'] ?? '';
        if (!is_string($failure) || $failure !== '') {
            $this->logger->warn('Maintenance phase held for retry: ' . $phasePass['phase'] . ': '
                . (is_string($failure) ? $failure : 'unreadable failure outcome'));
            return $pass;
        }
        if ($notificationConsumed) {
            /** @var array{pass:MaintenancePass,removed:non-negative-int} $consumedRecord */
            $this->logger->infoMessage('Maintenance slice: pass=' . $pass['id'] . ' next_phase=' . $pass['phase']
                . ' removed=' . $consumedRecord['removed'] . ' run=' . $pass['runs']);
            return $pass;
        }
        $recorded = $this->recordPhaseOutcome($pass, $outcome);
        $pass = $recorded['pass'];
        if (!$this->store->save($pass, $pass['id'])) {
            $this->logger->warn('Maintenance checkpoint refused after phase work; live rows will be re-read: ' . $pass['id']
                . ': ' . $this->store->lastFailureDetail());
        }
        $this->logger->infoMessage('Maintenance slice: pass=' . $pass['id'] . ' next_phase=' . $pass['phase']
            . ' removed=' . $recorded['removed'] . ' run=' . $pass['runs']);
        return $pass;
    }

    /**
     * Validate and apply a successful phase's progress before its durable checkpoint.
     * @param MaintenancePass $pass
     * @param array{complete:bool,removed?:mixed,duplicate_scan?:mixed} $outcome
     * @return array{pass:MaintenancePass,removed:non-negative-int}
     */
    private function recordPhaseOutcome(array $pass, array $outcome): array {
        $removed = ABJ_404_Solution_ExactInteger::read($outcome['removed'] ?? 0, 0);
        if ($removed === null || $removed < 0) { throw new UnexpectedValueException('Maintenance phase reported an invalid removed count.'); }
        if (array_key_exists('duplicate_scan', $outcome)) {
            if ($pass['phase'] !== 'duplicates' || !$this->validDuplicateScan($outcome['duplicate_scan'])) {
                throw new UnexpectedValueException('Maintenance duplicate phase returned an invalid scan checkpoint.');
            }
            $pass['duplicate_scan'] = $outcome['duplicate_scan'];
        }
        $pass['removed'][$pass['phase']] = ($pass['removed'][$pass['phase']] ?? 0) + $removed;
        if ($outcome['complete']) { $pass = $this->advance($pass); }
        return ['pass' => $pass, 'removed' => $removed];
    }

    /**
     * @param MaintenancePass|null $stored
     * @param array{manual?:bool} $context
     * @return MaintenancePass|null
     */
    private function admitPass(?array $stored, ?string $expectedId, array $context): ?array {
        if ($stored !== null) {
            if ($expectedId !== null && $expectedId !== $stored['id']) { return null; }
            if ($stored['deleted_at'] === null && $stored['deadline'] > $this->cron->now()
                && $stored['runs'] < self::MAX_RUNS) { return $stored; }
            if ($expectedId !== null) { return null; }
            if ($stored['deleted_at'] === null) {
                // A daily tick renews the SAME live predicate/size target. Starting
                // over from allocated InnoDB bytes would over-trim an interrupted
                // size pass whose deletion had already committed.
                $stored['deadline'] = $this->cron->now() + 86400;
                $stored['runs'] = 0;
                return $stored;
            }
        } elseif ($expectedId !== null) { return null; }
        $now = $this->cron->now();
        return ['version' => 1, 'type' => 'maintenance', 'id' => uniqid('maintenance-', true),
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            'deadline' => $now + 86400, 'runs' => 0, 'phase' => 'tables',
            'removed' => [], 'log_plan' => $stored['log_plan'] ?? null,
            'manual' => ($context['manual'] ?? false) === true];
    }

    /**
     * @param array<array-key,mixed> $pass
     * @phpstan-assert-if-true =MaintenancePass $pass
     */
    private function validPass(array $pass): bool {
        if (!$this->validPassHeader($pass) || !is_array($pass['removed'] ?? null)) { return false; }
        foreach ($pass['removed'] as $count) {
            if (!is_int($count) || $count < 0) { return false; }
        }
        if (array_key_exists('duplicate_scan', $pass) && !$this->validDuplicateScan($pass['duplicate_scan'])) {
            return false;
        }
        return array_key_exists('log_plan', $pass)
            && ABJ_404_Solution_RedirectsRetentionPolicy::storedSizePlanIsValid($pass['log_plan'])
            && (($pass['phase'] === 'complete') === ($pass['deleted_at'] !== null));
    }

    /**
     * An absent cursor is an older pass; a present malformed cursor must never restart destructive work.
     * @param mixed $scan
     * @phpstan-assert-if-true array{after_id:non-negative-int,max_id:non-negative-int} $scan
     */
    private function validDuplicateScan($scan): bool {
        return is_array($scan) && is_int($scan['after_id'] ?? null) && is_int($scan['max_id'] ?? null)
            && $scan['after_id'] >= 0 && $scan['max_id'] >= $scan['after_id'];
    }

    /** @param array<array-key,mixed> $pass Check identity and lifecycle independently of payloads. */
    private function validPassHeader(array $pass): bool {
        foreach (['created_at', 'updated_at', 'deadline', 'runs'] as $key) {
            if (!is_int($pass[$key] ?? null)) { return false; }
        }
        return ($pass['version'] ?? null) === 1 && ($pass['type'] ?? null) === 'maintenance'
            && is_string($pass['id'] ?? null) && $pass['id'] !== ''
            && array_key_exists('deleted_at', $pass) && ($pass['deleted_at'] === null || is_int($pass['deleted_at']))
            && $pass['runs'] >= 0
            && is_string($pass['phase'] ?? null) && ($pass['phase'] === 'complete' || isset($this->stages[$pass['phase']]))
            && is_bool($pass['manual'] ?? null);
    }

    /** @param MaintenancePass $pass */
    private function arm(array $pass): bool {
        $ok = $this->cron->scheduleSingleIfMissing(ABJ_404_Solution_CronScheduler::HOOK_CLEANUP, 10, [$pass['id']]);
        if (!$ok) {
            $this->logger->warn('Maintenance continuation refused before work: ' . $pass['id'] . ': ' . $this->cron->lastFailureDetail());
        }
        return $ok;
    }

    /**
     * @param MaintenancePass $pass
     * @return MaintenancePass
     */
    private function advance(array $pass): array {
        $phases = array_keys($this->stages);
        $index = array_search($pass['phase'], $phases, true);
        $pass['phase'] = $phases[$index + 1] ?? 'complete';
        if ($pass['phase'] === 'complete') { $pass['deleted_at'] = $this->cron->now(); }
        return $pass;
    }
}
