<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked, time-budgeted backfill of canonical_url on legacy redirect/logsv2 rows.
 *
 * Legacy rows (pre-4.1.x) lack canonical_url, so the view-build JOIN between
 * redirects.canonical_url and logsv2.canonical_url has to fall back to
 * CONCAT/TRIM and cannot use idx_canonical_url. This component drains the NULL
 * backlog across successive daily cron ticks and browser-triggered admin AJAX
 * drains until every row has been populated, at which point reads can drop the
 * COALESCE fallback entirely.
 *
 * Reached by {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} (daily cron)
 * and the browser-triggered lazy-backfill AJAX endpoint.
 */
class ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    private const REDIRECTS_CANONICAL_URL_BACKFILL_CURSOR_OPTION =
        'abj404_redirects_canonical_url_backfill_cursor';
    private const LOGSV2_CANONICAL_URL_BACKFILL_CURSOR_OPTION =
        'abj404_logsv2_canonical_url_backfill_cursor';

    // Backfill tuning values live on the coordinator and are exposed through
    // DatabaseUpgradeComponent accessors so the delegate does not duplicate
    // constants owned by ABJ_404_Solution_DatabaseUpgradesEtc.

    /**
     * Populate {wp_abj404_redirects}.canonical_url for any rows still NULL,
     * one chunk at a time. Each chunk runs:
     *
     *   SELECT id FROM redirects WHERE id > cursor AND canonical_url IS NULL
     *   ORDER BY id ASC LIMIT N, then UPDATE that primary-key window.
     *
     * Idempotent -- once every row has canonical_url set, the WHERE matches
     * zero rows and the function returns immediately. The chunk loop is
     * bounded by both row count (CANONICAL_URL_BACKFILL_CHUNK_SIZE) and wall
     * clock (CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC) so a 350K-row site
     * converges through daily cron and bounded continuations without blocking a
     * request long enough to hit PHP max_execution_time.
     *
     * Skips silently when:
     *   - the redirects table is missing (degraded site state)
     *   - the canonical_url column is missing (column add hasn't happened
     *     yet, e.g. immediately after upgrade before verifyColumns ran)
     *   - the previous run errored -- repair flow surfaces the error
     *
     * @param ?float $timeBudgetSec Browser requests may supply a smaller budget;
     *   null preserves the normal daily-cron budget.
     * @return int Number of rows updated in this invocation.
     */
    public function backfillRedirectsCanonicalUrl(?float $timeBudgetSec = null): int {
        return $this->runCanonicalUrlBackfill('redirects', $timeBudgetSec)['updated'];
    }

    /**
     * Populate {wp_abj404_logsv2}.canonical_url for any rows still NULL,
     * one chunk at a time. Each chunk runs:
     *
     *   SELECT id FROM logsv2 WHERE id > cursor AND canonical_url IS NULL
     *   ORDER BY id ASC LIMIT N, then UPDATE that primary-key window.
     *
     * Mirrors backfillRedirectsCanonicalUrl() with one budget difference --
     * 15-second wall budget (vs 25 for redirects) so browser-triggered AJAX
     * drains stay bounded while the daily cron remains the silent backstop.
     * A persisted primary-key cursor resumes verified progress, and healthy
     * normal-budget passes schedule a continuation while rows remain.
     *
     * Once the backlog is fully cleared (no rows where canonical_url IS NULL),
     * sets the abj404_logsv2_canonical_url_backfill_complete option so reads
     * can drop the COALESCE fallback in getRedirectsForViewTempTable.sql and
     * use the no-COALESCE form (logsv2.canonical_url = redirects.canonical_url).
     *
     * Skips silently when:
     *   - the logsv2 table is missing (degraded site state)
     *   - the canonical_url column is missing (column add hasn't happened
     *     yet, e.g. immediately after upgrade before verifyColumns ran)
     *   - the previous run errored -- repair flow surfaces the error
     *
     * @param ?float $timeBudgetSec Wall-clock budget for this invocation. When
     *   null (the daily-cron path), uses LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC
     *   (15s) for fast unattended convergence. The browser-driven AJAX poller
     *   passes a small value so each request stays well under its client timeout.
     * @return int Number of rows updated in this invocation.
     */
    public function backfillLogsv2CanonicalUrl(?float $timeBudgetSec = null): int {
        return $this->runCanonicalUrlBackfill('logsv2', $timeBudgetSec)['updated'];
    }

    /** Drain redirects first, then logsv2, under one fifteen-second deadline. */
    public function continueCanonicalUrlBackfills(): void {
        $deadline = abj_clock()->nowFloat() + (float)$this->getLogsv2CanonicalUrlBackfillTimeBudgetSec();
        $redirects = $this->runCanonicalUrlBackfill(
            'redirects',
            max(0.0, $deadline - abj_clock()->nowFloat())
        );
        if ($redirects['state'] === 'failed') {
            return;
        }
        $logs = $this->runCanonicalUrlBackfill(
            'logsv2',
            max(0.0, $deadline - abj_clock()->nowFloat())
        );
        if ($logs['state'] === 'failed') {
            return;
        }
        $updated = $redirects['updated'] + $logs['updated'];
        if ($updated > 0 && ($redirects['state'] === 'pending' || $logs['state'] === 'pending')) {
            $this->scheduleCanonicalUrlContinuation();
        }
    }

    /** @return array{table_token:string,source_column:string,cursor_option:string,completion_option:string,label:string,default_budget:float} */
    private function canonicalUrlTarget(string $target): array {
        if ($target === 'redirects') {
            return array(
                'table_token' => '{wp_abj404_redirects}',
                'source_column' => 'url',
                'cursor_option' => self::REDIRECTS_CANONICAL_URL_BACKFILL_CURSOR_OPTION,
                'completion_option' => $this->getRedirectsCanonicalUrlBackfillCompleteOption(),
                'label' => 'backfillRedirectsCanonicalUrl',
                'default_budget' => (float)$this->getCanonicalUrlBackfillTimeBudgetSec(),
            );
        }
        if ($target === 'logsv2') {
            return array(
                'table_token' => '{wp_abj404_logsv2}',
                'source_column' => 'requested_url',
                'cursor_option' => self::LOGSV2_CANONICAL_URL_BACKFILL_CURSOR_OPTION,
                'completion_option' => $this->getLogsv2CanonicalUrlBackfillCompleteOption(),
                'label' => 'backfillLogsv2CanonicalUrl',
                'default_budget' => (float)$this->getLogsv2CanonicalUrlBackfillTimeBudgetSec(),
            );
        }
        throw new InvalidArgumentException('Unknown canonical URL backfill target.');
    }

    /** @return bool|null True=ready, false=absent, null=unreadable. */
    private function canonicalUrlTargetSchemaState(string $tableName): ?bool {
        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }
        // SHOW TABLES existence probe -- routing through queryAndGetResults()
        // would log a benign missing-table error during fresh installation.
        // @utf8-audit: opt-out - $tableName is doTableNameReplacements() of the fixed '{wp_abj404_redirects}' / '{wp_abj404_logsv2}' placeholders (lowercase prefix + literal suffix); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: schema existence probe.
        $found = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, "SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
        $lastError = isset($wpdb->last_error) && is_scalar($wpdb->last_error)
            ? (string)$wpdb->last_error : '';
        if ($lastError !== '') {
            $this->logger->warn('Canonical URL schema probe failed for ' . $tableName . ': ' . $lastError);
            return null;
        }
        if ($found !== $tableName) {
            return false;
        }
        return $this->columnExists($tableName, 'canonical_url');
    }

    /** @return array{updated:int,state:'failed'|'pending'|'complete'} */
    private function runCanonicalUrlBackfill(string $targetName, ?float $timeBudgetSec): array {
        $target = $this->canonicalUrlTarget($targetName);
        if ($timeBudgetSec !== null && $timeBudgetSec <= 0.0) {
            return array('updated' => 0, 'state' => 'pending');
        }
        $tableName = $this->dbCore->doTableNameReplacements($target['table_token']);
        $schemaState = $this->canonicalUrlTargetSchemaState($tableName);
        if ($schemaState === null) {
            return array('updated' => 0, 'state' => 'failed');
        }
        if ($schemaState === false) {
            return array('updated' => 0, 'state' => 'complete');
        }
        $budget = $timeBudgetSec === null ? $target['default_budget'] : max(0.0, $timeBudgetSec);
        $progress = $this->backfillCanonicalUrlByPrimaryKey(
            $tableName,
            $target['source_column'],
            $target['cursor_option'],
            $budget,
            $target['label']
        );
        if ($progress['failed']) {
            return array('updated' => $progress['updated'], 'state' => 'failed');
        }
        $backlogEmpty = $this->publishCanonicalUrlCompletion($target, $tableName);
        if ($backlogEmpty === null) {
            return array('updated' => $progress['updated'], 'state' => 'failed');
        }
        $result = array('updated' => $progress['updated'], 'state' => $backlogEmpty ? 'complete' : 'pending');
        if ($timeBudgetSec === null && $result['updated'] > 0 && $result['state'] === 'pending') {
            $this->scheduleCanonicalUrlContinuation();
        }
        return $result;
    }

    /** @param array{completion_option:string,cursor_option:string,label:string} $target */
    private function publishCanonicalUrlCompletion(array $target, string $tableName): ?bool {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return null;
        }
        $option = $target['completion_option'];
        $reader = new ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper($this->dbCore);
        $backlogEmpty = $reader->canonicalUrlBacklogIsEmpty(array(
            'table' => $tableName,
            'after_id' => $this->readCursorOption($target['cursor_option']),
        ));
        if ($backlogEmpty !== true) {
            return $backlogEmpty;
        }
        if (!ABJ_404_Solution_OptionShapeRead::scalar($option)) {
            update_option($option, '1', false);
            $this->logger->infoMessage($target['label'] . ': backlog cleared -- flipped ' . $option . '.');
        }
        return true;
    }

    private function scheduleCanonicalUrlContinuation(): void {
        $cron = $this->cronScheduler ?? abj_cron_scheduler();
        $hook = ABJ_404_Solution_CronScheduler::HOOK_LOGSV2_CANONICAL_BACKFILL;
        if ($cron->nextScheduled($hook) === false && !$cron->scheduleSingle($hook, 60)) {
            $this->logger->warn('Canonical URL backfill continuation could not be scheduled.');
        }
    }

    /**
     * Drain one canonical_url column with a resumable primary-key range.
     *
     * The SELECT establishes a bounded primary-key window. Updating that window
     * avoids a growing NULL prefix and thousands of IN predicates, which exceed
     * MySQL's range optimizer memory on heavily indexed tables. A single wrap
     * catches rows inserted or reset behind the persisted cursor.
     *
     * @param string $tableName
     * @param string $sourceColumn Trusted source column: url or requested_url.
     * @param string $cursorOption
     * @param float $timeBudgetSec
     * @param string $label
     * @return array{updated:int,failed:bool}
     */
    private function backfillCanonicalUrlByPrimaryKey(
        string $tableName,
        string $sourceColumn,
        string $cursorOption,
        float $timeBudgetSec,
        string $label
    ): array {
        $batchSizer = new ABJ_404_Solution_DatabaseMaintenanceBatchSizer(array(
            'initial_size' => (int)$this->getCanonicalUrlBackfillChunkSize(),
            'maximum_size' => 20000,
        ));
        $start = abj_clock()->nowFloat();
        $cursor = $this->readCursorOption($cursorOption);
        $wrapped = false;
        $totalUpdated = 0;

        while ((abj_clock()->nowFloat() - $start) < max(0.0, $timeBudgetSec)) {
            $iterationStart = abj_clock()->nowFloat();
            $chunkSize = $batchSizer->limit();
            $result = $this->dbCore->queryAndGetResults(
                "SELECT id FROM " . $tableName .
                " WHERE id > %d AND canonical_url IS NULL ORDER BY id ASC LIMIT %d",
                array('query_params' => array($cursor, $chunkSize))
            );
            $ids = $this->readCanonicalChunkIds($result, $cursor, $chunkSize);
            if ($ids === null) {
                $this->logger->warn($label . ': canonical ID chunk read failed; preserving cursor.');
                return array('updated' => $totalUpdated, 'failed' => true);
            }
            if (empty($ids)) {
                if ($cursor > 0 && !$wrapped) {
                    $cursor = 0;
                    $wrapped = true;
                    $this->writeCursorOption($cursorOption, 0);
                    continue;
                }
                break;
            }

            $boundedIds = $batchSizer->boundedIds($ids);
            $firstId = $boundedIds[0];
            $lastId = (int)max($boundedIds);
            $update = "UPDATE " . $tableName .
                " SET canonical_url = CONCAT('/', TRIM(BOTH '/' FROM " . $sourceColumn . "))" .
                " WHERE id BETWEEN " . $firstId . " AND " . $lastId . " AND canonical_url IS NULL";
            $updateResult = $this->dbCore->queryAndGetResults($update);
            $rowsAffected = $this->readCanonicalUpdateCount($updateResult, count($boundedIds));
            if ($rowsAffected === null) {
                $this->logger->warn($label . ': canonical write failed; preserving cursor.');
                return array('updated' => $totalUpdated, 'failed' => true);
            }

            $cursor = $lastId;
            $this->writeCursorOption($cursorOption, $cursor);
            $totalUpdated += $rowsAffected;
            $batchSizer->observe($boundedIds, abj_clock()->nowFloat() - $iterationStart);
        }

        if ($totalUpdated > 0) {
            $this->logger->infoMessage(sprintf(
                '%s: populated canonical_url on %d rows in %.2fs.',
                $label,
                $totalUpdated,
                abj_clock()->nowFloat() - $start
            ));
        }
        return array('updated' => $totalUpdated, 'failed' => false);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,int>|null
     */
    private function readCanonicalChunkIds(array $result, int $cursor, int $chunkSize): ?array {
        if (!$this->canonicalQueryResultIsHealthy($result)
            || !array_key_exists('rows', $result) || !is_array($result['rows'])) {
            return null;
        }
        $ids = array();
        $previous = $cursor;
        foreach ($result['rows'] as $row) {
            if (!is_array($row) || !array_key_exists('id', $row)) {
                return null;
            }
            $id = ABJ_404_Solution_ExactInteger::read($row['id'], 1);
            if ($id === null || $id <= $previous) {
                return null;
            }
            $ids[] = $id;
            $previous = $id;
        }
        return count($ids) <= $chunkSize ? $ids : null;
    }

    /** @param array<string,mixed> $result */
    private function readCanonicalUpdateCount(array $result, int $maxRows): ?int {
        if (!$this->canonicalQueryResultIsHealthy($result)
            || !array_key_exists('rows_affected', $result)) {
            return null;
        }
        $rowsAffected = ABJ_404_Solution_ExactInteger::read($result['rows_affected'], 0);
        return $rowsAffected !== null && $rowsAffected <= $maxRows ? $rowsAffected : null;
    }

    /** @param array<string,mixed> $result */
    private function canonicalQueryResultIsHealthy(array $result): bool {
        return array_key_exists('query_succeeded', $result)
            && $result['query_succeeded'] === true
            && array_key_exists('last_error', $result)
            && is_string($result['last_error'])
            && $result['last_error'] === ''
            && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
    }

    /**
     * Cheap "does this column exist on this table" probe via SHOW COLUMNS.
     * Case-insensitive on the column name to match MySQL/MariaDB driver
     * variations in returned column-name casing.
     *
     * Three answers, not two: true, false, and null for "the server did not
     * tell me". Collapsing the third into false said "the column is absent"
     * whenever the read was refused, and the ensure*Column() helpers act on
     * absence by issuing ALTER TABLE ... ADD COLUMN -- so a denied read, a
     * connection lost mid-upgrade or a table caught mid-rename produced a blind
     * schema change against a table nothing had managed to introspect. Callers
     * therefore test against true or false explicitly; null means "leave it
     * alone and look again next tick".
     *
     * @param string $tableName  Fully-qualified table name.
     * @param string $columnName Column to look for.
     * @return bool|null
     */
    public function columnExists(string $tableName, string $columnName): ?bool {
        $result = $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $tableName,
            array('log_errors' => false));
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($lastError !== '' || !is_array($result['rows'] ?? null) || empty($result['rows'])) {
            // Unreadable, or a live table reporting no columns at all, which is
            // not a state a real table can be in and so is not an answer either.
            return null;
        }
        $rows = $result['rows'];
        $needle = strtolower($columnName);
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) !== 'field' || !is_scalar($value)) { continue; }
                if (strtolower((string)$value) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Add the canonical_url column to logsv2 with online DDL when supported.
     *
     * A small idempotent helper that runs ahead of the generic
     * verifyColumns() flow so the column add can use
     * ALGORITHM=INPLACE, LOCK=NONE on InnoDB 5.6 or newer (no table lock during the
     * rewrite). On engines that don't support online DDL for ADD COLUMN the
     * explicit clause causes the statement to fail with
     * ER_ALTER_OPERATION_NOT_SUPPORTED, so we fall back to a bare ALTER, which
     * is what verifyColumns() also runs as the safety net.
     *
     * The matching idx_canonical_url is added by the standard
     * ABJ_404_Solution_DatabaseUpgradeIndexes::verifyIndexes() flow. Index adds
     * use online DDL by default on InnoDB 5.6 or newer, so a separate ensure
     * helper isn't required for the index.
     *
     * @param string $logsTable
     * @return void
     */
    public function ensureLogsv2CanonicalUrlColumn(string $logsTable): void {
        $this->ensureCanonicalUrlColumn($logsTable);
    }

    /**
     * Add the canonical_url column to the redirects table with online DDL
     * when supported.
     *
     * Sibling of {@see ensureLogsv2CanonicalUrlColumn()} applied to the
     * redirects side. The column shipped in 4.1.11 and is normally added by dbDelta
     * on plugin update. On hosts where dbDelta silently fails to ALTER ADD
     * it, every captured-404 INSERT errors out with "Unknown column
     * 'canonical_url' in 'field list'" until verifyColumns eventually
     * retries the column add. One site in the May 10 debug zip emitted
     * 1671 such errors over 10 days on 4.1.12. Calling this helper eagerly
     * from runInitialCreateTables() shortens that window: every cron tick
     * that runs the bootstrap loop retries the ALTER on its own,
     * independent of the verifyColumns DDL diff path.
     *
     * @param string $redirectsTable
     * @return void
     */
    public function ensureRedirectsCanonicalUrlColumn(string $redirectsTable): void {
        $this->ensureCanonicalUrlColumn($redirectsTable);
    }

    /** Apply the shared guarded canonical-column DDL to either legacy table. */
    private function ensureCanonicalUrlColumn(string $tableName): void {
        if ($this->columnExists($tableName, 'canonical_url') !== false) {
            // Present, or unknown. Only a definite absence justifies an ALTER.
            return;
        }
        $inplaceQuery = "ALTER TABLE " . $tableName .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
            " ALGORITHM=INPLACE, LOCK=NONE";
        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
            array('log_too_slow' => false, 'log_errors' => false));
        if ($this->canonicalQueryResultIsHealthy($result)) {
            $this->logger->infoMessage("Added canonical_url to {$tableName} (ALGORITHM=INPLACE, LOCK=NONE).");
            return;
        }
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if (($result['query_succeeded'] ?? null) !== false
                || ($result['timed_out'] ?? false) === true || $lastError === '') {
            $this->logger->warn("Could not add canonical_url to {$tableName}; preserving the schema state.");
            return;
        }
        if ($this->schemaChangeWasAlreadyApplied($lastError)) {
            // Another request added it between the columnExists() probe above
            // and this ALTER. The bare fallback would meet the same column and
            // the same answer, so the column is there and there is nothing to
            // fall back to.
            $this->logger->infoMessage("canonical_url on {$tableName} was added by another process " .
                "while this one was adding it.");
            return;
        }
        // Engine didn't support online DDL for ADD COLUMN, so the bare ALTER
        // falls back to whatever algorithm the engine picks (COPY on MyISAM / very
        // old InnoDB). On modern InnoDB the bare ALTER is itself implicitly
        // INPLACE for ADD COLUMN ... DEFAULT NULL, so this branch only runs
        // on legacy engines where some lock is unavoidable.
        $bareQuery = "ALTER TABLE " . $tableName .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL";
        $bare = $this->dbCore->queryAndGetResults($bareQuery,
            array('log_too_slow' => false));
        if ($this->canonicalQueryResultIsHealthy($bare)) {
            $this->logger->infoMessage("Added canonical_url to {$tableName} (bare ALTER fallback).");
        }
    }
}
