<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Watches whether the wp_abj404_logs_hits rollup is actually converging, and
 * acts when it is not.
 *
 * The rollup is rebuilt by one cron listener (abj404_updateLogsHitsTableAction,
 * wired in 404-solution.php and defined in includes/root-boot/CronListeners.php),
 * and only a completed rebuild stamps the watermark that
 * ABJ_404_Solution_LogsHitsRollupService::getStoredMaxLogId() reads back out of
 * the table COMMENT. Arming that event is not the same as running it: on a host
 * whose wp-cron.php loopback is refused, or which sets DISABLE_WP_CRON without
 * a system cron behind it, the event is stored, never executes, and every later
 * page view simply re-arms it. Production report 395 (woodywoodweb.com, plugin
 * 4.3.5) is that state observed after five weeks: the rollup table existed since
 * 2026-07-30, MAX(logsv2.id) was 6932 -- far too small for a rebuild to be
 * failing on cost -- and the stored watermark was still 0, so the Page Redirects
 * screen had served stale Hits and Last Used columns since the table was made.
 *
 * Two things had to be true at once for that to last five weeks, and this class
 * owns both of them:
 *
 *   1. NOTHING ELSE COULD REBUILD IT. There is exactly one other moment at which
 *      the work is safe to do: after the response has been handed back to the
 *      web server, on an administrator's page render (never a visitor's
 *      front-end request, ordinary AJAX call, or cron itself). The one
 *      authenticated stats endpoint may explicitly opt in after its own gates.
 *      armDeferredRebuildIfCronIsNotExecuting() opens that path, and only on
 *      evidence -- the plugin's own event is stored and due AND the site has
 *      proved WP-Cron is not dispatching (CronDispatchStallEvidence: some
 *      stored event more than OVERDUE_SECONDS past due, now or earlier), which
 *      no healthy host produces. The
 *      rebuild is detached from the response first
 *      (ABJ_404_Solution_ResponseDetach), so the "a shutdown rebuild blanks the
 *      admin page on LSAPI" hazard cannot occur: the page is already delivered.
 *      It is rate limited to one attempt per DEFERRED_REBUILD_COOLDOWN_SECONDS
 *      site-wide, claimed BEFORE the rebuild runs so a rebuild that dies still
 *      consumes its window.
 *
 *   2. NOBODY WAS TOLD. observe() measures how long the rollup has been behind
 *      and escalates to the plugin's own admin notice past
 *      NOTICE_THRESHOLD_SECONDS. The instant that measurement starts from is
 *      written durably, via setDurableFlag(), NOT as a transient: a transient
 *      start instant is forgotten on its own TTL, which restarts the clock on
 *      every observation gap wider than the TTL and makes a threshold beyond it
 *      unreachable. The notice goes to abj404_plugin_db_notice, the one payload
 *      abj404_show_plugin_db_notice() actually renders on the plugin's page.
 *
 * Deliberately owns no rebuild mechanics: the operation arrives as an injected
 * callable, so this class cannot grow a second copy of the gate/lock protocol
 * that ABJ_404_Solution_LogsHitsRollupService::createRedirectsForViewHitsTable()
 * owns, and there is no dependency back onto it.
 */
class ABJ_404_Solution_LogsHitsRollupStallWatchdog {

    /** @var string Notice-payload discriminator for a rollup that stopped converging. */
    const NOTICE_TYPE = 'logs_hits_rollup_stale';

    /** @var string Durable flag: when the rollup was first seen behind logsv2. */
    const FIRST_STALL_FLAG = 'abj404_logs_hits_first_stale_detected_at';

    /** @var string Runtime flag: when the post-response rebuild last claimed its window. */
    const DEFERRED_REBUILD_FLAG = 'abj404_logs_hits_deferred_rebuild_at';

    /**
     * How long the rollup may be behind before the admin is told. Short enough
     * that a genuinely broken site learns within one working session, long
     * enough that an ordinary rebuild in flight never raises it.
     *
     * @var int
     */
    const NOTICE_THRESHOLD_SECONDS = 3600;

    /**
     * Minimum wall-clock gap between two post-response rebuilds, site-wide.
     * Bounds the cost of the fallback to one rebuild per window no matter how
     * many admin page views happen inside it.
     *
     * @var int
     */
    const DEFERRED_REBUILD_COOLDOWN_SECONDS = 900;

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var callable():bool Runs a rebuild if one is still needed; true when the rollup was refreshed. */
    private $rebuildIfStillNeeded;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /** @var ABJ_404_Solution_ScheduledEventInspector */
    private $inspector;

    /** @var bool Whether this request already armed the post-response rebuild. */
    private $deferredArmed = false;

    /** @var callable():bool|null Whether a bounded generation needs another slice. */
    private $hasPendingRebuild;

    /** @var string Separates another rollup's deferred-work cooldown. */
    private $flagNamespace;

    /**
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState Owns flag and notice persistence.
     * @param callable():bool $rebuildIfStillNeeded Re-checks staleness and rebuilds.
     * @param array{logger?:ABJ_404_Solution_Logging|null,inspector?:ABJ_404_Solution_ScheduledEventInspector,
     *     hasPendingRebuild?:callable():bool,flagNamespace?:string} $options Named optional collaborators and cooldown scope.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState,
        callable $rebuildIfStillNeeded,
        array $options = array()
    ) {
        $this->noticeState = $noticeState;
        $this->rebuildIfStillNeeded = $rebuildIfStillNeeded;
        $this->logger = $options['logger'] ?? null;
        $this->inspector = $options['inspector'] ?? new ABJ_404_Solution_ScheduledEventInspector();
        $this->hasPendingRebuild = $options['hasPendingRebuild'] ?? null;
        $this->flagNamespace = $options['flagNamespace'] ?? '';
    }

    /**
     * Record one observation of how far the rollup is behind the log table, and
     * escalate to an admin notice once it has been behind for longer than
     * NOTICE_THRESHOLD_SECONDS.
     *
     * @param int $currentMaxLogId MAX(logsv2.id) as observed now.
     * @param int $storedMaxLogId The watermark the last completed rebuild stamped.
     * @return void
     */
    public function observe(int $currentMaxLogId, int $storedMaxLogId): void {
        if ($currentMaxLogId <= $storedMaxLogId) {
            $this->clearStallState();
            return;
        }
        $firstStall = $this->readFirstStallInstant();
        $now = abj_clock()->now();
        if ($firstStall <= 0) {
            $this->noticeState->setDurableFlag(self::FIRST_STALL_FLAG, $now);
            return;
        }
        $age = $now - $firstStall;
        if ($age >= self::NOTICE_THRESHOLD_SECONDS) {
            $this->raiseStallNotice($age);
        }
    }

    /**
     * Forget the stall measurement and withdraw any notice this watchdog
     * raised. Called when the rollup has caught up, so recovery is silent.
     *
     * @return void
     */
    public function clearStallState(): void {
        $this->noticeState->deleteDurableFlag(self::FIRST_STALL_FLAG);
        $this->noticeState->clearPluginDbNoticeIfType(self::NOTICE_TYPE);
    }

    /**
     * Open the post-response rebuild path, but only on evidence that the cron
     * event this plugin armed is not being executed. An explicitly authorized
     * bounded continuation may opt in from AJAX after its own request gates.
     *
     * @param bool $allowAjax Whether this trusted endpoint may use the path.
     * @return bool Whether a post-response rebuild was armed for this request.
     */
    public function armDeferredRebuildIfCronIsNotExecuting(bool $allowAjax = false): bool {
        if ($this->deferredArmed || !function_exists('add_action')) {
            return false;
        }
        // Only an administrator's page render may carry the rebuild by default:
        // cron runs it inline, ordinary AJAX requests must not hold a worker
        // open for it, and a visitor's front-end request (scheduleHitsTableRebuild()
        // is reached from redirect matching) must never pay for it. The one
        // authenticated bounded continuation may explicitly opt in. On a buffering SAPI
        // the page is detached first; on a streaming SAPI (neither finish
        // function exists) the page has already been sent as it was produced.
        $adminPage = ABJ_404_Solution_RequestKind::isAdminPageRender();
        $authorizedAjax = $allowAjax && ABJ_404_Solution_RequestKind::isAjax();
        if (!$adminPage && !$authorizedAjax) {
            return false;
        }
        $pending = $this->hasPendingRebuild !== null && (bool)call_user_func($this->hasPendingRebuild);
        if (!$pending && !$this->cronIsNotExecuting()) {
            return false;
        }
        // The cooldown admits a NEW rebuild. Charging it for each deliberately
        // yielded slice would strand a cron-dead host for fifteen minutes per
        // chunk. Existing progress gets one bounded slice per admin render.
        if (!$pending && !$this->claimDeferredRebuildWindow()) {
            return false;
        }
        $this->deferredArmed = true;
        // WordPress 6.9+ spawns cron at shutdown priority 10. Admit this
        // bounded slice first, before a spawned worker can take its lease.
        add_action('shutdown', function (): void {
            $this->runDeferredRebuild();
        }, 9);
        return true;
    }

    /**
     * Whether the rebuild event this plugin armed is due on a site that has
     * proved WP-Cron is not dispatching, so cron cannot be executing it.
     *
     * Behavioural rather than configuration-sniffing on purpose: DISABLE_WP_CRON
     * is set on plenty of sites that run cron perfectly well from a system
     * crontab, and it is unset on sites whose loopback request is refused with a
     * 403 by a security plugin. Only the store's own answer separates them, and
     * CronDispatchStallEvidence is the one place that reads it, so this
     * watchdog, the n-gram fallback and the queued-event continuations cannot
     * disagree about whether cron runs.
     *
     * @return bool
     */
    public function cronIsNotExecuting(): bool {
        try {
            $event = $this->inspector->currentEvent(
                ABJ_404_Solution_CronScheduler::HOOK_UPDATE_LOGS_HITS_TABLE
            );
        } catch (\Throwable $e) {
            // An unreadable cron store cannot prove cron is dead, and guessing
            // in either direction is worse than reporting what happened.
            $this->logWarning(
                'Could not read the logs-hits rebuild cron event: '
                . get_class($e) . ': ' . $e->getMessage()
            );
            return false;
        }
        if ($event === null || $event['timestamp'] > abj_clock()->now()) {
            return false;
        }
        return (new ABJ_404_Solution_CronDispatchStallEvidence(abj_cron_scheduler(), $this->inspector))->isProven($event['timestamp']);
    }

    /**
     * The post-response backstop itself. Detaches the response before doing any
     * work, so the page the visitor is waiting on is already delivered, and
     * never lets a failure escape: this runs after the response, where an
     * exception could only corrupt the tail of the output.
     *
     * @return void
     */
    private function runDeferredRebuild(): void {
        try {
            ABJ_404_Solution_ResponseDetach::detach();
            call_user_func($this->rebuildIfStillNeeded);
        } catch (\Throwable $e) {
            $this->logWarning(
                'Post-response logs-hits rollup rebuild failed: '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Cross-request rate limit for the post-response rebuild. Recorded BEFORE
     * the rebuild runs so a rebuild that fails, or whose worker is killed, still
     * consumes its window instead of retrying on every page view.
     *
     * @return bool
     */
    private function claimDeferredRebuildWindow(): bool {
        $rawLast = $this->noticeState->getRuntimeFlag(self::DEFERRED_REBUILD_FLAG . $this->flagNamespace);
        $lastAttempt = is_scalar($rawLast) ? (int)$rawLast : 0;
        $now = abj_clock()->now();
        if ($lastAttempt > 0 && ($now - $lastAttempt) < self::DEFERRED_REBUILD_COOLDOWN_SECONDS) {
            return false;
        }
        $this->noticeState->setRuntimeFlag(
            self::DEFERRED_REBUILD_FLAG . $this->flagNamespace,
            $now,
            self::DEFERRED_REBUILD_COOLDOWN_SECONDS
        );
        return true;
    }

    /** @return int The durable first-stall instant, or 0 when none is recorded. */
    private function readFirstStallInstant(): int {
        $raw = $this->noticeState->getDurableFlag(self::FIRST_STALL_FLAG);
        return is_scalar($raw) ? (int)$raw : 0;
    }

    /**
     * Tell the admin, on the plugin's own screen, that the hit counts they are
     * reading are out of date and why.
     *
     * Written on every observation past the threshold rather than once, because
     * the payload it shares with the other database notices expires on its own
     * cooldown; a persistent condition has to keep saying so. A live notice of a
     * different type is left alone, since a write-block or a crashed table is
     * more urgent than stale columns and re-arms itself from its own path.
     *
     * @param int $ageSeconds How long the rollup has been behind.
     * @return void
     */
    private function raiseStallNotice(int $ageSeconds): void {
        // The foreign-notice check runs where the notice is written, so a notice
        // another subsystem raises while this one waits for translation is kept.
        ABJ_404_Solution_DeferredTranslation::whenReady(function () use ($ageSeconds): void {
            if ($this->foreignNoticeIsLive()) {
                return;
            }
            $hours = max(1, intval(floor($ageSeconds / 3600)));
            $message = sprintf(
                __('The Hits and Last Used columns on the Page Redirects screen have been out of date for at least %d hour(s). 404 Solution has not been able to finish rebuilding its hit-count summary in that time.', '404-solution'),
                $hours
            );
            $this->noticeState->setPluginDbNotice(
                self::NOTICE_TYPE,
                $message,
                $this->stallGuidance(),
                '',
                ABJ_404_Solution_DatabaseNoticeStateHolder::SEVERITY_WARNING
            );
        });
    }

    /**
     * What the admin can actually do about it, which differs by cause: an event
     * that is sitting past due means the site's scheduler is not running it at
     * all, and an event that is being picked up means the rebuild itself is
     * failing and the plugin's log holds the reason.
     *
     * @return string
     */
    private function stallGuidance(): string {
        if ($this->cronIsNotExecuting()) {
            return __("This site's WP-Cron is not running the plugin's scheduled rebuild. If DISABLE_WP_CRON is set in wp-config.php, either remove it or configure a system cron job that requests wp-cron.php periodically. In the meantime 404 Solution retries the rebuild in the background after an admin page has finished loading.", '404-solution');
        }
        return __('The scheduled rebuild is being picked up but is not completing. Enable debug logging on the 404 Solution Options screen and check the log for the reason the rebuild stopped.', '404-solution');
    }

    /** @return bool Whether a live plugin database notice belongs to another subsystem. */
    private function foreignNoticeIsLive(): bool {
        $existing = $this->noticeState->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($existing)) {
            return false;
        }
        $type = isset($existing['type']) && is_string($existing['type']) ? $existing['type'] : '';
        return $type !== '' && $type !== self::NOTICE_TYPE;
    }

    /** @param string $message @return void */
    private function logWarning(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
        }
    }
}
