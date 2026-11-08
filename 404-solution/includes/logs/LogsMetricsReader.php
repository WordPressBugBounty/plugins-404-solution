<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsReadResult.php';

/**
 * Logs-table row count and on-disk size reads, with a multisite-aware
 * transient cache around the unfiltered count path.
 *
 * Extracted from ViewReadService in the i805 decomposition. The unfiltered
 * `getLogsCount(0)` path is cached keyed on (blog_id, max_log_id) because the
 * old logsv2 table can be very large and dashboards hit it on every page
 * load; the per-id variant joins by logID and is left uncached (callers ask
 * for a single id, the cache hit ratio is near zero).
 */
class ABJ_404_Solution_LogsMetricsReader {

    /** Cache TTL for the unfiltered getLogsCount(0) total row count. */
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $f,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * @param int $logID 0 for the unfiltered total (cached); nonzero for a per-id count.
     * @param array<string, mixed> $tableOptions Internal strict-read and timeout options.
     * @return int Count, or -1 when a non-strict read could not determine it.
     */
    public function getLogsCount($logID, array $tableOptions = array()): int {
        $logID = absint($logID);
        $strict = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $timeout = ABJ_404_Solution_ExactInteger::read(
            $tableOptions['_abj404_query_timeout'] ?? null, 1
        );

        // A strict or budgeted request cannot spend an unbounded pre-read on
        // MAX(id), nor can a failed MAX safely identify an old zero cache entry.
        $cacheKey = ($strict || $timeout !== null) ? null : $this->logsCountCacheKey($logID);
        if ($cacheKey !== null) {
            $cached = get_transient($cacheKey);
            $cachedCount = ABJ_404_Solution_ExactInteger::read($cached, 0);
            if ($cachedCount !== null) {
                return $cachedCount;
            }
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsCount.sql");

        if ($logID != 0) {
            $query = $this->f->str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = $this->f->str_replace('{logID}', (string)$logID, $query);
        }

        try {
            $count = $this->readCount($query, array_replace($tableOptions, array('logsid' => $logID)));
        } catch (ABJ_404_Solution_ViewQueryFailureException $failure) {
            if ($strict) {
                throw $failure;
            }
            $diagnostics = $failure->getDiagnostics();
            if ((!is_string($diagnostics['last_error'] ?? null) || $diagnostics['last_error'] === '')
                && empty($diagnostics['timed_out'])) {
                $this->logger->warnCaught('Logs count query result could not be validated.', $failure);
            }
            $count = -1;
        }

        if ($count < 0) {
            return $count;
        }

        if ($cacheKey !== null && function_exists('set_transient')
            && empty($GLOBALS['abj404_feedback_preview_readonly'])) {
            set_transient($cacheKey, $count, self::LOGS_COUNT_CACHE_TTL_SECONDS);
        }

        return function_exists('apply_filters')
            ? (int) apply_filters('abj404_logs_count', $count, $logID)
            : $count;
    }

    /**
     * Returns the on-disk size in bytes of the logsv2 table, or -1 on query
     * failure (so callers can distinguish "table is empty" from "we don't
     * know"). The `abj404_log_disk_usage` filter lets tests stub the value.
     *
     * @return int
     */
    public function getLogDiskUsage(): int {
        $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables '
                . 'WHERE table_schema=DATABASE() AND table_name=\'{wp_abj404_logsv2}\'';

        $result = $this->dbCore->queryAndGetResults($query);

        // queryAndGetResults() already logged the failure at its own severity
        // (an infrastructure error is a warning); re-logging it here as an
        // error escalated every disk-full or dropped connection to a report.
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return -1;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }

        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $size = $row['tablesize'] ?? null;
        if ($size === null || !is_scalar($size)) {
            return 0;
        }
        $bytes = intval($size);
        return function_exists('apply_filters')
            ? (int) apply_filters('abj404_log_disk_usage', $bytes)
            : $bytes;
    }

    /**
     * Cache key for the unfiltered getLogsCount(0) total. Returns null when
     * caching is not applicable (per-id path, or transient API unavailable).
     *
     * @param int $logID
     * @return string|null
     */
    private function logsCountCacheKey(int $logID): ?string {
        if ($logID !== 0 || !function_exists('get_transient')) {
            return null;
        }
        $maxLogId = $this->maxLogIdForCache();
        return $maxLogId === null
            ? null
            : 'abj404_logs_count_v1_' . $this->currentBlogIdForCache() . '_' . $maxLogId;
    }

    /** @param array<string, mixed> $tableOptions Preserve legacy strict reads outside the admin endpoint. */
    private function readCount(string $query, array $tableOptions): int {
        if (!empty($tableOptions['_abj404_allow_degraded_read'])) {
            return abj_service('admin_table_reader')->count(array(
                'statement' => array('sql' => $query, 'params' => array()), 'subpage' => 'abj404_logs',
                'tableOptions' => $tableOptions, 'operation' => 'getLogsCount',
            ));
        }
        $timeout = ABJ_404_Solution_ExactInteger::read($tableOptions['_abj404_query_timeout'] ?? null, 1);
        $result = $this->dbCore->queryAndGetResults($query,
            $timeout === null ? array() : array('timeout' => $timeout));
        return (new ABJ_404_Solution_LogsReadResult($result, 'getLogsCount'))->integerAggregate();
    }

    /** @return int */
    private function currentBlogIdForCache(): int {
        if (!function_exists('get_current_blog_id')) {
            return 1;
        }
        $rawBlogId = function_exists('absint')
            ? absint(get_current_blog_id())
            : abs(intval(get_current_blog_id()));
        return $rawBlogId > 0 ? $rawBlogId : 1;
    }

    /** @return int|null */
    private function maxLogIdForCache(): ?int {
        try {
            return max(0, intval($this->logsRepo->getMaxLogId()));
        } catch (Throwable $e) {
            $this->logger->warnCaught(__FUNCTION__ . ' getMaxLogId() failed; count caching is disabled for this read.', $e);
            return null;
        }
    }
}
