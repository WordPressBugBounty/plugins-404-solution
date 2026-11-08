<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side queries over the log tables and rollup: row population from the
 * hits rollup, distinct recent URL extraction, exact / fuzzy URL lookup,
 * paged list reads, and daily activity trends for the dashboard.
 */
interface ABJ_404_Solution_LogReadQueryInterface {

    /**
     * Default size of the recency window scanned to find distinct logged URLs.
     * Mirrored as ABJ_404_Solution_LogsReadQueries::DEFAULT_RECENT_LOG_WINDOW so
     * interface defaults do not depend on the implementation being loaded.
     */
    const DEFAULT_DISTINCT_RECENT_LOG_WINDOW = 5000;

    /** Default cap on distinct URLs returned by getDistinctLoggedUrls(). */
    const DEFAULT_DISTINCT_URL_CAP = 500;

    /**
     * Populate logshits / logsid / last_used on each row from the pre-aggregated
     * wp_abj404_logs_hits rollup.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function populateLogsData($rows);

    /**
     * Distinct recently-requested URLs from the 404 log table.
     *
     * @param int $recentLogWindow Max rows scanned from logsv2 before deduplication.
     * @param int $distinctUrlCap  Max distinct URLs returned.
     * @return array<int, string>
     */
    public function getDistinctLoggedUrls(
        int $recentLogWindow = self::DEFAULT_DISTINCT_RECENT_LOG_WINDOW,
        int $distinctUrlCap = self::DEFAULT_DISTINCT_URL_CAP
    ): array;

    /**
     * @param string $specificURL
     * @param array<string, mixed> $tableOptions Internal timeout options.
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewQueryFailureException When the read is unavailable.
     */
    public function getLogsIDandURL($specificURL = '', array $tableOptions = array());

    /**
     * @param string $specificURL
     * @param string|int $limitResults
     * @param array<string, mixed> $tableOptions Internal timeout options.
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewQueryFailureException When the read is unavailable.
     */
    public function getLogsIDandURLLike($specificURL, $limitResults, array $tableOptions = array());

    /**
     * @param array<string, mixed> $tableOptions orderby, paged, perpage, etc.
     * @return array<int, array<string, mixed>>
     */
    public function getLogRecords($tableOptions);

    /** Whether the latest getLogRecords() call returned a compatibility fallback after a failed read. */
    public function lastLogRecordsReadWasIncomplete(): bool;

    /**
     * @param int $days Number of days (default 30, clamped to 1-90)
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days = 30): array;
}
