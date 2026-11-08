<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily-cron entry point that fans out to every database-maintenance sub-task.
 *
 * Called by abj404_dailyMaintenanceCronJobListener() in 404-solution.php.
 * Coordinates (in order): self-heal prologue, ngram cache sync/cleanup, expired
 * transient cleanup, abandoned census row cleanup, auto-redirect retention,
 * canonical_url backfill (redirects + logsv2), redirects denorm backfill (Step
 * 3a) + nightly full reconcile (Step 3d), internal-link scan, and an
 * inline view_done snapshot refresh. The orchestrator owns the small in-house
 * tasks that only the daily cron triggers (transient cleanup, view-done
 * refresh); everything else routes through the coordinator delegate map.
 */
class ABJ_404_Solution_DatabaseUpgradeDailyMaintenance extends ABJ_404_Solution_DatabaseUpgradeComponent {
    private const RECONCILE_PASS_OPTION = 'abj404_redirects_denorm_reconcile_pass';
    private const RECONCILE_MAX_INVOCATIONS = 64;

    /**
     * Run all database maintenance tasks.
     *
     * This is the main orchestrator method called by the daily maintenance cron job.
     * It coordinates all database-related maintenance tasks in the proper order.
     *
     * Called by: abj404_dailyMaintenanceCronJobListener() in 404-solution.php
     *
     * @return void
     */
    public function runDatabaseMaintenanceTasks() {
        $this->upgrades()->selfHealUpgrade()->runSelfHealPrologue();
        if (!$this->logsTableMetadataRefresh()->run()['complete']) {
            return;
        }
        $stages = $this->maintenanceStages();
        unset($stages['schema_insurance']);
        foreach ($stages as $stage) { $stage(); }
    }

    /** @return array<string,callable> Each existing database task owns one independent cron phase. */
    public function maintenanceStages(): array {
        return [
        // Insurance: Verify tables exist (per-site or network-wide based on activation mode)
        // This catches failed activations, database corruption, and edge cases.
        // Routed through runSelfHealPrologue() so the SelfHealingPrologueReachabilityTest
        // can confirm the daily cron reaches the canonical prologue token.
        'schema_insurance' => function (): array {
            $this->upgrades()->selfHealUpgrade()->runSelfHealPrologue();
            return $this->logsTableMetadataRefresh()->run();
        },

        // Ngram cache maintenance: sync missing entries and cleanup orphaned ones
        'ngram_sync' => fn() => $this->upgrades()->nGramUpgrade()->syncMissingNGrams(),
        'ngram_orphans' => fn() => $this->upgrades()->nGramUpgrade()->cleanupOrphanedNGrams(),

        // Clean up expired rate limit transients to prevent wp_options bloat
        'rate_limit_transients' => fn() => $this->cleanupExpiredRateLimitTransients(),

        // Response handoffs expire independently of Redis and object caching.
        'admin_table_flights' => fn() => (new ABJ_404_Solution_AdminTableFlightRepository())
            ->cleanupExpired($this->dbCore, abj_clock()->now()),

        // A hard-killed request leaves its in-flight census row behind, and the
        // only other disposer is a census reading on a 404 Solution admin screen,
        // so a site whose administrator never opens one kept the rows forever.
        // Same stale definition and same claim-then-record path as that reading.
        'abandoned_census_rows' => fn() => $this->sweepAbandonedCensusRows(),

        // Backfill canonical_url on legacy redirect rows so the captured-page
        // JOIN to logs_hits.requested_url stays index-friendly. Chunked + rate-
        // limited so the daily cron continues progress without blocking large
        // sites; converges on its own across successive runs.
        'canonical_redirects' => fn() => $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillRedirectsCanonicalUrl(),

        // Same idea for logsv2: legacy rows (pre-4.1.x) lack canonical_url, so
        // the hits-rebuild JOIN falls back to CONCAT/TRIM and can't use
        // idx_canonical_url. Chunked + rate-limited so even a multi-hundred-K
        // logsv2 backlog converges across successive cron ticks. Tighter
        // 15-second budget (vs redirects' 25) because this same function is
        // also reachable from the browser-triggered lazy-backfill AJAX endpoint.
        'canonical_logs' => fn() => $this->upgrades()->canonicalUrlBackfillUpgrade()->backfillLogsv2CanonicalUrl(),

        // Denorm Step 3a (i459): populate the four derived columns (logshits,
        // last_used, dest_for_view, published_status) on legacy redirect rows
        // that pre-date the column add. Chunked + wall-clock-bounded so even a
        // large redirects table converges across successive daily ticks without
        // blocking activation. Runs after the canonical_url backfills so the
        // hits rollup join matches on the freshly populated canonical_url column.
        'denorm_backfill' => fn() => $this->upgrades()->redirectsDenormBackfillUpgrade()->backfillRedirectsDenormColumns(),

        // report5.md Finding 1: an install upgraded ACROSS the dest_sort_key
        // column add already has dest_for_view populated, so the main backfill's
        // dest_for_view IS NULL sentinel skips it and the converged-row live
        // write-back never fires; the indexable Destination sort key would stay
        // NULL until the nightly reconcile below happened to walk that row. This
        // cheap narrow drain (no per-type joins) closes that window directly,
        // keyed on the self-clearing dest_sort_key IS NULL AND dest_for_view IS
        // NOT NULL sentinel. Runs after the main backfill so freshly-resolved rows
        // (which already got their sort key) are skipped, and converges to a no-op.
        'destination_sort_keys' => fn() => $this->upgrades()->redirectsSortKeyBackfillUpgrade()->backfillRedirectsDestSortKey(),

        // report6.md: same one-time drain for url_sort_key (the indexable URL sort
        // key, LEFT(url, 191)). New/edited rows get it in real time via the Step 3c
        // recompute; this converges legacy rows that pre-date the column add so the
        // URL sort is index-ordered on the captured tab too, without waiting for the
        // nightly reconcile below.
        'url_sort_keys' => fn() => $this->upgrades()->redirectsSortKeyBackfillUpgrade()->backfillRedirectsUrlSortKey(),

        // Denorm Step 3d (i462): nightly full reconcile of the same four derived
        // columns for ALL redirect rows. The Tier-3 floor / backstop: a
        // brute-force cursor-walked recompute that catches drift from raw-SQL
        // writers that bypassed the Step 3c real-time hooks (no hook fired, no
        // timestamp bumped). Chunked + wall-clock-bounded with a resumable id
        // cursor so a large table converges within the same nightly sweep via
        // bounded continuations, rather than taking weeks at one budget per day.
        // Background-only: never on the read path, never blocks the table view;
        // a stale value can only delay a refresh by one pass, never blank rows.
        // Runs after the backfill so a fresh install's one-time population goes
        // first and the reconcile then keeps the populated columns honest.
        'nightly_reconcile' => fn() => $this->runNightlyReconcilePass(),

        // Nightly internal-link scan: find broken internal links in published content.
        'internal_links' => static function (): void {
            if (class_exists('ABJ_404_Solution_InternalLinkScanner')) {
                (new ABJ_404_Solution_InternalLinkScanner())->runNightlyScan();
            }
        },
        ];
    }

    /**
     * The `abandoned_census_rows` stage: dispose of every census row older than
     * the in-flight TTL and prune retired rows past their retention, through
     * the one disposal path a census reading also uses.
     *
     * Never throws. The maintenance pass holds on a phase that throws and
     * retries it, which would starve every later phase over leftovers that are
     * only diagnostic evidence; a failure is logged and the phase completes.
     *
     * @return array{complete: bool, reaped: int, recorded: int, pruned: int}
     *   `complete: false` asks the maintenance pass to run this phase again
     *   through its own continuation (see ABJ_404_Solution_MaintenanceDrain).
     */
    public function sweepAbandonedCensusRows(): array {
        try {
            return ABJ_404_Solution_SameSiteCensusReaper::sweep(
                ABJ_404_Solution_SameSiteCensusReaper::NIGHTLY_BUDGET_SEC);
        } catch (Throwable $e) {
            $this->logger->warn('Abandoned census row cleanup failed and was skipped: '
                . get_class($e) . ' code=' . (string)$e->getCode() . ' message=' . $e->getMessage());
            return array('complete' => true, 'reaped' => 0, 'recorded' => 0, 'pruned' => 0);
        }
    }

    /**
     * The logs table optimizer-metadata refresh, bound to this site's database
     * and logger. Retried by the schema-insurance stage until it completes.
     */
    private function logsTableMetadataRefresh(): ABJ_404_Solution_LogsTableMetadataRefresh {
        return new ABJ_404_Solution_LogsTableMetadataRefresh(
            array($this->dbCore, 'queryAndGetResults'), $this->logger
        );
    }

    /** Continue only the identified nightly sweep; never start another sweep. */
    public function resumeNightlyReconcilePass(string $passId): void {
        $this->runNightlyReconcilePass($passId);
    }

    /** Run one normal-budget chunk loop under the shared sweep lock. */
    private function runNightlyReconcilePass(?string $expectedPassId = null): void {
        $pass = $this->nightlyReconcilePass($expectedPassId);
        if ($pass === null) {
            return;
        }
        $lockKey = 'redirects_denorm_reconcile';
        $lock = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($lock)) {
            if ($expectedPassId === null) {
                // Reserve only an absent identity. add_option is atomic on the
                // unique option name; never replace another lock holder's pass.
                add_option(self::RECONCILE_PASS_OPTION, $pass, '', false);
                $reservedPass = $this->nightlyReconcilePass($pass['id']);
                if ($reservedPass === null) {
                    return;
                }
                $pass = $reservedPass;
            }
            $this->scheduleReconcileContinuation($pass);
            return;
        }
        try {
            $pass = $this->nightlyReconcilePass($expectedPassId);
            if ($pass === null) {
                return;
            }
            $pass['runs']++;
            $pass['updated_at'] = abj_clock()->now();
            if (!update_option(self::RECONCILE_PASS_OPTION, $pass, false)) {
                global $wpdb;
                $this->logger->warn('Nightly reconcile could not persist its pass: ' . (string)($wpdb->last_error ?? ''));
                return;
            }
            $complete = false;
            $this->upgrades()->redirectsDenormReconcileUpgrade()->reconcileRedirectsDenormColumns(
                static function () use (&$complete): void { $complete = true; }
            );
            if ($complete) {
                delete_option(self::RECONCILE_PASS_OPTION);
            } else {
                $this->scheduleReconcileContinuation($pass);
            }
        } finally {
            $this->syncUtils->synchronizerReleaseLock($lock, $lockKey);
        }
    }

    /**
     * Versioned metadata identifies one nightly sweep. Only a daily invocation
     * may create/renew it; resume events expire within 24h and at 64 invocations.
     * @return array{version: int, type: string, id: string, created_at: int,
     * updated_at: int, deleted_at: int|null, deadline: int, runs: int}|null
     */
    private function nightlyReconcilePass(?string $expectedPassId): ?array {
        $raw = ABJ_404_Solution_OptionShapeRead::arrayValue(self::RECONCILE_PASS_OPTION, false);
        if (!is_array($raw)) {
            $raw = [];
        }
        $now = abj_clock()->now();
        $id = $raw['id'] ?? null;
        $deadline = $raw['deadline'] ?? null;
        $runs = $raw['runs'] ?? null;
        if (($raw['version'] ?? null) === 1 && is_string($id) && $id !== ''
            && is_int($deadline) && $deadline > $now
            && is_int($runs) && $runs >= 0 && $runs < self::RECONCILE_MAX_INVOCATIONS) {
            if ($expectedPassId !== null && $id !== $expectedPassId) {
                return null;
            }
            return ['version' => 1, 'type' => 'nightly_reconcile', 'id' => $id,
                'deadline' => $deadline, 'runs' => $runs]
                + $this->normalizeNightlyReconcileTimestamps($raw, $now);
        }
        if ($expectedPassId !== null) {
            return null;
        }
        return ['version' => 1, 'type' => 'nightly_reconcile',
            'id' => uniqid('nightly-', true), 'created_at' => $now, 'updated_at' => $now,
            'deleted_at' => null, 'deadline' => $now + 86400, 'runs' => 0];
    }

    /**
     * Preserve valid persisted timestamps and supply defaults for legacy records.
     * @param array<array-key, mixed> $raw
     * @return array{created_at: int, updated_at: int, deleted_at: int|null}
     */
    private function normalizeNightlyReconcileTimestamps(array $raw, int $now): array {
        $createdAt = $raw['created_at'] ?? $now;
        $updatedAt = $raw['updated_at'] ?? $now;
        $deletedAt = $raw['deleted_at'] ?? null;
        return ['created_at' => is_int($createdAt) ? $createdAt : $now,
            'updated_at' => is_int($updatedAt) ? $updatedAt : $now,
            'deleted_at' => is_int($deletedAt) ? $deletedAt : null];
    }

    /**
     * Arm at most one continuation of this sweep, with no recurring drift probe.
     * @param array{version: int, type: string, id: string, created_at: int,
     * updated_at: int, deleted_at: int|null, deadline: int, runs: int} $pass
     */
    private function scheduleReconcileContinuation(array $pass): void {
        if ($pass['runs'] >= self::RECONCILE_MAX_INVOCATIONS || $pass['deadline'] <= abj_clock()->now()) {
            $this->logger->warn('Nightly reconcile pass exhausted its continuation limit: ' . $pass['id']);
            return;
        }
        $cron = $this->cronScheduler ?? abj_cron_scheduler();
        $args = [$pass['id']];
        $hook = ABJ_404_Solution_CronScheduler::HOOK_REDIRECTS_DENORM_RECONCILE_RESUME;
        if ($cron->nextScheduled($hook, $args) === false && !$cron->scheduleSingle($hook, 60, $args)) {
            $this->logger->warn('Nightly reconcile continuation could not be scheduled for pass ' . $pass['id']);
        }
    }

    /**
     * Clean up expired rate limit transients from wp_options table.
     *
     * WordPress transients are supposed to auto-delete when they expire, but in practice
     * they can accumulate over time. This maintenance task removes expired rate limit
     * transients to prevent wp_options table bloat.
     *
     * Called during daily maintenance cron job.
     *
     * @return array<string, mixed> Statistics: ['deleted' => int, 'errors' => int]
     */
    public function cleanupExpiredRateLimitTransients() {
        global $wpdb;

        $this->logger->debugMessage("Cleaning up expired rate limit transients...");

        $stats = ['deleted' => 0, 'errors' => 0];

        // Delete expired rate limit transients
        // WordPress stores transients as two rows: _transient_* and _transient_timeout_*
        // The timeout row contains the expiration timestamp
        // We delete both the value and timeout rows for expired transients

        $currentTime = abj_clock()->now();

        // Find all expired rate limit timeout keys
        // DAO-bypass-approved: WP-core wp_options probe; $wpdb->prepare is read-only string formatting, executed via $wpdb->get_col below
        $query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_abj404_rate_limit_') . '%',
            $currentTime
        );

        // DAO-bypass-approved: Outside-plugin-tables wp_options cleanup probe (parallels DataAccessTrait_ViewQueries:478 transient clear)
        $expiredTimeouts = ABJ_404_Solution_DatabaseDriverCall::getCol($wpdb, $query);

        $lastError = (string)($wpdb->last_error ?? '');
        if ($lastError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($lastError)) {
                $this->logger->errorMessage("Failed to query for expired rate limit transients: " . $lastError);
            }
            return ['deleted' => 0, 'errors' => 1, 'error' => $lastError];
        }

        if (!empty($expiredTimeouts)) {
            $this->logger->debugMessage("Found " . count($expiredTimeouts) . " expired rate limit transients to delete.");

            foreach ($expiredTimeouts as $timeoutKey) {
                // option_name is NOT NULL, so a NULL cell is not a transient row.
                if (!is_string($timeoutKey)) {
                    continue;
                }
                // Get the corresponding value key (remove '_timeout' from the name)
                $valueKey = str_replace('_transient_timeout_', '_transient_', $timeoutKey);

                // Delete both the timeout and value rows
                $timeoutDeleted = delete_option($timeoutKey);
                $valueDeleted = delete_option($valueKey);

                if ($timeoutDeleted || $valueDeleted) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            }

            $this->logger->debugMessage("Deleted {$stats['deleted']} expired rate limit transients, {$stats['errors']} errors.");
        } else {
            $this->logger->debugMessage("No expired rate limit transients found.");
        }

        return $stats;
    }

}
