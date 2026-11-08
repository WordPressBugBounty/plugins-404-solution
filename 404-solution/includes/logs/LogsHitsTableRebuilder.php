<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsHitsCanonicalUrlJoinHelper.php';
require_once __DIR__ . '/LogsHitsRebuildBudget.php';
require_once __DIR__ . '/LogsHitsRebuildProgress.php';
require_once __DIR__ . '/LogsHitsTableMetadata.php';
require_once __DIR__ . '/../database/DatabaseCollationHelper.php';

/**
 * Resumable SQL materialization of the logs-hits rollup.
 *
 * Each phase changes only generation-private staging tables until one atomic
 * RENAME publishes the complete snapshot. Phase-one range replacement and the
 * merge's target exclusion make a committed unit replay-safe if PHP dies before
 * its checkpoint is saved. The coordinator owns persistence and scheduling;
 * this execution layer never decides when to arm another request.
 *
 * @phpstan-import-type State from ABJ_404_Solution_LogsHitsRebuildProgress
 */
class ABJ_404_Solution_LogsHitsTableRebuilder {

    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = 5000;
    const MERGE_CHUNK_SIZE = 1000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;
    /** @var ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper */
    private $joinHelper;
    /** @var callable():bool|null */
    private $leaseRenewer;
    /** @var ABJ_404_Solution_LogsHitsTableMetadata */
    private $metadata;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper $joinHelper
     * @param callable():bool|null $leaseRenewer
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logger, $rebuildHealth,
        ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper $joinHelper, $leaseRenewer = null) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState ? $rebuildHealth : null;
        $this->joinHelper = $joinHelper;
        $this->metadata = new ABJ_404_Solution_LogsHitsTableMetadata($dbCore, $logger);
        if ($leaseRenewer !== null && !is_callable($leaseRenewer)) {
            throw new InvalidArgumentException('LogsHitsTableRebuilder lease renewer must be callable or null.');
        }
        $this->leaseRenewer = $leaseRenewer;
    }

    /**
     * Execute a bounded slice, checkpointing each complete replay-safe unit.
     *
     * The two-argument entry remains available for internal consumers; a caller
     * continuing a yielded result supplies its state in the options bag.
     *
     * @param int $minLogId Inclusive snapshot minimum.
     * @param int $maxLogId Inclusive snapshot maximum, used as the watermark.
     * @param array{state?:State,budget?:ABJ_404_Solution_LogsHitsRebuildBudget,checkpoint?:callable(State):void} $options
     * @return array{refreshed:bool,pending:bool,state:State,elapsed_time:float,error:string}
     */
    public function rebuildAndSwap(int $minLogId, int $maxLogId, array $options = array()): array {
        $budget = $options['budget'] ?? new ABJ_404_Solution_LogsHitsRebuildBudget(abj_clock());
        $state = $options['state'] ?? (new ABJ_404_Solution_LogsHitsRebuildProgress($this->logger))->start(array(
            'min_log_id' => $minLogId, 'max_log_id' => $maxLogId,
            'chunk_size' => $this->getHitsRebuildChunkSize($maxLogId - $minLogId),
        ));
        // A new request may follow a cold restart or a resource-cap change.
        // Revalidate throughput with one small unit before growing again.
        if ($state['phase'] === 'prepare' || $state['phase'] === 'preaggregate') {
            $state['chunk_size'] = min($state['chunk_size'],
                ABJ_404_Solution_LogsHitsRebuildBudget::INITIAL_LOG_CHUNK_SIZE);
        }
        $error = '';
        try {
            while ($state['phase'] !== 'complete' && $budget->canStart()) {
                $this->renewLeaseOrThrow();
                $phase = $state['phase'];
                $started = abj_clock()->nowFloat();
                $state = $this->advancePhase($state, $budget);
                $this->renewLeaseOrThrow();
                $budget->completeUnit();
                $elapsed = max(0.0, abj_clock()->nowFloat() - $started);
                $state['elapsed_time'] += $elapsed;
                $state['step_seconds'] = $state['phase'] === $phase ? $elapsed : 0.0;
                $state['updated_at'] = abj_clock()->now();
                if (isset($options['checkpoint'])) { ($options['checkpoint'])($state); }
            }
        } catch (ABJ_404_Solution_LogsHitsRebuildYield $e) {
            // No cursor advanced for an incomplete unit; its SQL is replay-safe.
            // A yield is the healthy liveness signal of a budgeted drain, not a
            // failure: it is recorded as a durable INFO line (a WARN would read
            // as a fault on every healthy slice, and a debug line is lost while
            // debug_mode is off).
            $this->logger->infoMessage('Logs-hits rebuild yielded during ' . $state['phase']
                . ' (code ' . $e->getCode() . '): ' . $e->getMessage() . ' A later request resumes it.');
        } catch (Throwable $e) {
            $error = get_class($e) . ' (' . $e->getCode() . '): ' . $e->getMessage();
            if ($e->getCode() !== 409) { $this->recordHitsRebuildFailure($error); }
            $this->logger->warn('Logs-hits rebuild stopped: ' . $error);
            try {
                $this->discard($state, $budget);
            } catch (Throwable $cleanupError) {
                // The durable running checkpoint lets a later owner finish
                // cleanup if the budget or exclusive lease is already gone.
                $this->logger->warnCaught('Logs-hits staging cleanup deferred.', $cleanupError);
            }
        }
        $complete = $error === '' && $state['phase'] === 'complete';
        if ($complete) { $this->recordHitsRebuildSuccess($state['chunk_size']); }
        return array('refreshed' => $complete, 'pending' => !$complete && $error === '',
            'state' => $state, 'elapsed_time' => (float)$state['elapsed_time'], 'error' => $error);
    }

    /**
     * @param State $state
     * @return State
     */
    private function advancePhase(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        switch ($state['phase']) {
            case 'prepare': return $this->prepareGeneration($state, $budget);
            case 'direct': return $this->fillDirectSnapshot($state, $budget);
            case 'preaggregate': return $this->preaggregateRange($state, $budget);
            case 'merge': return $this->mergeRedirectRange($state, $budget);
            case 'publish': return $this->publishGeneration($state, $budget);
            case 'denormalize': return $this->writeBackRedirectRange($state, $budget);
            case 'cleanup': return $this->cleanGeneration($state, $budget);
            default: throw new InvalidArgumentException('Unknown logs-hits rebuild phase.');
        }
    }

    /**
     * Discard only an abandoned generation's unpublished resources.
     * @param State $state
     */
    public function discard(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): void {
        $tables = $this->generationTables($state);
        foreach (array('temp', 'preagg', 'backup') as $kind) {
            $scratchTable = $tables[$kind];
            $this->query('DROP TABLE IF EXISTS ' . $scratchTable, $budget);
        }
    }

    /**
     * Recover a crash between atomic RENAME and checkpoint persistence.
     * @param State $state
     */
    public function isPublishedGeneration(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): bool {
        return $this->statusMatchesGeneration($this->readLiveStatus($budget), $state);
    }

    /** @return array<string,mixed> */
    private function readLiveStatus(ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $this->renewLeaseOrThrow();
        $row = $this->metadata->statusRow(array('timeout' => $budget->timeoutSeconds()));
        $this->renewLeaseOrThrow();
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param State $state
     */
    private function statusMatchesGeneration(array $row, array $state): bool {
        return isset($row['comment']) && is_string($row['comment'])
            && substr($row['comment'], -20) === '|v1:' . $state['generation'];
    }

    /**
     * @param State $state
     * @return State
     */
    private function prepareGeneration(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tables = $this->generationTables($state);
        $collation = $this->joinHelper->resolveHitsJoinCollation(fn(): array => $this->queryAdmissionOptions($budget));
        $this->createStagingTable('createLogsHitsTempTable.sql', '{wp_abj404_logs_hits}_temp', $tables['temp'], $collation, $budget);
        $state['phase'] = 'direct';
        if ($state['max_log_id'] - $state['min_log_id'] > self::HITS_TABLE_DIRECT_PATH_THRESHOLD) {
            $this->createStagingTable('createLogsHitsPreAggTempTable.sql', '{wp_abj404_logs_hits}_preagg', $tables['preagg'], $collation, $budget);
            $state['phase'] = 'preaggregate';
        }
        return $state;
    }

    /** Create only generation-private scratch names derived by this engine. */
    private function createStagingTable(string $file, string $token, string $table, string $collation,
        ABJ_404_Solution_LogsHitsRebuildBudget $budget): void {
        $ddl = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/' . $file);
        $ddl = str_replace($token, $table, $ddl);
        $ddl = $this->applyJoinCharsetCollation(array('ddl' => $ddl, 'rawCollation' => $collation));
        $this->query($ddl, $budget);
    }

    /**
     * @param State $state
     * @return State
     */
    private function fillDirectSnapshot(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tempTable = $this->generationTables($state)['temp'];
        // A crash before this phase's checkpoint must not duplicate its rows.
        // @cache-write-audit: opt-out - truncates only this generation's unpublished rollup.
        $this->query('TRUNCATE TABLE ' . $tempTable, $budget);
        $this->hitsTableInsertDirect($tempTable, $state, $budget);
        $state['phase'] = 'publish';
        return $state;
    }

    /** @param State $state */
    private function hitsTableInsertDirect(string $tempDestTable, array $state,
        ABJ_404_Solution_LogsHitsRebuildBudget $budget): void {
        $select = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/getRedirectsForViewTempTable.sql');
        $queryOptionsFactory = fn(): array => $this->queryAdmissionOptions($budget);
        if ($this->joinHelper->isLogsv2CanonicalUrlBackfillComplete($queryOptionsFactory)) {
            $select = $this->joinHelper->dropLogsv2CanonicalCoalesceWrap($select);
        }
        $select = str_replace('{redirects_canonical_url_join_rhs}', $this->joinHelper->buildDirectJoinRhs($queryOptionsFactory), $select);
        $select = str_replace('{snapshot_min_log_id}', (string)$state['min_log_id'], $select);
        $select = str_replace('{snapshot_max_log_id}', (string)$state['max_log_id'], $select);
        $this->query('/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertDirect */ INSERT INTO ' . $tempDestTable
            . ' (requested_url, logsid, last_used, logshits, failed_hits) ' . $select, $budget);
    }

    /**
     * @param State $state
     * @return State
     */
    private function preaggregateRange(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tables = $this->generationTables($state);
        $started = abj_clock()->nowFloat();
        $start = $state['next_log_id'];
        // Walk PRIMARY so covering secondary indexes cannot filesort the history.
        // Bound actual input rows. Empty ID gaps must neither consume many
        // callbacks nor train the next dense range to use an oversized unit.
        $boundary = $this->query('/* abj404:src=LogsHitsTableRebuilder#phase1Boundary */ '
            . 'SELECT MAX(id) AS range_end FROM (SELECT id FROM {wp_abj404_logsv2} FORCE INDEX (PRIMARY) WHERE id >= %d AND id <= %d ORDER BY id ASC LIMIT %d) bounded_logs',
            $budget, array('query_params' => array($start, $state['max_log_id'], $state['chunk_size'])));
        $lastId = (new ABJ_404_Solution_LogsReadResult($boundary, 'logs-hits phase-one boundary'))->integerAggregate(true);
        if ($lastId === 0) {
            $state['next_log_id'] = $state['max_log_id'] + 1;
            $state['phase'] = 'merge';
            return $state;
        }
        if ($lastId < $start || $lastId > $state['max_log_id']) {
            throw new UnexpectedValueException('Logs-hits phase-one boundary is outside its snapshot.');
        }
        $end = $lastId + 1;
        // MIN(id) of every preaggregated row belongs to exactly this range.
        // @cache-write-audit: opt-out - replay removes only an unpublished generation's current range.
        $this->query('DELETE FROM ' . $tables['preagg'] . ' WHERE logsid >= %d AND logsid < %d',
            $budget, array('query_params' => array($start, $end)));
        $canonical = $this->joinHelper->isLogsv2CanonicalUrlBackfillComplete(fn(): array => $this->queryAdmissionOptions($budget))
            ? 'canonical_url' : "COALESCE(canonical_url, CONCAT('/', TRIM(BOTH '/' FROM requested_url)))";
        $this->query('/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertChunked#phase1Chunk */ INSERT INTO '
            . $tables['preagg'] . ' (requested_url, logsid, last_used, logshits, failed_hits) SELECT '
            . $canonical . ", MIN(id), MAX(timestamp), COUNT(*), SUM(CASE WHEN dest_url = '' OR dest_url IS NULL THEN 1 ELSE 0 END) FROM "
            . '{wp_abj404_logsv2} WHERE id >= %d AND id < %d GROUP BY ' . $canonical,
            $budget, array('query_params' => array($start, $end)));
        $state['next_log_id'] = $end;
        if ($end > $state['max_log_id']) {
            $state['phase'] = 'merge';
        } else {
            $state['chunk_size'] = $budget->nextLogChunkSize($state['chunk_size'],
                max(0.0, abj_clock()->nowFloat() - $started));
        }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function mergeRedirectRange(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tables = $this->generationTables($state);
        $result = $this->query('SELECT id FROM {wp_abj404_redirects} FORCE INDEX (PRIMARY) WHERE id > %d ORDER BY id ASC LIMIT %d',
            $budget, array('query_params' => array($state['merge_after_id'], self::MERGE_CHUNK_SIZE)));
        $ids = $this->redirectIds($result, $state['merge_after_id']);
        if (empty($ids)) { $state['phase'] = 'publish'; return $state; }
        $queryOptionsFactory = fn(): array => $this->queryAdmissionOptions($budget);
        $joinRhs = $this->joinHelper->buildPhase2JoinRhs(
            $this->joinHelper->resolveHitsJoinCollation($queryOptionsFactory), $queryOptionsFactory);
        // DISTINCT prevents duplicate redirects multiplying hits; LEFT JOIN to
        // the regular scratch target makes replays and cross-range duplicates
        // harmless without a target-table subquery (MySQL 5.6 compatibility).
        $sql = '/* abj404:src=LogsHitsTableRebuilder::hitsTableInsertChunked#phase2Aggregate */ INSERT INTO '
            . $tables['temp'] . ' (requested_url, logsid, last_used, logshits, failed_hits) '
            . 'SELECT a.requested_url, MIN(a.logsid), MAX(a.last_used), SUM(a.logshits), SUM(a.failed_hits) FROM '
            . '(SELECT DISTINCT ' . $joinRhs . ' AS requested_url FROM {wp_abj404_redirects} r WHERE r.id IN ('
            . implode(',', $ids) . ')) redirect_keys INNER JOIN ' . $tables['preagg']
            . ' a ON a.requested_url = redirect_keys.requested_url LEFT JOIN ' . $tables['temp']
            . ' existing ON existing.requested_url = a.requested_url WHERE existing.requested_url IS NULL GROUP BY a.requested_url';
        $this->query($sql, $budget);
        $state['merge_after_id'] = max($ids);
        if (count($ids) < self::MERGE_CHUNK_SIZE) { $state['phase'] = 'publish'; }
        return $state;
    }

    /**
     * @param array{rows:array<mixed>} $result
     * @return list<int>
     */
    private function redirectIds(array $result, int $afterId): array {
        $ids = array();
        foreach ($result['rows'] as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
            if ($id === null || $id <= $afterId) {
                throw new UnexpectedValueException('Invalid redirect ID after logs-hits merge cursor ' . $afterId . '.');
            }
            $ids[] = $id;
            $afterId = $id;
        }
        return $ids;
    }

    /**
     * @param State $state
     * @return State
     */
    private function publishGeneration(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tables = $this->generationTables($state);
        $status = $this->readLiveStatus($budget);
        if (!$this->statusMatchesGeneration($status, $state)) {
            $this->query('ALTER TABLE ' . $tables['temp'] . ' COMMENT %s', $budget,
                array('query_params' => array($state['elapsed_time'] . '|' . $state['max_log_id'] . '|v1:' . $state['generation'])));
            // DDL auto-commits: a transaction around DROP + RENAME does not
            // protect the live table. One RENAME keeps it continuously whole.
            $rename = empty($status) ? '' : $tables['live'] . ' TO ' . $tables['backup'] . ', ';
            $this->query('RENAME TABLE ' . $rename . $tables['temp'] . ' TO ' . $tables['live'], $budget);
        }
        $state['phase'] = 'denormalize';
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function writeBackRedirectRange(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $result = ABJ_404_Solution_RedirectsDenormChunkResolver::writeHitsChunk($this->dbCore, $this->logger, array(
            'after_id' => $state['denorm_after_id'],
            'before_query' => fn(): array => $this->queryAdmissionOptions($budget),
        ));
        if ($result['error'] !== '') { throw new RuntimeException($result['error']); }
        $state['denorm_after_id'] = $result['cursor'];
        if ($result['complete']) { $state['phase'] = 'cleanup'; }
        return $state;
    }

    /**
     * @param State $state
     * @return State
     */
    private function cleanGeneration(array $state, ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $tables = $this->generationTables($state);
        $kinds = array('preagg', 'backup');
        if ($state['cleanup_index'] < count($kinds)) {
            $scratchTable = $tables[$kinds[$state['cleanup_index']]];
            $this->query('DROP TABLE IF EXISTS ' . $scratchTable, $budget);
            $state['cleanup_index']++;
        }
        if ($state['cleanup_index'] === count($kinds)) { $state['phase'] = 'complete'; }
        return $state;
    }

    /**
     * Shared SQL admission for staging, canonical probes and metadata fallbacks.
     * @return array{timeout:int}
     */
    private function queryAdmissionOptions(ABJ_404_Solution_LogsHitsRebuildBudget $budget): array {
        $this->renewLeaseOrThrow();
        return array('timeout' => $budget->timeoutSeconds());
    }

    /**
     * SQL timeout and exclusive-lease boundary shared by every staging unit.
     * @param array<string,mixed> $options
     * @return array{rows:array<mixed>,last_error?:mixed,timed_out?:mixed}
     */
    private function query(string $sql, ABJ_404_Solution_LogsHitsRebuildBudget $budget, array $options = array()): array {
        $options = array_merge(array('log_too_slow' => false), $options, $this->queryAdmissionOptions($budget));
        $result = $this->dbCore->queryAndGetResults($sql, $options);
        $this->renewLeaseOrThrow();
        $error = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($error !== '' || !empty($result['timed_out'])) {
            if (strpos($sql, '#phase1Chunk') !== false || strpos($sql, '#phase2Aggregate') !== false) {
                $this->recordHitsChunkFailure();
            }
            throw new RuntimeException($error !== '' ? $error : 'Logs-hits rebuild SQL timed out.');
        }
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            if (strpos($sql, '#phase1Chunk') !== false || strpos($sql, '#phase2Aggregate') !== false) {
                $this->recordHitsChunkFailure();
            }
            throw new RuntimeException('Logs-hits rebuild SQL failed without an error message.');
        }
        $rows = $result['rows'] ?? null;
        if (!is_array($rows)) {
            throw new UnexpectedValueException('Logs-hits rebuild SQL returned malformed rows.');
        }
        return array_merge($result, array('rows' => $rows));
    }

    /**
     * @param State $state
     * @return array{live:string,temp:string,preagg:string,backup:string}
     */
    private function generationTables(array $state): array {
        if (!is_string($state['generation'] ?? null) || !preg_match('/^[0-9a-f]{16}$/D', $state['generation'])) {
            throw new InvalidArgumentException('Unsafe logs-hits staging generation; refusing table mutation.');
        }
        $live = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $stem = substr($live, 0, 36) . '_w' . $state['generation'];
        return array('live' => $live, 'temp' => $stem . '_temp', 'preagg' => $stem . '_preagg', 'backup' => $stem . '_old');
    }

    /** Abort before a superseded worker can mutate or publish another unit. */
    private function renewLeaseOrThrow(): void {
        if ($this->leaseRenewer !== null && !(bool)call_user_func($this->leaseRenewer)) {
            throw new RuntimeException('The logs-hits rebuild lost its exclusive lease; preserving replacement ownership.', 409);
        }
    }

    /**
     * Fill the {CHARSET} / {COLLATION} pair in a staging-table DDL from the one
     * collation the staging table must honour.
     *
     * The rollup's phase-2 JOIN probes redirects.canonical_url, so requested_url
     * has to carry that column's collation or the index cannot serve the probe.
     * The charset therefore cannot be chosen independently: the DDL used to
     * hard-code CHARACTER SET utf8mb4 next to a column collation read from
     * information_schema, and on an install whose redirects table is still
     * latin1 the engine rejects the CREATE outright ("COLLATION
     * 'latin1_swedish_ci' is not valid for CHARACTER SET 'utf8mb4'"), taking the
     * whole rollup rebuild with it. Both halves now come from one pair.
     *
     * @param array{ddl: string, rawCollation: string} $options
     * @return string DDL with a self-consistent charset/collation pair.
     */
    private function applyJoinCharsetCollation(array $options): string {
        $pair = ABJ_404_Solution_DatabaseCollationHelper::charsetCollationPair(
            $options['rawCollation']
        );
        return str_replace(
            array('{CHARSET}', '{COLLATION}'),
            array($pair['charset'], $pair['collation']),
            $options['ddl']
        );
    }

    /** @param int $idRange @return int */
    public function getHitsRebuildChunkSize(int $idRange): int {
        $planned = $this->rebuildHealth === null ? self::HITS_TABLE_PREAGG_CHUNK_SIZE
            : $this->rebuildHealth->getHitsChunkSize($idRange);
        return min(ABJ_404_Solution_LogsHitsRebuildBudget::INITIAL_LOG_CHUNK_SIZE, $planned);
    }

    /** @return void */
    private function recordHitsChunkFailure(): void {
        if ($this->rebuildHealth !== null) {
            $this->rebuildHealth->recordHitsChunkFailure();
        }
    }

    /** @param int $chunkSize @return void */
    private function recordHitsRebuildSuccess(int $chunkSize): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFullRebuildSuccess($chunkSize);
        $this->rebuildHealth->recordSuccess();
    }

    /** @param string $message @return void */
    private function recordHitsRebuildFailure(string $message): void {
        if ($this->rebuildHealth === null) {
            return;
        }
        $this->rebuildHealth->recordFailure($message, $this->rebuildHealth->classifyError($message));
    }
}
