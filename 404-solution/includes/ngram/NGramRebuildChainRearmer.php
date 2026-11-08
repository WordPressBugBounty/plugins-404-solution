<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/NGramRebuildProgressState.php';
require_once __DIR__ . '/NGramRescheduleFailureReport.php';
require_once __DIR__ . '/NGramRebuildRetryPolicy.php';

/**
 * Queues the next tick of the self-rescheduling N-gram cache rebuild chain.
 *
 * The rebuild advances one bounded chunk per WP-Cron tick and each tick must
 * arm its own successor. This collaborator is the only place that does it, so
 * the cadence (retry backoff), the decision not to re-arm a failure retrying
 * cannot fix, and the report of a refused WP-Cron enqueue each have a single
 * owner. {@see ABJ_404_Solution_NGramCacheRebuildBatchRunner} decides WHAT
 * happened on a tick; this class decides when, and whether, the chain comes
 * back.
 */
class ABJ_404_Solution_NGramRebuildChainRearmer {

    /**
     * WP-Cron hook the chain reschedules itself on. The canonical
     * definition lives on the cron adapter that owns hook names
     * (ABJ_404_Solution_CronScheduler::HOOK_REBUILD_NGRAM_CACHE); the literal
     * is repeated here so the Pattern 8 coordination-key audit can see the
     * key in the file that enqueues it.
     */
    const REBUILD_CRON_HOOK = 'abj404_rebuild_ngram_cache_hook';

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramRebuildProgressState */
    private $progress;

    /** @var ABJ_404_Solution_NGramRescheduleFailureReport */
    private $rescheduleFailureReport;

    /**
     * Owns the whole answer to "when, and whether, does the chain come back
     * after a failed tick". This class decides WHAT to ask it; it does not
     * decide what a failure is worth waiting for.
     *
     * @var ABJ_404_Solution_NGramRebuildRetryPolicy
     */
    private $retryPolicy;

    /**
     * @param ABJ_404_Solution_NGramRebuildRuntime $runtime Database, logging and
     *        cron: the platform a rebuild tick runs against.
     * @param ABJ_404_Solution_NGramRebuildProgressState $progress The rebuild
     *        cursor and consecutive-failure ledger of the running chain.
     * @param ABJ_404_Solution_NGramRebuildRetryPolicy|null $retryPolicy Defaults to
     *        the shipped policy; supplied by tests that pin the jitter window.
     */
    public function __construct(
        ABJ_404_Solution_NGramRebuildRuntime $runtime,
        ABJ_404_Solution_NGramRebuildProgressState $progress,
        ?ABJ_404_Solution_NGramRebuildRetryPolicy $retryPolicy = null
    ) {
        $this->cronScheduler = $runtime->cronScheduler();
        $this->logger = $runtime->logger();
        $this->progress = $progress;
        $this->rescheduleFailureReport = new ABJ_404_Solution_NGramRescheduleFailureReport(
            $runtime, $progress);
        $this->retryPolicy = $retryPolicy instanceof ABJ_404_Solution_NGramRebuildRetryPolicy
            ? $retryPolicy
            : new ABJ_404_Solution_NGramRebuildRetryPolicy();
    }

    /**
     * Reschedule the chain, reporting a refusal instead of dropping it.
     *
     * Every reschedule of the chain goes through here. The single-site path
     * used to check the return value and the multisite paths did not, so a
     * WP-Cron refusal stranded a multisite rebuild silently -- the one failure
     * mode where nothing else re-arms the chain.
     *
     * The offset the report needs is read from the cursor rather than passed
     * alongside the delay: as adjacent int parameters the two were transposable
     * at every call site, and a transposition would silently change both when
     * the chain resumes and where it resumes from.
     *
     * @param int $delaySeconds
     * @param array<int, mixed> $args
     * @param float $progress Percent complete, for the report.
     * @return void
     */
    public function rearmAfter(int $delaySeconds, array $args, float $progress): void {
        // Resolved to an absolute second here, then used TWICE: once to ask for
        // the event and once to say what was asked for. The report used to
        // rebuild both this timestamp and these args from what it could reach,
        // and got both wrong on the multisite path -- see
        // ABJ_404_Solution_NGramRescheduleFailureReport::report().
        $timestamp = $this->cronScheduler->timestampAfter($delaySeconds);
        if ($this->cronScheduler->scheduleSingleAt(self::REBUILD_CRON_HOOK, $timestamp, $args) === false) {
            $this->rescheduleFailureReport->report(array(
                'hookName' => self::REBUILD_CRON_HOOK,
                'offset' => $this->progress->cursor(),
                'progressPercent' => $progress,
                'args' => $args,
                'requestedTimestamp' => $timestamp,
            ));
        }
    }

    /**
     * Arm the chain's next link at the cadence this tick earned, or report why
     * it is not being armed at all.
     *
     * Every reschedule that follows a drain or a probe goes through here, which
     * is the point: the delay used to be the literal 10 written out at four
     * separate call sites, so there was no single place that could be taught to
     * back off, and nothing anywhere asked whether the failure was one that
     * retrying could fix.
     *
     * Two inputs, deliberately separate. The CADENCE comes from the consecutive
     * failure count, so a tick that succeeded runs at the base interval however
     * bad the last hour was. Whether to re-arm AT ALL comes from the failure
     * text, so a statement the server has already rejected on its own terms is
     * surfaced once instead of re-run until the failure budget runs out.
     *
     * A chain that is not re-armed is not a dead rebuild: the cache stays
     * honestly uninitialized, and the next 404, daily reconcile or admin
     * rebuild re-arms it through
     * {@see ABJ_404_Solution_NGramCacheRebuildScheduler}.
     *
     * @param string $failureText What stopped this tick, or '' when nothing did.
     *        Callers holding a drain outcome read it through
     *        {@see ABJ_404_Solution_NGramRebuildDrain::failureTextOf()} rather
     *        than testing the raw field, so every branch here and in the
     *        callers agrees on what the absence of a failure looks like.
     * @param array<int, mixed> $args Cron arguments for the next link.
     * @param float $progress Percent complete, for the refusal report.
     * @return void
     */
    public function rearm(string $failureText, array $args, float $progress): void {
        if ($failureText !== '' && !$this->retryPolicy->isWorthRetrying($failureText)) {
            $this->logger->errorMessage(
                'N-gram cache rebuild stopped, because retrying cannot fix this: ' . $failureText
                . ' The cache is left uninitialized so a later rebuild can resume it once the '
                . 'underlying problem is corrected.'
            );
            return;
        }

        $this->rearmAfter(
            $this->retryPolicy->secondsUntilNextAttempt($this->progress->consecutiveFailures()),
            $args,
            $progress
        );
    }
}
