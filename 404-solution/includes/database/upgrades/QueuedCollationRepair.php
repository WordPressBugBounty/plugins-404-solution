<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../../services/QueuedCronEventAdminContinuation.php';

/**
 * The consumers of the queued plugin-table collation repair
 * (abj404_repair_collations).
 *
 * The DAO schedules that event when a query fails on a collation mix, because
 * the repair ALTERs every drifted plugin table and must not run inside the
 * request that noticed it. Two consumers run the same step,
 * DatabaseUpgradeCollationDrift::correctCollations(), under the same lock:
 *
 *   - the WP-Cron listener, every drifted table in one tick;
 *   - on a site whose WP-Cron is proved stalled, ordinary authorized admin
 *     views through ABJ_404_Solution_QueuedCronEventAdminContinuation, each
 *     slice starting no table after its deadline.
 *
 * The event stays queued until every drifted table has converted. A slice
 * that converts nothing pauses admin-view admission with one warning naming
 * the tables still unconverted.
 */
final class ABJ_404_Solution_QueuedCollationRepair {

    /** Serializes the cron tick and the admin-view slice. */
    public const LOCK_KEY = 'collation_repair';

    /** @var ABJ_404_Solution_DatabaseUpgradeCollationDrift */
    private $drift;

    /** @var array{complete:bool,progressed:bool,unconverted:list<string>}|null Last slice outcome, for the pause warning. */
    private $lastOutcome = null;

    public function __construct(ABJ_404_Solution_DatabaseUpgradeCollationDrift $drift) {
        $this->drift = $drift;
    }

    /**
     * WP-Cron listener body. A tick that finds the lock held re-queues the
     * event rather than racing the holder's ALTER on the same table.
     */
    public function runScheduled(): void {
        $sync = abj_service('sync_utils');
        $lock = $sync->synchronizerAcquireLockTry(self::LOCK_KEY);
        if ($lock === '') {
            $this->requeue();
            return;
        }
        try {
            $this->drift->correctCollations();
        } finally {
            $sync->synchronizerReleaseLock($lock, self::LOCK_KEY);
        }
    }

    /**
     * Admit the queued repair on an authorized ordinary admin page render of
     * a site whose WP-Cron is proved stalled.
     *
     * @return bool Whether a post-response slice was registered.
     */
    public function arm(): bool {
        return (new ABJ_404_Solution_QueuedCronEventAdminContinuation(array(
            'hook' => ABJ_404_Solution_CronScheduler::HOOK_REPAIR_COLLATIONS,
            'lockKey' => self::LOCK_KEY,
            'label' => 'collation repair',
            'sync' => abj_service('sync_utils'),
            'clock' => abj_clock(),
            'cron' => abj_cron_scheduler(),
            'logger' => abj_service('logging'),
            // The queued event is this repair's only persisted request; the
            // slice's own discovery decides whether any table still drifts.
            'pending' => static function (): bool { return true; },
            'slice' => function (float $deadline): array {
                $this->lastOutcome = $this->drift->correctCollations($deadline);
                return array('complete' => $this->lastOutcome['complete'],
                    'progressed' => $this->lastOutcome['progressed']);
            },
            'describe' => function (): array {
                return $this->lastOutcome === null ? array('outcome' => 'none')
                    : array('unconverted' => $this->lastOutcome['unconverted']);
            },
        )))->arm();
    }

    private function requeue(): void {
        $scheduler = abj_cron_scheduler();
        if (!$scheduler->scheduleSingleIfMissing(ABJ_404_Solution_CronScheduler::HOOK_REPAIR_COLLATIONS, 1)) {
            abj_service('logging')->warn('Collation repair continuation could not be re-queued: '
                . $scheduler->lastFailureDetail());
        }
    }
}
