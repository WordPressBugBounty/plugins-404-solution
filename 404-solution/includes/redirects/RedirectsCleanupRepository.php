<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRetentionPolicy.php';
require_once __DIR__ . '/RedirectDuplicatesRepository.php';
require_once __DIR__ . '/../view-build/ViewReadRuntimeState.php';

/**
 * Repository operations for scheduled redirect and log cleanup.
 */
class ABJ_404_Solution_RedirectsCleanupRepository {

    /**
     * Rows handled per cleanup SELECT batch. Each cleanup SELECT is bounded
     * with a LIMIT of this size so a large match set (up to ~630k rows on big
     * installs) never loads into PHP memory all at once during cron.
     * @var int
     */
    const CLEANUP_BATCH_SIZE = 1000;

    /**
     * Maximum number of cleanup batches handled per run (safety backstop:
     * caps a run at CLEANUP_BATCH_SIZE * CLEANUP_MAX_BATCHES rows). Prevents an
     * infinite loop if a row's deletion silently fails and it keeps
     * re-matching the SELECT.
     * @var int
     */
    const CLEANUP_MAX_BATCHES = 1000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRetentionPolicy */
    private $retentionPolicy;

    /** @var ABJ_404_Solution_CleanupQuerySession */
    private $querySession;

    /** @var ABJ_404_Solution_LogRetentionRepository */
    private $logRetentionRepository;

    /** @var ABJ_404_Solution_RedirectBatchDeletion */
    private $redirectBatchDeletion;

    /** @var ABJ_404_Solution_RedirectDuplicatesRepository */
    private $duplicateRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectsRetentionPolicy|null $retentionPolicy
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo,
        ABJ_404_Solution_Functions $functions,
        ABJ_404_Solution_Logging $logger,
        ?ABJ_404_Solution_RedirectsRetentionPolicy $retentionPolicy = null
    ) {
        $this->dbCore = $dbCore;
        $this->redirectsRepo = $redirectsRepo;
        $this->f = $functions;
        $this->logger = $logger;
        $this->retentionPolicy = $retentionPolicy !== null ? $retentionPolicy : new ABJ_404_Solution_RedirectsRetentionPolicy();
        $this->querySession = new ABJ_404_Solution_CleanupQuerySession($dbCore);
        $this->logRetentionRepository = new ABJ_404_Solution_LogRetentionRepository(
            $this->querySession, $this->retentionPolicy
        );
        $this->redirectBatchDeletion = new ABJ_404_Solution_RedirectBatchDeletion(array(
            'dbCore' => $dbCore,
            'redirectsRepo' => $redirectsRepo,
            'querySession' => $this->querySession,
        ));
        $this->duplicateRepository = new ABJ_404_Solution_RedirectDuplicatesRepository(
            $this->querySession
        );
    }

    /** Return the first direct-query failure from the most recent cleanup operation. */
    public function lastCleanupFailure(): string {
        return $this->querySession->failure();
    }

    /** @param array<string,mixed> $limits */
    public function cleanupOrphanedAutoRedirects(array $limits = array()): int {
        $limits = $this->querySession->begin($limits);
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableNameResolver()->tableExists($redirectsTable)) {
            $this->logger->warn("Skipping orphaned redirect cleanup: table missing.");
            return 0;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getOrphanedAutoRedirects.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->replaceKnownConstants($query);

        $logRow = function (array $row): void {
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $this->logger->debugMessage('Orphaned auto redirect deleted: "' . $url . '" (dest post ' .
                (isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '?') . ' missing/unpublished).');
        };
        return $this->redirectBatchDeletion->deleteMatches($query, $logRow, $limits);
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @param array<string,mixed> $limits
     * @return int
     */
    public function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType,
            array $limits = array()) {
        $limits = $this->querySession->begin($limits);
        $logsRepo = abj_service('logs_repository');

        $deletionDays = $this->retentionPolicy->daysFromOptions(is_array($options) ? $options : array(), $optionKey);
        $then = $this->retentionPolicy->cutoffForDays($deletionDays, $now);
        if ($then === null) {
            return 0;
        }

        $this->dbCore->tableNameResolver()->setSqlBigSelects();

        if (!$logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipping: logs_hits table missing; scheduling rebuild.");
            $logsRepo->scheduleHitsTableRebuild();
            return 0;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', (string)$then, $query);
        $logsHitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $redirectUrlExpression = "COALESCE(r.canonical_url, r.url)";
        $comparableRedirectUrl = $this->dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $redirectUrlExpression,
            array('table' => $logsHitsTable, 'column' => 'requested_url')
        );
        $query = $this->f->str_replace('{logs_hits_url_rhs}', $comparableRedirectUrl, $query);

        $logRow = function (array $row) use ($debugMessageType): void {
            $fromUrl = isset($row['from_url']) && is_scalar($row['from_url']) ? (string)$row['from_url'] : '';
            $lastUsed = isset($row['last_used_formatted']) && is_scalar($row['last_used_formatted'])
                ? (string)$row['last_used_formatted']
                : '';
            $bestGuessDest = isset($row['best_guess_dest']) && is_scalar($row['best_guess_dest'])
                ? (string)$row['best_guess_dest']
                : '';
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . $fromUrl .
                    '" deleted (last used: ' . $lastUsed . ').');
            } else {
                $this->logger->debugMessage($debugMessageType . " from: " . $fromUrl . ' to: ' .
                    $bestGuessDest . ' deleted (last used: ' . $lastUsed . ').');
            }
        };
        return $this->redirectBatchDeletion->deleteMatches($query, $logRow, $limits);
    }

    /** @param array<string,mixed> $limits */
    public function deleteOldLogsByAge(int $daysToKeep, int $now, array $limits = array()): int {
        return $this->logRetentionRepository->deleteOldLogsByAge($daysToKeep, $now, $limits);
    }

    /** @param array<string,mixed> $limits */
    public function removeDuplicatesCron(array $limits = array()): int {
        return $this->duplicateRepository->removeDuplicates($limits);
    }

    /**
     * @param array<string,mixed> $request
     * @return array{removed:int,complete:bool,duplicate_scan:array<string,mixed>|null,failure:string}
     */
    public function removeDuplicatesSlice(array $request): array {
        return $this->duplicateRepository->slice($request);
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $limits
     */
    public function autoTrashJunkCapturedUrls(array $options, array $limits = array()): int {
        $limitsProvided = $limits !== array();
        $limits = $this->querySession->begin($limits);
        $enabled = $options['auto_trash_junk_urls'] ?? '0';
        if ($enabled !== '1') {
            return 0;
        }

        $transientKey = 'abj404_last_auto_trash';
        // Both modes honor the one-hour cooldown. A bounded (staged) run sets it only once the
        // backlog is drained (below), so the continuation batches of a draining backlog still run.
        if (get_transient($transientKey) !== false) { return 0; }
        if (!$limitsProvided) {
            // allow-cache-empty: timestamp marker rate-limits automatic trash cleanup; not a cached query payload.
            set_transient($transientKey, abj_clock()->now(), HOUR_IN_SECONDS);
        }

        $patternsRaw = $options['auto_trash_junk_patterns'] ?? '';
        $patternsStr = is_string($patternsRaw) ? $patternsRaw : '';
        $lines = array_filter(array_map('trim', explode("\n", $patternsStr)));

        if (empty($lines)) {
            return 0;
        }

        global $wpdb;
        $totalTrashed = 0;

        // The admin-entered patterns are bound LAST, as positional query parameters: the executor
        // expands table names on the template and only then prepares the statement, so a pattern
        // holding a `{wp_...}` token is matched literally instead of being rewritten.
        $likeClauses = array();
        $likeParams = array();
        foreach ($lines as $pattern) {
            $likeClauses[] = "url LIKE %s";
            $likeParams[] = '%' . $wpdb->esc_like($pattern) . '%';
        }

        $wherePatterns = implode(' OR ', $likeClauses);
        $query = "UPDATE {wp_abj404_redirects}
            SET disabled = 1
            WHERE status = " . ABJ404_STATUS_CAPTURED . "
            AND disabled = 0
            AND (" . $wherePatterns . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->querySession->query($query . ($limitsProvided ? ' LIMIT ' . $limits['batch_size'] : ''),
            array('query_params' => $likeParams));
        $patternFailed = $this->querySession->failure() !== '';
        $patternAffected = $this->querySession->affectedRows($result);
        $totalTrashed += $patternAffected;
        $patternsDrained = !$patternFailed && $patternAffected < $limits['batch_size'];

        if ($patternFailed) {
            ABJ_404_Solution_ViewCacheInvalidator::invalidateCapturedStatusCountsCache();
            return $totalTrashed;
        }

        $cutoff = abj_clock()->now() - (14 * DAY_IN_SECONDS);
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $comparableRedirectUrl = $this->dbCore->collationHelper()->coerceExpressionToColumnCollation(
            'r.url',
            array('table' => $logsTable, 'column' => 'requested_url')
        );
        $query = "UPDATE {wp_abj404_redirects} r
            SET r.disabled = 1
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . "
            AND r.disabled = 0
            AND r.timestamp < %d
            AND NOT EXISTS (
                SELECT 1 FROM {wp_abj404_logsv2} l
                WHERE l.requested_url = {logs_url_rhs}
                LIMIT 1
            )";
        $query = $this->f->str_replace('{logs_url_rhs}', $comparableRedirectUrl, $query);
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->querySession->query($query . ($limitsProvided ? ' LIMIT ' . $limits['batch_size'] : ''),
            array('query_params' => array($cutoff)));
        $staleFailed = $this->querySession->failure() !== '';
        $staleAffected = $this->querySession->affectedRows($result);
        $totalTrashed += $staleAffected;
        $staleDrained = !$staleFailed && $staleAffected < $limits['batch_size'];

        if ($limitsProvided && $patternsDrained && $staleDrained) {
            // allow-cache-empty: cooldown starts only after both bounded predicates are drained.
            set_transient($transientKey, abj_clock()->now(), HOUR_IN_SECONDS);
        }

        if ($totalTrashed > 0) {
            $this->logger->infoMessage("Auto-trashed " . $totalTrashed . " junk/stale captured URLs during maintenance.");
        }
        if ($totalTrashed > 0 || $staleFailed) {
            ABJ_404_Solution_ViewCacheInvalidator::invalidateCapturedStatusCountsCache();
        }

        return $totalTrashed;
    }

    /** @param array<string,mixed> $limits */
    public function expireOldAutoRedirects(int $days, int $now, array $limits = array()): int {
        $limits = $this->querySession->begin($limits);
        $cutoff = $this->retentionPolicy->cutoffForDays($days, $now);
        if ($cutoff === null) {
            return 0;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->dbCore->tableNameResolver()->tableExists($redirectsTable)) {
            $this->logger->warn("expireOldAutoRedirects: redirects table missing, skipping.");
            return 0;
        }

        // allow-unbounded-select: LIMIT %d appended at runtime by processRows (batched cron cleanup)
        $sql = "SELECT id FROM `{$redirectsTable}`
             WHERE status = %d
               AND disabled = 0
               AND `timestamp` > 0
               AND `timestamp` < %d";

        // Each batch SELECTs the next LIMIT-bounded slice of still-enabled
        // expired auto-redirects, then trashes them. moveRedirectsToTrash sets
        // disabled = 1, so trashed rows drop out of the next batch's
        // `disabled = 0` filter and the loop advances without an offset. On a
        // batch that times out or errors, the closure returns array() so the
        // loop stops cleanly (preserving the original bail-on-error semantics
        // for the first batch).
        $runBatch = function (int $batchSize, ?int $timeout) use ($sql, $cutoff): array {
            $queryOptions = array(
                'query_params' => array(ABJ404_STATUS_AUTO, $cutoff, (int)$batchSize),
            );
            if ($timeout !== null) {
                $queryOptions['timeout'] = $timeout;
            }
            $result = $this->querySession->query($sql . " LIMIT %d", $queryOptions);
            if ($this->querySession->failure() !== '') {
                return array();
            }
            if (!is_array($result['rows'] ?? null)) {
                $this->querySession->noteFailure('expireOldAutoRedirects SELECT: result omitted rows');
                return array();
            }
            return is_array($result['rows'] ?? null) ? $result['rows'] : array();
        };
        $handleRow = function (array $row): bool {
            $id = ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1);
            if ($id === null) {
                $this->querySession->noteFailure('expireOldAutoRedirects SELECT: result row omitted a valid id');
                return false;
            }
            $result = $this->redirectsRepo->moveRedirectsToTrash($id, 1);
            if (is_string($result) && $result !== '') {
                $this->querySession->noteFailure('expireOldAutoRedirects move to trash: ' . $result);
                return false;
            }
            return true;
        };

        $moved = $this->querySession->processRows($runBatch, $handleRow, $limits);

        if ($moved > 0) {
            $this->logger->infoMessage("expireOldAutoRedirects: moved {$moved} expired auto-redirect(s) to trash (threshold: {$days} days).");
        }
        return $moved;
    }

    /**
     * Remove one bounded slice of oldest log rows until the caller's persisted
     * target is reached.
     *
     * @param int $targetKeep Minimum number of log rows to retain.
     * @param array<string, mixed> $limits
     * @return array{removed:int,complete:bool}
     */
    public function trimOldestLogsToCount(int $targetKeep, array $limits = array()): array {
        return $this->logRetentionRepository->trimOldestLogsToCount($targetKeep, $limits);
    }

}
