<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site-wide evidence that WP-Cron is not dispatching its queue.
 *
 * WordPress runs every due event, whichever plugin queued it, so one stored
 * event more than OVERDUE_SECONDS past due proves that nothing is dispatching
 * (DISABLE_WP_CRON with no system cron, a blocked loopback). That is a fact
 * about the site, not about one hook: every plugin backfill queued afterwards
 * is just as stranded, even seconds after it was queued.
 *
 * The proof is recorded once observed, because the event that proved it is
 * often the very work an admin-view consumer then completes and consumes. The
 * record is retired only by the opposite observation: a request WordPress
 * itself dispatched as cron. A stale record therefore costs at most an admin
 * view doing work cron was also about to do, under the same locks; it never
 * makes work run twice or out of order.
 *
 * Consumers: QueuedCronEventAdminContinuation (min_log_id backfill, admin
 * table repair), NGramCronFallback and LogsHitsRollupStallWatchdog. It is
 * the only place the overdue threshold is applied.
 */
final class ABJ_404_Solution_CronDispatchStallEvidence {

    /** How far past due an event must be before it proves no dispatch. */
    public const OVERDUE_SECONDS = 900;

    /** Durable record of the observed stall; not autoloaded. */
    public const OPTION = 'abj404_cron_dispatch_stall_observed';

    /** @var ABJ_404_Solution_CronScheduler */
    private $cron;

    /** @var ABJ_404_Solution_ScheduledEventInspector */
    private $inspector;

    /**
     * @param ABJ_404_Solution_CronScheduler $cron Supplies the scheduler's clock.
     * @param ABJ_404_Solution_ScheduledEventInspector|null $inspector Reads the
     *   stored queue; defaults to a plain one, which owns no state.
     */
    public function __construct(
        ABJ_404_Solution_CronScheduler $cron,
        ?ABJ_404_Solution_ScheduledEventInspector $inspector = null
    ) {
        $this->cron = $cron;
        $this->inspector = $inspector ?? new ABJ_404_Solution_ScheduledEventInspector();
    }

    /**
     * Whether this site has proved, now or earlier, that WP-Cron is not
     * dispatching. Reads the in-request cron store; callers that cross
     * requests refresh the store reads first.
     *
     * @param int|null $observedEventTimestamp A stored event the caller has
     *   already read through the scheduler (its own queued work). It is one
     *   more stored event, judged by the same threshold as the rest, so no
     *   caller keeps a private overdue check beside this one.
     */
    public function isProven(?int $observedEventTimestamp = null): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $record = ABJ_404_Solution_OptionShapeRead::arrayValue(self::OPTION, null);
        if (is_array($record) && ($record['version'] ?? null) === 1) {
            return true;
        }
        $oldest = $this->inspector->oldestStoredEventTimestamp();
        if ($observedEventTimestamp !== null) {
            $oldest = $oldest === null ? $observedEventTimestamp : min($oldest, $observedEventTimestamp);
        }
        $now = $this->cron->now();
        if ($oldest === null || $now - $oldest <= self::OVERDUE_SECONDS) {
            return false;
        }
        if (function_exists('update_option')) {
            update_option(self::OPTION, array(
                'version' => 1,
                'observed_at' => $now,
                'event_timestamp' => $oldest,
            ), false);
        }
        return true;
    }

    /**
     * WordPress boundary on `init`: a request WordPress dispatched as cron
     * proves dispatch works again, so the recorded stall is retired.
     */
    public static function observeRequest(): void {
        if (!function_exists('wp_doing_cron') || !wp_doing_cron() || !function_exists('delete_option')) {
            return;
        }
        delete_option(self::OPTION);
    }
}
