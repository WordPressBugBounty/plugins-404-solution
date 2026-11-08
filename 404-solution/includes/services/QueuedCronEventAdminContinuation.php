<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../policies/PluginAdminAuthorization.php';

/**
 * The cron-independent consumer of one queued cron event (one hook and its
 * exact args).
 *
 * Durable plugin work (an upgrade backfill, the admin-table repair) is queued
 * as a WP-Cron event and resumes from persisted state. On a site where WP-Cron
 * never dispatches, that event has no consumer at all unless an ordinary
 * request runs it. This class is that consumer: on an authorized plugin admin
 * page render whose queued event is due while the site has proved cron is
 * stalled, it registers one post-response slice that
 *
 *   - detaches the delivered response,
 *   - takes the same lock the cron listener takes,
 *   - rechecks that the exact admitted event is still queued,
 *   - runs the owner's slice until its deadline, and
 *   - consumes that exact event only when the owner reports completion.
 *
 * A slice that makes no progress (or fails) leaves the event queued, so the
 * work is never dropped, but it pauses this hook's admin-view admission for
 * COOLDOWN_SECONDS and logs one warning with the owner's state: a step that
 * cannot advance must not be retried on every admin view. A slice that does
 * progress retires the pause. Cron dispatch never reads it.
 *
 * The slice deadline, AFTER_RESPONSE_BUDGET_SECONDS, bounds how long the
 * already-detached PHP worker stays busy for each continuation. Owners start
 * no step after it: a step is a bounded row batch (500 log rows, 1000
 * redirect rows, 25 legacy pages) or one table's DDL (an index pass, one
 * table's collation conversion), so the overrun past the deadline is one step,
 * the same step the owner's cron tick would run.
 */
final class ABJ_404_Solution_QueuedCronEventAdminContinuation {

    /** Worker seconds one admin view may spend on this continuation. */
    public const AFTER_RESPONSE_BUDGET_SECONDS = 5.0;

    /** How long a no-progress slice pauses this hook's admin-view admission. */
    public const COOLDOWN_SECONDS = 3600;

    /** Version of the persisted cooldown record. */
    private const COOLDOWN_VERSION = 1;

    /** @var string */
    private $hook;
    /** @var list<string|int> Exact args of the queued event (empty for a no-args hook). */
    private $args;
    /** @var string */
    private $lockKey;
    /** @var string */
    private $label;
    /** @var ABJ_404_Solution_SynchronizationUtils */
    private $sync;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var ABJ_404_Solution_CronScheduler */
    private $cron;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var callable():bool */
    private $pending;
    /** @var callable(float):array{complete:bool,progressed:bool} */
    private $slice;
    /** @var callable():array<string,mixed> */
    private $describe;
    /** @var int|null Exact event admitted by this request. */
    private $eventTimestamp = null;

    /**
     * @param array{hook:string,lockKey:string,label:string,
     * sync:ABJ_404_Solution_SynchronizationUtils,clock:ABJ_404_Solution_Clock,
     * cron:ABJ_404_Solution_CronScheduler,logger:ABJ_404_Solution_Logging,
     * pending:callable():bool,
     * slice:callable(float):array{complete:bool,progressed:bool},
     * describe?:callable():array<string,mixed>,args?:list<string|int>} $options
     *   `pending` reports whether real persisted state still needs this work;
     *   `slice` runs it until the absolute deadline it receives (clock
     *   nowFloat seconds) under the lock, and reports real completion;
     *   `describe` reports the owner's persisted state for the no-progress
     *   warning; `args` names the exact event of a hook queued once per
     *   scope, and gives that scope its own admission pause.
     */
    public function __construct(array $options) {
        $this->hook = $options['hook'];
        $this->args = array_values($options['args'] ?? array());
        $this->lockKey = $options['lockKey'];
        $this->label = $options['label'];
        $this->sync = $options['sync'];
        $this->clock = $options['clock'];
        $this->cron = $options['cron'];
        $this->logger = $options['logger'];
        $this->pending = $options['pending'];
        $this->slice = $options['slice'];
        $this->describe = $options['describe'] ?? static function (): array { return array(); };
    }

    /**
     * Transient that pauses admin-view admission for one queued event.
     *
     * @param list<string|int> $args The event's exact args; a no-args hook keeps its plain name.
     */
    public static function cooldownTransient(string $hook, array $args = array()): string {
        $name = 'abj404_admin_continuation_cooldown_' . $hook;
        return $args === array() ? $name : $name . '_' . substr(md5((string)json_encode(array_values($args))), 0, 12);
    }

    /**
     * Admit the queued event on an authorized ordinary admin page render.
     * Cheap checks run first; the owner's pending probe runs last.
     */
    public function arm(): bool {
        if ($this->eventTimestamp !== null || !function_exists('add_action')
                || !ABJ_404_Solution_RequestKind::isAdminPageRender()
                || !ABJ_404_Solution_PluginAdminAuthorization::currentUserCanAccessPluginAdmin()) {
            return false;
        }
        if ($this->coolingDown()) {
            return false;
        }
        $event = $this->admissibleEvent();
        if ($event === null || !($this->pending)()) {
            return false;
        }
        $this->eventTimestamp = $event;
        add_action('shutdown', array($this, 'runAfterResponse'), PHP_INT_MAX, 0);
        return true;
    }

    /** Run the admitted slice after the response, under the cron listener's lock. */
    public function runAfterResponse(): void {
        $eventTimestamp = $this->eventTimestamp;
        if ($eventTimestamp === null) { return; }
        try {
            ABJ_404_Solution_ResponseDetach::detach();
            $lock = $this->sync->synchronizerAcquireLockTry($this->lockKey);
            if (empty($lock)) { return; }
            try {
                $this->runLocked($eventTimestamp);
            } finally {
                $this->sync->synchronizerReleaseLock($lock, $this->lockKey);
            }
        } catch (Throwable $error) {
            $this->pause('failed: ' . get_class($error) . ' code=' . (string)$error->getCode()
                . ': ' . $error->getMessage());
        }
    }

    private function runLocked(int $eventTimestamp): void {
        // dueEvent() refreshes the option caches before observing cross-request state.
        if ($this->dueEvent() !== $eventTimestamp) {
            return;
        }
        // Persistent caches can retain this worker's earlier absent transient.
        // Refresh that local entry without deleting another worker's pause.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
                && function_exists('wp_cache_get')) {
            wp_cache_get(self::cooldownTransient($this->hook, $this->args), 'transient', true);
        }
        if ($this->coolingDown() || !($this->pending)()) {
            return;
        }
        $deadline = $this->clock->nowFloat() + self::AFTER_RESPONSE_BUDGET_SECONDS;
        $outcome = ($this->slice)($deadline);
        if (!$outcome['complete'] && !$outcome['progressed']) {
            $this->pause('made no progress');
            return;
        }
        $this->resume();
        if ($outcome['complete']) {
            $this->cron->consumeExactEvent(array(
                'timestamp' => $eventTimestamp,
                'hook' => $this->hook,
                'args' => $this->args,
            ));
        }
    }

    /** Whether a recorded no-progress pause for this hook is still in force. */
    private function coolingDown(): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        $record = get_transient(self::cooldownTransient($this->hook, $this->args));
        if (!is_array($record)) {
            return false;
        }
        return ($record['version'] ?? null) === self::COOLDOWN_VERSION
            && is_int($record['until'] ?? null) && $this->clock->now() < $record['until'];
    }

    /**
     * Pause admin-view admission for COOLDOWN_SECONDS and say so once. The
     * pause gates admission, so the next warning can only follow the next
     * attempt after it expires.
     */
    private function pause(string $outcome): void {
        $until = $this->clock->now() + self::COOLDOWN_SECONDS;
        $state = $this->describeState();
        if (function_exists('set_transient')) {
            // allow-cache-empty: the record is built here, not fetched; only 'until' (clock time) gates admission and 'state' is diagnostic.
            set_transient(self::cooldownTransient($this->hook, $this->args), array(
                'version' => self::COOLDOWN_VERSION, 'until' => $until, 'state' => $state,
            ), self::COOLDOWN_SECONDS);
        }
        $encoded = json_encode($state);
        $this->logger->warn('Post-response ' . $this->label . ' continuation ' . $outcome
            . '; admin-view retries paused until ' . gmdate('Y-m-d\\TH:i:s\\Z', $until)
            . ' (the queued event stays for cron); state=' . ($encoded === false ? 'unencodable' : $encoded));
    }

    /** A slice that progressed retires any earlier pause. */
    private function resume(): void {
        if (function_exists('get_transient') && function_exists('delete_transient')
                && get_transient(self::cooldownTransient($this->hook, $this->args)) !== false) {
            delete_transient(self::cooldownTransient($this->hook, $this->args));
        }
    }

    /** @return array<string,mixed> The owner's state, or the reason it is unreadable. */
    private function describeState(): array {
        try {
            return ($this->describe)();
        } catch (Throwable $error) {
            return array('unreadable' => get_class($error) . ' code=' . (string)$error->getCode()
                . ': ' . $error->getMessage());
        }
    }

    /** The queued event, when it is due and the site has proved cron is stalled. */
    private function admissibleEvent(): ?int {
        $event = $this->dueEvent();
        if ($event === null) { return null; }
        return (new ABJ_404_Solution_CronDispatchStallEvidence($this->cron))->isProven($event) ? $event : null;
    }

    private function dueEvent(): ?int {
        $this->cron->refreshStoredEventReads();
        $timestamp = ABJ_404_Solution_ExactInteger::read($this->cron->nextScheduled($this->hook, $this->args), 0);
        return $timestamp !== null && $timestamp <= $this->clock->now() ? $timestamp : null;
    }
}
