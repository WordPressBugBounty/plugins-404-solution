<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/LogsReadResult.php';

/**
 * The daily-activity trend query failed or timed out.
 *
 * Thrown rather than answered with a zero-filled series: a series of zeros
 * reads as "no activity", which misstates a site that failed to read its own
 * log. Same policy as RedirectHitCountHistogramQueryException, whose docblock
 * says query failures throw so the consumer omits the field instead of
 * publishing a false zero.
 */
class ABJ_404_Solution_TrendDataQueryException extends RuntimeException {
}

/**
 * Read-side queries for the logsv2 table feeding the admin Logs page,
 * autocomplete dropdowns, and the daily-activity trend chart.
 *
 * Responsibilities:
 *  - getLogRecords: paginated/sortable admin table read with field allow-listing.
 *  - getLogsIDandURL / getLogsIDandURLLike: dropdown lookups by URL value.
 *  - getDistinctLoggedUrls: distinct URL list for the GSC sitemap probe.
 *  - getDailyActivityTrend: cached daily counts (404 vs redirect) for charting.
 *
 * Extracted from LogsRepository under M201. Consumed by the LogsRepository
 * facade; the rollup-derived MAX(logsv2.id) used to invalidate the trend
 * cache is passed in by the facade so test rollups swapped in by
 * tests propagate naturally.
 */
class ABJ_404_Solution_LogsReadQueries {

    /** @var int Max age for cached daily-activity trend data. */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;

    /**
     * Default size of the recency window scanned to find distinct logged URLs.
     * The GSC URL probe only needs recent traffic, so we cap rows read from
     * logsv2 before deduplication.
     */
    const DEFAULT_RECENT_LOG_WINDOW = 5000;

    /** Hard ceiling on the recency window so a misbehaving caller cannot exhaust memory. */
    const MAX_RECENT_LOG_WINDOW = 50000;

    /** Default cap on the number of distinct URLs returned. */
    const DEFAULT_DISTINCT_URL_CAP = 500;

    /** Hard ceiling on the distinct URL cap. */
    const MAX_DISTINCT_URL_CAP = 5000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var bool Whether the latest getLogRecords() read failed or had unknown shape. */
    private $lastLogRecordsReadIncomplete = true;

    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Fetch the set of distinct recently-requested URLs from logsv2.
     *
     * Both bounds are caller-supplied so the cap is visible at the
     * repository boundary instead of being hidden inside the SQL file.
     * Values are clamped to [1, MAX_*] to keep this read safe even when
     * callers (or test fixtures) pass garbage.
     *
     * @param int $recentLogWindow Max rows scanned from logsv2 (clamped to [1, MAX_RECENT_LOG_WINDOW]).
     * @param int $distinctUrlCap  Max distinct URLs returned (clamped to [1, MAX_DISTINCT_URL_CAP]).
     * @return array<int, string>
     */
    public function getDistinctLoggedUrls(
        int $recentLogWindow = self::DEFAULT_RECENT_LOG_WINDOW,
        int $distinctUrlCap = self::DEFAULT_DISTINCT_URL_CAP
    ): array {
        $recentLogWindow = max(1, min(self::MAX_RECENT_LOG_WINDOW, $recentLogWindow));
        $distinctUrlCap = max(1, min(self::MAX_DISTINCT_URL_CAP, $distinctUrlCap));
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getDistinctLoggedUrls.sql");
        $query = $this->f->str_replace('{recent_window}', (string)$recentLogWindow, $query);
        $query = $this->f->str_replace('{distinct_cap}', (string)$distinctUrlCap, $query);
        $rows = $this->readLogRows($query, array('operation' => 'getDistinctLoggedUrls'));
        $urls = array();
        foreach ($rows as $row) {
            $url = isset($row['requested_url']) && is_string($row['requested_url']) ? $row['requested_url'] : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * @param string $specificURL
     * @param array<string, mixed> $tableOptions Internal timeout options.
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewQueryFailureException When the read is unavailable.
     */
    public function getLogsIDandURL($specificURL = '', array $tableOptions = array()) {
        $whereClause = ABJ_404_Solution_SqlFragmentTemplate::none();
        if ($specificURL != '') {
            // A 404 url is visitor-chosen. It is bound after the executor's token pass, so a
            // {wp_...} token inside it still matches the row that was logged with it.
            $whereClause = array(
                'sql' => 'where requested_url = %s',
                'params' => array($this->f->sanitizeInvalidUTF8($specificURL)),
            );
        }
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsIDandURL.sql");
        $statement = ABJ_404_Solution_SqlFragmentTemplate::fill($query, array('where_clause_here' => $whereClause));
        return $this->readLogRows($statement['sql'], array(
            'operation' => 'getLogsIDandURL',
            'table_options' => $tableOptions,
            'query_params' => $statement['params'],
        ));
    }

    /**
     * @param string $specificURL
     * @param string|int $limitResults
     * @param array<string, mixed> $tableOptions Internal timeout options.
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewQueryFailureException When the read is unavailable.
     */
    public function getLogsIDandURLLike($specificURL, $limitResults, array $tableOptions = array()) {
        global $wpdb;
        $whereClause = ABJ_404_Solution_SqlFragmentTemplate::none();
        if ($specificURL != '') {
            $whereClause = array(
                'sql' => "where lower(requested_url) like lower(%s)\nand min_log_id = true",
                'params' => array('%' . $wpdb->esc_like($specificURL) . '%'),
            );
        }
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsIDandURLForAjax.sql");
        $statement = ABJ_404_Solution_SqlFragmentTemplate::fill($query, array(
            'where_clause_here' => $whereClause,
            'limit-results' => array('sql' => 'limit ' . absint($limitResults), 'params' => array()),
        ));
        return $this->readLogRows($statement['sql'], array(
            'operation' => 'getLogsIDandURLLike',
            'table_options' => $tableOptions,
            'query_params' => $statement['params'],
        ));
    }

    /**
     * Admin logs page read with allow-listed orderby + pagination.
     *
     * @param array<string, mixed> $tableOptions Boundary-normalized callers pass typed
     *                                            log/page values; this method keeps
     *                                            query-specific allowlists and bounds.
     * @return array<int, array<string, mixed>>
     */
    public function getLogRecords($tableOptions) {
        $this->lastLogRecordsReadIncomplete = true;
        $tableOptions = is_array($tableOptions) ? $tableOptions : array();
        $sortPending = false;
        if (!empty($tableOptions['_abj404_allow_degraded_read'])) {
            $ready = (new ABJ_404_Solution_LogsUrlSortRepository($this->dbCore))->isReady();
            $timestampReady = $this->timestampIndexReady();
            $tableOptions['_abj404_logs_url_sort_ready'] = $ready;
            $tableOptions['_abj404_logs_timestamp_index_ready'] = $timestampReady;
            $pending = $ready ? array() : array('url', 'requested_url');
            if (!$timestampReady) { $pending[] = 'timestamp'; }
            abj_service('admin_table_reader')->setPendingSortColumns($pending);
            $sortPending = in_array($tableOptions['orderby'] ?? 'timestamp', $pending, true);
        }
        $query = $this->buildLogRecordsQuery($tableOptions);
        try {
            if (!empty($tableOptions['_abj404_prepare_count'])) {
                abj_service('view_read_service')->getLogsCount(
                    $this->positiveIntOption($tableOptions, 'logsid', 0), $tableOptions);
            }
            $rows = !empty($tableOptions['_abj404_allow_degraded_read'])
                ? abj_service('admin_table_reader')->read(array(
                    'statement' => array('sql' => $query, 'params' => array()),
                    'fallback' => array('sql' => $this->buildLogRecordsQuery($tableOptions, true), 'params' => array()),
                    'subpage' => 'abj404_logs', 'tableOptions' => $tableOptions,
                    'sortPending' => $sortPending,
                ))
                : $this->readLogRows($query, array('operation' => 'getLogRecords', 'table_options' => $tableOptions));
            $this->lastLogRecordsReadIncomplete = false;
            return $rows;
        } catch (ABJ_404_Solution_ViewQueryFailureException $failure) {
            if (!empty($tableOptions['_abj404_throw_on_view_query_error'])) {
                throw $failure;
            }
            return array();
        }
    }

    /** A verified full timestamp index can serve either Date direction without a filesort. */
    private function timestampIndexReady(): bool {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $indexes = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($table);
        $index = $indexes['timestamp'] ?? null;
        return is_array($index) && ABJ_404_Solution_TableIndexDefinitions::isDescribable($index)
            && $index['columns'] === array(array('column' => 'timestamp', 'prefix' => null));
    }

    /** @param array<string, mixed> $tableOptions Build the exact or bounded version of the same log query. */
    private function buildLogRecordsQuery(array $tableOptions, bool $bounded = false): string {
        $logsid_included = '';
        $logsid = '';
        $logsIdValue = $this->positiveIntOption($tableOptions, 'logsid', 0);
        if ($logsIdValue > 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = (string)$logsIdValue;
        }
        $orderbyExpressionByName = array(
            'timestamp'     => '{wp_abj404_logsv2}.timestamp',
            'requested_url' => '{wp_abj404_logsv2}.requested_url',
            'url'           => 'url',
            'id'            => '{wp_abj404_logsv2}.id',
            'min_log_id'    => '{wp_abj404_logsv2}.min_log_id',
        );
        $orderby = $this->stringOption($tableOptions, 'orderby', '');
        $orderby = array_key_exists($orderby, $orderbyExpressionByName) ? $orderby : 'timestamp';
        $orderbyExpression = $orderbyExpressionByName[$orderby];
        $order = strtoupper($this->stringOption($tableOptions, 'order', ''));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }
        $paged = $this->positiveIntOption($tableOptions, 'paged', 1);
        $perpage = $this->positiveIntOption($tableOptions, 'perpage', ABJ404_OPTION_DEFAULT_PERPAGE);
        $start = ($paged - 1) * $perpage;
        $source = '{wp_abj404_logsv2}';
        if (!$bounded && !empty($tableOptions['_abj404_logs_url_sort_ready'])
            && in_array($orderby, array('url', 'requested_url'), true)) {
            $orderbyExpression = '{wp_abj404_logsv2}.url_sort_key';
            $source .= ' FORCE INDEX (' . ABJ_404_Solution_LogsUrlSortRepository::INDEX_NAME . ')';
        }
        if (!$bounded && $orderby === 'timestamp' && !empty($tableOptions['_abj404_logs_timestamp_index_ready'])) {
            $source .= ' FORCE INDEX (timestamp)';
        }
        if ($bounded) {
            $start = 0;
            $perpage = min(ABJ_404_Solution_AdminTableReadRepository::PAGE_LIMIT, $perpage);
            $orderbyExpression = '{wp_abj404_logsv2}.id';
            $order = 'DESC';
            $source = '(SELECT * FROM {wp_abj404_logsv2} FORCE INDEX (PRIMARY) ORDER BY id DESC LIMIT '
                . ABJ_404_Solution_AdminTableReadRepository::CANDIDATE_LIMIT . ') {wp_abj404_logsv2}';
        }
        // The sorted column is rarely unique (many hits share one second and one URL), and rows
        // that tie have no defined order: the engine may order them differently for page 1 and
        // page 2, so a row on a page boundary is shown twice or never. End the ORDER BY in the
        // unique primary key, in the same direction, so every plan returns one total order. The
        // `timestamp` and `idx_url_sort_id` indexes end in the id, so they still serve both
        // directions without a filesort.
        $tieOrder = $orderbyExpression === '{wp_abj404_logsv2}.id' ? '' : ', {wp_abj404_logsv2}.id ' . $order;
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogRecords.sql");
        $query = $this->f->str_replace('{log_records_source}', $source, $query);
        $query = $this->f->str_replace('{logsid_included}', $logsid_included, $query);
        $query = $this->f->str_replace('{logsid}', $logsid, $query);
        $query = $this->f->str_replace('{orderby}', $orderbyExpression, $query);
        $query = $this->f->str_replace('{order}', $order, $query);
        $query = $this->f->str_replace('{tie_order}', $tieOrder, $query);
        $query = $this->f->str_replace('{start}', (string)$start, $query);
        $query = $this->f->str_replace('{perpage}', (string)$perpage, $query);
        return $bounded ? preg_replace('/select /i', 'select /* abj404 bounded admin page */ ', $query, 1) ?? $query : $query;
    }

    /** @return bool */
    public function lastLogRecordsReadWasIncomplete(): bool {
        return $this->lastLogRecordsReadIncomplete;
    }

    /**
     * Execute a logs read with caller-owned timeout and query bindings, then validate its result.
     *
     * @param array{operation: string, table_options?: array<string, mixed>, query_params?: array<int, mixed>} $context
     * @return array<int, array<string, mixed>>
     */
    private function readLogRows(string $query, array $context): array {
        $options = array();
        $tableOptions = $context['table_options'] ?? array();
        if (isset($tableOptions['_abj404_query_timeout'])) {
            $timeout = ABJ_404_Solution_ExactInteger::readOr(
                $tableOptions['_abj404_query_timeout'], 1, 0
            );
            if ($timeout > 0) {
                $options['timeout'] = $timeout;
            }
        }
        if (array_key_exists('query_params', $context)) {
            $options['query_params'] = $context['query_params'];
        }

        $result = $this->dbCore->queryAndGetResults($query, $options);
        $checked = new ABJ_404_Solution_LogsReadResult($result, $context['operation']);
        try {
            return $checked->rows();
        } catch (ABJ_404_Solution_ViewQueryFailureException $failure) {
            $diagnostics = $failure->getDiagnostics();
            if ((!is_string($diagnostics['last_error'] ?? null) || $diagnostics['last_error'] === '')
                && empty($diagnostics['timed_out'])) {
                $this->logger->warnCaught('Logs query result could not be validated for ' . $context['operation'] . '.', $failure);
            }
            throw $failure;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function positiveIntOption(array $options, string $key, int $default): int {
        $raw = $options[$key] ?? $default;
        if (!is_scalar($raw)) {
            return $default;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return $default;
        }
        $value = intval($raw);
        return $value > 0 ? $value : $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key, string $default): string {
        $raw = $options[$key] ?? $default;
        return is_string($raw) ? $raw : $default;
    }

    /** @return int Current blog id for the trend cache key (1 when WordPress cannot say). */
    private function trendCacheBlogId(): int {
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = function_exists('absint') ? absint(get_current_blog_id()) : abs(intval(get_current_blog_id()));
            if ($blogId <= 0) { $blogId = 1; }
        }
        return $blogId;
    }

    /**
     * MAX(logsv2.id) for the trend cache key, or null when the read failed.
     */
    private function trendCacheMaxLogId(ABJ_404_Solution_LogsRepositoryInterface $repo): ?int {
        try {
            return max(0, intval($repo->getMaxLogId()));
        } catch (Throwable $e) {
            $this->logger->warnCaught(__FUNCTION__ . ' getMaxLogId() failed; trend caching is disabled for this read.', $e);
            return null;
        }
    }

    /**
     * The exception for a failed or timed-out trend query, carrying the
     * database cause in the same `timed_out=true` / `last_error=...` form the
     * histogram repository uses.
     *
     * @param ABJ_404_Solution_ViewQueryFailureException $failure Original checked-read failure.
     */
    private function trendQueryFailure(ABJ_404_Solution_ViewQueryFailureException $failure): ABJ_404_Solution_TrendDataQueryException {
        $diagnostics = $failure->getDiagnostics();
        $lastError = $diagnostics['last_error'] ?? '';
        $context = 'timed_out=' . (!empty($diagnostics['timed_out']) ? 'true' : 'false')
            . '; last_error=' . (is_string($lastError) ? $lastError : 'unknown');
        return new ABJ_404_Solution_TrendDataQueryException(
            'Trend query failed (' . $context . '): ' . $failure->getMessage(), 0, $failure
        );
    }

    /**
     * Daily activity trend for the dashboard chart. Cached for 15 min keyed
     * on (blog id, day count, current MAX logsv2 id) so the cache invalidates
     * naturally as new rows arrive.
     *
     * @param int $days Number of days (clamped to 1-90)
     * @param ABJ_404_Solution_LogsRepositoryInterface $repo Source of the cache-busting MAX(logsv2.id) read; passing the facade (rather than the raw rollup) ensures subclass overrides propagate.
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days, ABJ_404_Solution_LogsRepositoryInterface $repo): array {
        $days = max(1, min(90, $days));
        $blogId = $this->trendCacheBlogId();
        $maxLogId = $this->trendCacheMaxLogId($repo);
        $cacheKey = $maxLogId === null ? null : 'abj404_trend_v3_' . $blogId . '_' . $days . '_' . $maxLogId;
        if ($cacheKey !== null && function_exists('get_transient')) { $cached = get_transient($cacheKey); if (is_array($cached)) { return $cached; } }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $now = abj_clock()->now();
        $cutoff = $now - ($days * 86400);
        $notFoundDest = '404';
        $query = "SELECT `timestamp` DIV 86400 AS `day_index`, SUM(CASE WHEN `dest_url` = %s THEN 1 ELSE 0 END) AS `hits_404`, SUM(CASE WHEN `dest_url` <> %s THEN 1 ELSE 0 END) AS `hits_redirect` FROM " . $logsTable . " WHERE `timestamp` >= " . intval($cutoff) . " GROUP BY `timestamp` DIV 86400 ORDER BY `day_index` ASC";
        $readFailure = null;
        $rows = array();
        try {
            $rows = $this->readLogRows($query, array(
                'operation' => 'getDailyActivityTrend',
                'query_params' => array($notFoundDest, $notFoundDest),
            ));
        } catch (ABJ_404_Solution_ViewQueryFailureException $failure) {
            $readFailure = $failure;
        }
        if ($readFailure !== null) {
            throw $this->trendQueryFailure($readFailure);
        }
        $byDayIndex = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            if (!isset($row['day_index']) || !is_numeric($row['day_index'])) { continue; }
            $dayIndex = intval($row['day_index']);
            $byDayIndex[$dayIndex] = array('hits_404' => intval($row['hits_404'] ?? 0), 'hits_redirect' => intval($row['hits_redirect'] ?? 0), 'new_captures' => intval($row['hits_404'] ?? 0));
        }
        $output = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $dayIndex = intdiv($now - ($i * 86400), 86400);
            $date = gmdate('Y-m-d', $dayIndex * 86400);
            $counts = $byDayIndex[$dayIndex] ?? array('hits_404' => 0, 'hits_redirect' => 0, 'new_captures' => 0);
            $output[] = array('date' => $date) + $counts;
        }
        if ($cacheKey !== null && function_exists('set_transient')) { set_transient($cacheKey, $output, self::TREND_DATA_CACHE_TTL_SECONDS); }
        return $output;
    }
}
