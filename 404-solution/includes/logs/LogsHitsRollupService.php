<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsHitsRollupServiceInterface.php';
require_once __DIR__ . '/LogsHitsCanonicalUrlJoinHelper.php';
require_once __DIR__ . '/LogsHitsTableRebuilder.php';
require_once __DIR__ . '/LogsHitsRebuildLock.php';
require_once __DIR__ . '/LogsHitsRollupStallWatchdog.php';

/**
 * wp_abj404_logs_hits rollup lifecycle (existence checks, scheduling,
 * locking, rebuild pipelines, staleness signaling, max/min id reads,
 * runtime-flag state).
 *
 * Extracted from LogsRepository under M201. LogsRepository now forwards
 * the rollup-related interface methods to this service. Internal log read
 * and write paths reach the rollup via LogsRepository's facade as well.
 */
class ABJ_404_Solution_LogsHitsRollupService implements ABJ_404_Solution_LogsHitsRollupServiceInterface {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300;
    /** @var int Minimum interval between hits-table rebuild schedules (server-side dedupe). */
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = 30;
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. Canonical home is the rebuild lock; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = ABJ_404_Solution_LogsHitsRebuildLock::TTL_SECONDS;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. Canonical home is the rebuild engine; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = ABJ_404_Solution_LogsHitsTableRebuilder::HITS_TABLE_PREAGG_CHUNK_SIZE;
    /** @var int Direct-path threshold for hits-table rebuild. Canonical home is the rebuild engine; aliased here for backward-compatible forwarding via LogsRepository. */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = ABJ_404_Solution_LogsHitsTableRebuilder::HITS_TABLE_DIRECT_PATH_THRESHOLD;

    /** @var string Runtime flag: last time we scheduled a rebuild. */
    const HITS_TABLE_LAST_SCHEDULED_FLAG = 'abj404_logs_hits_last_scheduled_at';
    /** @var string Runtime flag: last successful hits-table rebuild completion. */
    const HITS_TABLE_LAST_REFRESHED_FLAG = 'abj404_logs_hits_last_refreshed_at';
    /** @var string Durable flag: first stale detection timestamp. Canonical home is the stall watchdog. */
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = ABJ_404_Solution_LogsHitsRollupStallWatchdog::FIRST_STALL_FLAG;
    /** @var string Notice-payload type for a stalled logs_hits rollup. Canonical home is the stall watchdog. */
    const HITS_TABLE_STALE_NOTICE_TYPE = ABJ_404_Solution_LogsHitsRollupStallWatchdog::NOTICE_TYPE;
    /** @var int Minimum age (seconds) of stale gap before surfacing admin notice. Canonical home is the stall watchdog. */
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = ABJ_404_Solution_LogsHitsRollupStallWatchdog::NOTICE_THRESHOLD_SECONDS;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /**
     * Reset per-request scheduling state. Test seam: lets tests run multiple
     * scenarios in a single process without the static cooldown flag leaking
     * between them.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$hitsTableRebuildScheduled = false;
    }

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper */
    private $joinHelper;

    /** @var ABJ_404_Solution_LogsHitsRebuildLock */
    private $rebuildLock;

    /** @var ABJ_404_Solution_LogsHitsTableRebuilder */
    private $rebuilder;

    /** @var ABJ_404_Solution_LogsHitsRollupStallWatchdog */
    private $stallWatchdog;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder|null $noticeState
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logging = null,
        $rebuildHealth = null,
        $noticeState = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->noticeState = $noticeState !== null ? $noticeState : $dbCore->noticeState();
        $this->rebuildLock = new ABJ_404_Solution_LogsHitsRebuildLock($dbCore);
        $this->joinHelper = new ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper($dbCore);
        $this->rebuilder = new ABJ_404_Solution_LogsHitsTableRebuilder(
            $this->dbCore,
            $this->logger,
            $this->rebuildHealth,
            $this->joinHelper,
            array($this->rebuildLock, 'renew')
        );
        $this->stallWatchdog = new ABJ_404_Solution_LogsHitsRollupStallWatchdog(
            $this->noticeState,
            // The watchdog decides WHEN a rebuild may happen outside cron; the
            // gate/lock protocol that decides whether it CAN stays here.
            function (): bool {
                return $this->hitsTableNeedsRebuild()
                    ? $this->createRedirectsForViewHitsTable()
                    : false;
            },
            $this->logger
        );
    }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    private function resolveRebuildHealthState(): ?ABJ_404_Solution_RebuildHealthState {
        if (function_exists('abj_service_optional')) {
            $service = abj_service_optional('rebuild_health');
            if ($service instanceof ABJ_404_Solution_RebuildHealthState) {
                return $service;
            }
        }
        return null;
    }

    /**
     * Resolve the collation from the abj404_redirects.canonical_url column,
     * the actual join partner for the hits rebuild phase2 JOIN. Delegates
     * to the canonical-url join helper.
     *
     * @return string Sanitized collation identifier.
     */
    public function resolveHitsJoinCollation(): string {
        return $this->joinHelper->resolveHitsJoinCollation();
    }

    // =========================================================================
    // Staleness signaling
    // =========================================================================

    /** @inheritDoc */
    public function recordLogsHitsRollupStalenessSignal(): void {
        $this->stallWatchdog->observe($this->getMaxLogId(), $this->getStoredMaxLogId());
    }

    // =========================================================================
    // Rebuild readiness queries
    // =========================================================================

    /** @inheritDoc */
    public function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();
        if ($currentMaxId != $storedMaxId) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)"); return true; }
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) { $age = abj_clock()->now() - $lastUpdated; if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) { $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)"); return true; } }
        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;
        $schemaTimestamp = $this->readSchemaFreshnessEpoch();
        if ($schemaTimestamp === null) { return $runtimeRefreshedAt; }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) { return $runtimeRefreshedAt; }
        return $schemaTimestamp;
    }

    /**
     * The rollup table's own creation instant, as a true UTC epoch, read from
     * information_schema.
     *
     * The server is asked for UNIX_TIMESTAMP(create_time) rather than
     * create_time itself, because create_time is DISPLAYED in the MySQL session
     * timezone: one instant reads as "2026-08-13 21:52:51" at +00:00 and
     * "2026-08-14 06:52:51" at +09:00. Only the epoch form is timezone-invariant,
     * and it is the form the caller needs, since it compares this against
     * abj_clock()->now(), a true UTC epoch.
     *
     * @return int|null Epoch seconds, or null when the server has no usable
     *                  answer (no such table, or the read was refused and the
     *                  fallback could not date it either).
     */
    private function readSchemaFreshnessEpoch(): ?int {
        $query = "SELECT UNIX_TIMESTAMP(create_time) AS create_time_epoch FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] == null || empty($results['rows'])) {
            // Rows AND an error means the read was refused (restricted grants
            // on managed hosts), which is the only case worth a second attempt.
            return empty($results['last_error']) ? null : $this->readStatusFallbackFreshnessEpoch();
        }
        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? array_change_key_case($hitsRows[0]) : array();
        $createTimeEpoch = $row['create_time_epoch'] ?? null;
        if (!is_numeric($createTimeEpoch)) { return null; }
        $schemaTimestamp = (int)$createTimeEpoch;
        return $schemaTimestamp > 0 ? $schemaTimestamp : null;
    }

    /**
     * The same instant read through SHOW TABLE STATUS, for hosts that refuse
     * information_schema. Prefers Update_time, which InnoDB leaves NULL until
     * the table is written, and falls back to Create_time.
     *
     * @return int|null Epoch seconds, or null when the row carries no usable date.
     */
    private function readStatusFallbackFreshnessEpoch(): ?int {
        $statusRow = $this->getLogsHitsTableStatusRow();
        $dateValue = is_array($statusRow) ? ($statusRow['update_time'] ?? ($statusRow['create_time'] ?? '')) : '';
        if (!is_string($dateValue) || $dateValue === '') { return null; }
        return $this->mysqlDatetimeToEpoch($dateValue);
    }

    /**
     * Convert a datetime string the database server just rendered back into a
     * true UTC epoch, using that same server's session timezone.
     *
     * SHOW TABLE STATUS cannot be wrapped in an expression the way
     * information_schema's create_time can, so its Create_time / Update_time
     * arrive here as a string already rendered in the MySQL session timezone
     * (normally SYSTEM, i.e. the DB host's OS timezone). Handing that string to
     * PHP's strtotime() would re-read it in PHP's default timezone, which
     * WordPress pins to UTC -- two systems rendering and parsing the same
     * instant under different implicit timezones, which puts the rollup age out
     * by the server's whole UTC offset. Asking the server to convert its own
     * rendering keeps interpretation on the side that produced it, so the two
     * cannot disagree.
     *
     * @param string $mysqlDatetime A datetime as rendered by the server.
     * @return int|null Epoch seconds, or null when the server cannot convert it
     *                  (unparseable, zero date, or a failed conversion query).
     */
    private function mysqlDatetimeToEpoch(string $mysqlDatetime): ?int {
        $results = $this->dbCore->queryAndGetResults(
            "SELECT UNIX_TIMESTAMP(%s) AS `epoch`",
            array('query_params' => array($mysqlDatetime), 'log_errors' => false)
        );
        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($rows[0] ?? null) ? array_change_key_case($rows[0]) : array();
        $epoch = $row['epoch'] ?? null;
        if (!is_numeric($epoch)) { return null; }
        $epoch = (int)$epoch;
        return $epoch > 0 ? $epoch : null;
    }

    /** @return array<string, mixed> */
    private function getLogsHitsTableStatusRow() {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        // The name is BOUND by the executor (`query_params`) after its `{token}` pass over the template.
        $results = $this->dbCore->queryAndGetResults(
            "SHOW TABLE STATUS LIKE %s",
            array('query_params' => array($tableName), 'log_errors' => false)
        );
        if (!is_array($results['rows']) || empty($results['rows']) || !is_array($results['rows'][0])) { return array(); }
        // Lower-cased explicitly rather than through array_change_key_case(),
        // which is typed as preserving the input's (here unknown) key type and
        // so cannot satisfy this method's declared string-keyed contract.
        // MySQL and MariaDB disagree on the case of SHOW TABLE STATUS column
        // names, which is the whole reason the keys are normalized at all
        // (defensive philosophy #5, case-insensitive metadata access).
        $row = array();
        foreach ($results['rows'][0] as $column => $value) {
            $row[strtolower((string)$column)] = $value;
        }
        return $row;
    }

    // =========================================================================
    // Rebuild pipeline (direct + chunked)
    // =========================================================================

    /**
     * @inheritDoc
     *
     * Coordinates a rollup rebuild: enforces the health gate, write cooldown,
     * and cross-request lock, snapshots the logsv2 id range (so the tracking
     * test subclass and the pre-insert watermark both observe the same values),
     * then delegates the materialize-and-swap SQL to the rebuild engine. On a
     * successful refresh it stamps the freshness flag, clears the staleness
     * signal, and writes the denorm hit columns back onto the redirects rows.
     */
    public function createRedirectsForViewHitsTable(): bool {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed.");
            return false;
        }
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); return false; }
        if (!$this->rebuildLock->acquire()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held."); return false; }
        try {
            $maxLogIdSnapshot = $this->getMaxLogId();
            $minLogId = $this->getMinLogId();
            $result = $this->rebuilder->rebuildAndSwap($minLogId, $maxLogIdSnapshot);
            if (!$result['refreshed']) {
                return false;
            }
            $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, abj_clock()->now(), 86400);
            $this->stallWatchdog->clearStallState();
            $this->writeBackDenormHitsColumns();
            return true;
        } catch (Throwable $e) {
            // The rebuild engine self-handles its own SQL/Throwable failures and
            // signals them via the return value; reaching here means a post-swap
            // bookkeeping step (freshness flag, staleness clear, denorm write-back)
            // threw. Return false rather than letting the exception escape the
            // cron / shutdown listener.
            $this->logger->errorMessage(__FUNCTION__ . " post-rebuild step failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            return false;
        } finally {
            $this->rebuildLock->release();
        }
    }

    /**
     * After a successful rollup rebuild, push the freshly rolled-up hit count +
     * last-used timestamp from wp_abj404_logs_hits back onto the redirects rows
     * (Denorm Step 3c). This keeps the STORED logshits / last_used columns -
     * which order the full off-page result set - current in real time instead of
     * waiting for the nightly reconcile. Delegated to the denorm maintenance
     * service, resolved lazily so a minimal wiring (or a context where the
     * service is unregistered) degrades to a no-op rather than fataling.
     *
     * @return void
     */
    private function writeBackDenormHitsColumns(): void {
        $maintenance = new ABJ_404_Solution_RedirectsDenormMaintenanceService(
            $this->dbCore,
            $this->logger
        );
        $maintenance->writeBackLogsHitsColumns();
    }

    // =========================================================================
    // Table existence + scheduling
    // =========================================================================

    /** @inheritDoc */
    public function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        if ($results['rows'] != null && !empty($results['rows'])) { return true; }
        if (!empty($results['last_error'])) { return $this->logsHitsTableExistsViaShowTables(); }
        return false;
    }

    /** @return bool */
    private function logsHitsTableExistsViaShowTables(): bool {
        $tableName = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        // The name is BOUND by the executor (`query_params`) after its `{token}` pass over the template.
        $fallback = $this->dbCore->queryAndGetResults(
            "SHOW TABLES LIKE %s",
            array('query_params' => array($tableName), 'log_errors' => false)
        );
        if (empty($fallback['rows'])) { return false; }
        $fbRows = is_array($fallback['rows']) ? $fallback['rows'] : array();
        $firstRow = isset($fbRows[0]) ? $fbRows[0] : null;
        if (!is_array($firstRow)) { return false; }
        $value = reset($firstRow);
        return ((string)$value === (string)$tableName);
    }

    /** @inheritDoc */
    public function scheduleHitsTableRebuild(): void {
        if ($this->rebuildHealth !== null && !$this->rebuildHealth->mayStartExpensiveRebuild()) { $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild health gate is closed."); return; }
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()) { $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown."); return; }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->rebuildLock->isHeld()) { $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running."); return; }
            $rawScheduledFlag = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (abj_clock()->now() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown.");
            } else {
                self::$hitsTableRebuildScheduled = true;
                $this->noticeState->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, abj_clock()->now(), 86400);
                // A full rollup rebuild is background work in every request
                // context. PHP/LSAPI servers may send response headers early but
                // buffer the body until WordPress shutdown callbacks finish, so a
                // shutdown rebuild can keep the admin page blank for the entire
                // database operation. WP-Cron is therefore where this pipeline
                // is meant to run.
                $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron.");
                abj_cron_scheduler()->scheduleSingle(
                    ABJ_404_Solution_CronScheduler::HOOK_UPDATE_LOGS_HITS_TABLE,
                    5
                );
            }
        }
        // Armed is not executed. A host whose wp-cron.php loopback is refused
        // stores the event and never runs it, and this method would otherwise
        // re-arm it forever while the watermark stays where it was, which is
        // production report 395. The watchdog opens a post-response path on
        // evidence of exactly that, and detaches the response before using it,
        // so the buffered-body hazard above cannot occur on that path either.
        $this->stallWatchdog->armDeferredRebuildIfCronIsNotExecuting();
    }


    // =========================================================================
    // logsv2 / logs_hits id queries
    // =========================================================================

    /** @inheritDoc */
    public function getMaxLogId() {
        // allow-unbounded-select: MAX(id) aggregate; returns a single row
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /** @inheritDoc */
    public function getMinLogId() {
        // allow-unbounded-select: MIN(id) aggregate; returns a single row
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) { return 0; }
        $row = $resultRows[0];
        $minId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return ABJ_404_Solution_ExactInteger::readOr($minId, 0, 0);
    }

    /** @inheritDoc */
    public function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        $storedRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($storedRows)) {
            if (!empty($results['last_error'])) { $statusRow = $this->getLogsHitsTableStatusRow(); $commentFromStatus = $statusRow['comment'] ?? ''; if ($commentFromStatus !== '') { $parts = explode('|', is_string($commentFromStatus) ? $commentFromStatus : ''); if (count($parts) >= 2) { return (int)$parts[1]; } } }
            return 0;
        }
        $row = is_array($storedRows[0] ?? null) ? $storedRows[0] : array();
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';
        $parts = explode('|', is_string($comment) ? $comment : '');
        if (count($parts) >= 2) { return (int)$parts[1]; }
        return 0;
    }

    /** @inheritDoc */
    public function getLogsHitsTableLastScheduledAt() { $rawTsFlag2 = $this->noticeState->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG); $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0; return $ts > 0 ? $ts : null; }
}
