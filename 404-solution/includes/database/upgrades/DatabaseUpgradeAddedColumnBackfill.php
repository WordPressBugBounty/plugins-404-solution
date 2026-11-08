<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-time data backfills and cache invalidations triggered by a column that
 * was just added to a plugin table.
 *
 * Peer of the other column-triggered backfill components
 * ({@see ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill},
 * {@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}): those make
 * sure a column EXISTS, this one seeds the data that a column needs once it
 * does. Reached from
 * {@see ABJ_404_Solution_DatabaseUpgradeSchemaDiff::verifyColumns()}, the only
 * caller that verifies both new and already-present columns needing data work.
 *
 * Extracted from ABJ_404_Solution_DatabaseTableDdlExecutor, where it shared a
 * file with permanent-DDL discovery and execution but never shared a call
 * graph with them: nothing in that file called it and it called nothing in
 * that file, and it owned that file's only use of the content repository.
 * Seeding table DATA is a different job from executing table DDL; the two only
 * ever met because both happen during an upgrade.
 */
class ABJ_404_Solution_DatabaseUpgradeAddedColumnBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    private const MIN_LOG_ID_BATCH_SIZE = 500;
    /** @var array<string,ABJ_404_Solution_MinLogIdBackfillContinuation> Request-local coordinators by table. */
    private $minLogIdContinuations = array();

    /**
     * Post-creation, column-triggered one-time data backfills. Dispatches
     * on (tableName, colName) to exactly two cases:
     *  - abj404_logsv2.min_log_id: runs the seed SQL (backfillLogsMinLogId)
     *    and ensures the composite index that depends on it.
     *  - abj404_permalink_cache.url_length: truncates the permalink cache
     *    so the new column gets populated on next rebuild.
     * Any other (tableName, colName) pair is a no-op.
     *
     * Takes a single associative array (rather than two positional strings)
     * so the table name and column name -- both plain strings -- cannot be
     * silently transposed at the call site.
     *
     * @param array{tableName: string, colName: string} $context
     * @return void
     */
    public function runBackfillsForAddedColumn(array $context) {
        $tableName = isset($context['tableName']) && is_string($context['tableName']) ? $context['tableName'] : '';
        $colName = isset($context['colName']) && is_string($context['colName']) ? $context['colName'] : '';
        if (empty($tableName)) {
            return;
        }

        if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
            $this->backfillLogsMinLogId($tableName);
        }
        if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
            // clear the permalink cache so that the url length column will be populated.
            // this could be more efficient but I'll assume that's not necessary.
            $this->contentRepo->truncatePermalinkCacheTable();
        }
    }

    /**
     * Continue resumable backfills even after the triggering column exists.
     * A failed or partially completed data update must remain reachable on the
     * next schema verification.
     *
     * @param string $tableName
     * @return void
     */
    public function runPendingBackfills(string $tableName): void {
        if (strpos($tableName, 'abj404_logsv2') !== false) {
            $this->backfillLogsMinLogId($tableName);
        }
        if (strpos($tableName, 'abj404_permalink_cache') !== false) {
            // NULL is the existing pending marker. Keep it intact on a failed
            // read or truncate so a later normal verification can retry.
            $result = $this->dbCore->queryAndGetResults(
                'select id from ' . $tableName . ' where url_length is null limit 1', array('log_errors' => false));
            $rows = $result['rows'] ?? null;
            $id = is_array($rows) && is_array($rows[0] ?? null)
                ? ABJ_404_Solution_ExactInteger::read($rows[0]['id'] ?? null, 0) : null;
            if ($id !== null && ($result['query_succeeded'] ?? null) === true && ($result['last_error'] ?? null) === ''
                    && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false)) {
                $this->contentRepo->truncatePermalinkCacheTable();
            }
        }
    }

    /**
     * One-time backfill for the abj404_logsv2.min_log_id column: runs the
     * seed SQL, then ensures the composite index that depends on it exists.
     * Extracted out of runBackfillsForAddedColumn() so that method stays a plain
     * column-name dispatcher; the data-access step (SQL file load + execute)
     * lives in its own method instead of inline in the dispatch logic.
     *
     * @param string $tableName
     * @return void
     */
    private function backfillLogsMinLogId($tableName) {
        $this->minLogIdContinuation($tableName)->runForeground();
    }

    /** Continue the resumable min_log_id pass from the registered cron hook. */
    public function runScheduledMinLogIdBackfill(): void {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        if (!is_string($tableName) || $tableName === '') {
            $this->logger->warn('Cannot continue min_log_id backfill: log table name is unreadable.');
            return;
        }

        $this->minLogIdContinuation($tableName)->runScheduled();
    }

    /** Admit a bounded continuation for already-pending work, never start a pass. */
    public function armMinLogIdCronFallback(): bool {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        return is_string($table) && $table !== '' && $this->minLogIdContinuation($table)->arm();
    }

    private function minLogIdContinuation(string $table): ABJ_404_Solution_MinLogIdBackfillContinuation {
        if (!isset($this->minLogIdContinuations[$table])) {
            $this->minLogIdContinuations[$table] = new ABJ_404_Solution_MinLogIdBackfillContinuation(array(
                'table' => $table, 'sync' => $this->syncUtils, 'clock' => abj_clock(),
                'cron' => $this->cronScheduler ?? abj_cron_scheduler(), 'logger' => $this->logger,
                'batch' => function () use ($table): array { return $this->runMinLogIdBatch($table); },
                'state' => function () use ($table): ?array { return $this->stateStore()->read($table); },
                'schedule' => function (): void { $this->scheduleMinLogIdContinuation(); },
            ));
        }
        return $this->minLogIdContinuations[$table];
    }

    /**
     * Forget a completed pass when the min_log_id column is physically added
     * again in the same plugin release.
     *
     * @return bool Whether the old pass was removed or was already absent.
     */
    public function resetMinLogIdBackfill(string $tableName): bool {
        return $this->stateStore()->forget($this->dbCore->doTableNameReplacements($tableName));
    }

    /**
     * @return array{complete: bool, progressed: bool}
     */
    private function runMinLogIdBatch(string $tableName): array {
        $tableName = $this->dbCore->doTableNameReplacements($tableName);
        if ($tableName === '') {
            $this->logger->warn('Cannot run min_log_id backfill: log table name is unreadable.');
            return array('complete' => false, 'progressed' => false);
        }
        $state = $this->stateStore()->readOrFresh($tableName);
        if ($state['complete']) {
            $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
            return array('complete' => true, 'progressed' => false);
        }

        if ($state['ceiling'] === null) {
            $ceilingResult = $this->dbCore->queryAndGetResults(
                'select max(id) as max_id from ' . $tableName
            );
            if (!$this->querySucceeded($ceilingResult, $tableName, 'ceiling')) {
                return array('complete' => false, 'progressed' => false);
            }
            $ceiling = $this->readMaxId($ceilingResult, $tableName);
            if ($ceiling === null) {
                return array('complete' => false, 'progressed' => false);
            }
            $state['ceiling'] = $ceiling;
        }

        if ($state['cursor'] >= $state['ceiling']) {
            return $this->completeMinLogIdPass($tableName, $state);
        }

        $candidateResult = $this->dbCore->queryAndGetResults(
            'select id from ' . $tableName . ' where id > ' . $state['cursor']
            . ' and id <= ' . $state['ceiling'] . ' order by id asc limit '
            . self::MIN_LOG_ID_BATCH_SIZE
        );
        if (!$this->querySucceeded($candidateResult, $tableName, 'candidate read')) {
            return array('complete' => false, 'progressed' => false);
        }
        $candidateIds = $this->readCandidateIds($candidateResult, $state, $tableName);
        if ($candidateIds === null) {
            return array('complete' => false, 'progressed' => false);
        }
        if (empty($candidateIds)) {
            return $this->completeMinLogIdPass($tableName, $state);
        }

        $markerResult = $this->dbCore->queryAndGetResults(
            'select candidate.id, case when not exists (select 1 from ' . $tableName
            . ' earlier_row where earlier_row.requested_url = candidate.requested_url'
            . ' and earlier_row.id < candidate.id) then 1 else 0 end as min_log_id'
            . ' from ' . $tableName . ' candidate where candidate.id in ('
            . implode(', ', $candidateIds) . ') order by candidate.id asc limit '
            . self::MIN_LOG_ID_BATCH_SIZE
        );
        if (!$this->querySucceeded($markerResult, $tableName, 'minimum lookup')) {
            return array('complete' => false, 'progressed' => false);
        }
        $markers = $this->readMinLogIdMarkers($markerResult, $candidateIds, $tableName);
        if ($markers === null) {
            return array('complete' => false, 'progressed' => false);
        }

        try {
            $query = ABJ_404_Solution_FileSystemService::readFileContents(
                __DIR__ . "/../../sql/logsSetMinLogID.sql"
            );
        } catch (Exception $e) {
            $this->logger->errorMessage(
                'Could not read logsSetMinLogID.sql backfill file for ' . $tableName
                . ': ' . $e->getMessage() . '. Skipping min_log_id backfill and composite '
                . 'index creation this run; will retry on the next upgrade check.'
            );
            return array('complete' => false, 'progressed' => false);
        }
        $markerCases = '';
        foreach ($candidateIds as $candidateId) {
            $markerCases .= ' when ' . $candidateId . ' then ' . $markers[$candidateId];
        }
        $query = str_replace(
            array('{candidate_ids}', '{marker_cases}'),
            array(implode(', ', $candidateIds), $markerCases),
            $query
        );
        $query = $this->dbCore->doTableNameReplacements($query);
        $queryResponse = $this->dbCore->queryAndGetResults($query);
        if (!$this->querySucceeded($queryResponse, $tableName, 'update')) {
            return array('complete' => false, 'progressed' => false);
        }
        $rowsAffected = $queryResponse['rows_affected'] ?? null;
        if (!is_int($rowsAffected) && !is_numeric($rowsAffected)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid rows_affected type ('
                . gettype($rowsAffected) . ') for ' . $tableName
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return array('complete' => false, 'progressed' => false);
        }

        $state['cursor'] = $candidateIds[count($candidateIds) - 1];
        $state['complete'] = count($candidateIds) < self::MIN_LOG_ID_BATCH_SIZE;
        if (!$this->stateStore()->persist($tableName, $state)) {
            return array('complete' => false, 'progressed' => false);
        }
        if ($state['complete']) {
            $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
        } else {
            $this->scheduleMinLogIdContinuation();
        }
        return array('complete' => $state['complete'], 'progressed' => true);
    }

    /**
     * Built per call: the logger can be swapped by replaceDatabaseUpgradeDependencies().
     */
    private function stateStore(): ABJ_404_Solution_MinLogIdBackfillStateStore {
        return new ABJ_404_Solution_MinLogIdBackfillStateStore($this->logger);
    }

    /**
     * @param array{release: string, table: string, cursor: int, ceiling: int|null, complete: bool} $state
     * @return array{complete: bool, progressed: bool}
     */
    private function completeMinLogIdPass(string $tableName, array $state): array {
        $state['complete'] = true;
        if (!$this->stateStore()->persist($tableName, $state)) {
            return array('complete' => false, 'progressed' => false);
        }
        $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
        return array('complete' => true, 'progressed' => true);
    }

    /** @param array<string, mixed> $result */
    private function querySucceeded(array $result, string $tableName, string $step): bool {
        $timedOut = $result['timed_out'] ?? false;
        if ($timedOut === true) {
            $nativeError = is_string($result['last_error'] ?? null) && $result['last_error'] !== ''
                ? ': ' . $result['last_error'] : '';
            $this->logger->errorMessage(
                'min_log_id backfill query timed out for ' . $tableName . ' during ' . $step
                . $nativeError . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return false;
        }
        if (array_key_exists('timed_out', $result) && !is_bool($timedOut)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid timed_out type (' . gettype($timedOut) . ') for '
                . $tableName . ' during ' . $step
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return false;
        }

        $querySucceeded = $result['query_succeeded'] ?? null;
        if ($querySucceeded !== true) {
            if (!array_key_exists('query_succeeded', $result)) {
                $diagnostic = 'missing query_succeeded';
            } elseif (!is_bool($querySucceeded)) {
                $diagnostic = 'invalid query_succeeded type (' . gettype($querySucceeded) . ')';
            } else {
                $diagnostic = 'query_succeeded=false';
            }
            $nativeError = is_string($result['last_error'] ?? null) && $result['last_error'] !== ''
                ? ': ' . $result['last_error'] : '';
            $this->logger->errorMessage(
                'min_log_id backfill query failed for ' . $tableName . ' during ' . $step
                . ' (' . $diagnostic . ')' . $nativeError
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return false;
        }

        $lastError = $result['last_error'] ?? null;
        if (!is_string($lastError)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid last_error type (' . gettype($lastError) . ') for '
                . $tableName . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return false;
        }
        if ($lastError !== '') {
            $this->logger->errorMessage(
                'min_log_id backfill query failed for ' . $tableName . ' during ' . $step . ': ' . $lastError
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return false;
        }
        return true;
    }

    /** @param array<string, mixed> $result */
    private function readMaxId(array $result, string $tableName): ?int {
        $rows = $result['rows'] ?? null;
        if (!is_array($rows) || empty($rows) || !is_array($rows[0] ?? null)) {
            $this->logger->errorMessage('min_log_id backfill returned unreadable MAX(id) metadata for '
                . $tableName . '; will retry on the next upgrade check.');
            return null;
        }
        if (!array_key_exists('max_id', $rows[0])) {
            $this->logger->errorMessage('min_log_id backfill returned MAX(id) without max_id alias for ' . $tableName
                . '; will retry on the next upgrade check.');
            return null;
        }
        $maxId = $rows[0]['max_id'] === null
            ? 0 : ABJ_404_Solution_ExactInteger::read($rows[0]['max_id'], 0);
        if ($maxId === null) {
            $this->logger->errorMessage('min_log_id backfill returned malformed MAX(id) for ' . $tableName
                . '; will retry on the next upgrade check.');
        }
        return $maxId;
    }

    /**
     * @param array<string, mixed> $result
     * @param array{cursor: int, ceiling: int|null} $state
     * @return array<int, int>|null
     */
    private function readCandidateIds(array $result, array $state, string $tableName): ?array {
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            $this->logger->errorMessage('min_log_id backfill returned unreadable candidate metadata for '
                . $tableName . '; will retry on the next upgrade check.');
            return null;
        }
        if (count($rows) > self::MIN_LOG_ID_BATCH_SIZE) {
            $this->logger->errorMessage('min_log_id backfill returned more than the bounded candidate batch for '
                . $tableName . '; will retry on the next upgrade check.');
            return null;
        }
        $ids = array();
        $previousId = $state['cursor'];
        foreach ($rows as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
            if ($id === null || $id <= $previousId || $state['ceiling'] === null || $id > $state['ceiling']
                    || isset($ids[$id])) {
                $this->logger->errorMessage('min_log_id backfill returned malformed candidate IDs for '
                    . $tableName . '; will retry on the next upgrade check.');
                return null;
            }
            $ids[$id] = $id;
            $previousId = $id;
        }
        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, int> $candidateIds
     * @return array<int, int>|null
     */
    private function readMinLogIdMarkers(array $result, array $candidateIds, string $tableName): ?array {
        $rows = $result['rows'] ?? null;
        if (!is_array($rows) || count($rows) !== count($candidateIds)) {
            $this->logger->errorMessage('min_log_id backfill returned incomplete minimum markers for '
                . $tableName . '; will retry on the next upgrade check.');
            return null;
        }
        $expected = array_fill_keys($candidateIds, true);
        $markers = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $this->invalidMinLogIdMarkers($tableName);
                return null;
            }
            $id = ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1);
            $marker = ABJ_404_Solution_ExactInteger::read($row['min_log_id'] ?? null, 0);
            if ($id === null || $marker === null || ($marker !== 0 && $marker !== 1)
                    || !isset($expected[$id]) || array_key_exists($id, $markers)) {
                $this->invalidMinLogIdMarkers($tableName);
                return null;
            }
            $markers[$id] = $marker;
        }
        if (count($markers) !== count($candidateIds)) {
            $this->invalidMinLogIdMarkers($tableName);
            return null;
        }
        return $markers;
    }

    private function invalidMinLogIdMarkers(string $tableName): void {
        $this->logger->errorMessage('min_log_id backfill returned malformed minimum markers for '
            . $tableName . '; will retry on the next upgrade check.');
    }

    private function scheduleMinLogIdContinuation(): void {
        if (!function_exists('abj_cron_scheduler')) {
            $this->logger->warn('Cannot schedule min_log_id backfill continuation: cron scheduler unavailable.');
            return;
        }
        $scheduled = abj_cron_scheduler()->scheduleSingleIfMissing(
            ABJ_404_Solution_CronScheduler::HOOK_LOGSV2_MIN_LOG_ID_BACKFILL, 1
        );
        if (!$scheduled) {
            $this->logger->warn('Could not schedule min_log_id backfill continuation; will retry on the next upgrade check.');
        }
    }
}
