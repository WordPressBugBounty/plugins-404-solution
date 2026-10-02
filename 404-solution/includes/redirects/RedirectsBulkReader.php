<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Specialized, non-paginated reads of the redirects table for callers that
 * don't go through the staged admin-list pipeline.
 *
 * Owns:
 *   - redirectsExportRows: stream the redirects table's export rows one at a time
 *   - getRedirectsWithRegEx: regex redirects with a static request-scoped cache
 *   - getManualRedirectsWithRegexMetachars: manual redirects whose URL
 *     contains regex metacharacters (for the matcher's wildcard fallback)
 *   - getExtraDataToPermalinkSuggestions: post metadata for suggestion ids
 *
 * Extracted from ViewReadService in the i805 decomposition. The regex
 * cache is still owned by RedirectsRepository (its static cache holds the
 * per-request state); this reader just consults it.
 */
class ABJ_404_Solution_RedirectsBulkReader {

    /** Number of rows fetched by each keyset page once caching is unsafe. */
    const REGEX_READ_BATCH_SIZE = 250;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     * @param ABJ_404_Solution_Functions $f
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder,
        $f
    ) {
        $this->dbCore = $dbCore;
        $this->queryBuilder = $queryBuilder;
        $this->f = $f;
    }

    /**
     * Run the export query and hand its rows back one at a time, straight from
     * the mysqli result, so the row buffer stays bounded on large redirect
     * tables. The rows are raw (getRedirectsExport.sql columns); deciding where
     * each exported redirect points is Core's job
     * (ABJ_404_Solution_RedirectExportDestination).
     *
     * The query runs here, before any row is read, so a query that cannot run
     * (null) is distinguishable from one that ran and returned no redirects
     * (an empty generator).
     *
     * @return \Generator<int, array<string, mixed>>|null Null when the query failed.
     */
    public function redirectsExportRows(): ?\Generator {
        global $wpdb;

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsExport.sql");
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = mysqli_query($wpdb->dbh, $query);
        if (!($result instanceof \mysqli_result)) {
            return null;
        }
        return $this->streamExportRows($result);
    }

    /**
     * Yield each row of an open export result, freeing the result when the
     * stream ends, whether it finished, the consumer stopped early, or
     * mysqli's default error mode (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
     * since PHP 8.1) threw mysqli_sql_exception on a dropped connection
     * mid-fetch.
     *
     * @param \mysqli_result $result
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamExportRows(\mysqli_result $result): \Generator {
        try {
            while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
                yield $row;
            }
        } finally {
            mysqli_free_result($result);
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    public function getRedirectsWithRegEx(): iterable {
        $cached = ABJ_404_Solution_RedirectsRepository::getRegexRedirectsCache();
        $disabled = ABJ_404_Solution_RedirectsRepository::isRegexCacheDisabled();

        if ($cached !== null && !$disabled) {
            return $cached;
        }

        if ($disabled) {
            return $this->iterateAllRegexRedirectsInBatches();
        }

        $results = $this->queryBuilder->queryRegexRedirects(ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT + 1);

        if (count($results) <= ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT) {
            ABJ_404_Solution_RedirectsRepository::setRegexRedirectsCache($results);
        } else {
            ABJ_404_Solution_RedirectsRepository::setRegexCacheDisabled(true);
            return $this->iterateAllRegexRedirectsInBatches($results);
        }

        return $results;
    }

    /**
     * Stream a complete regex redirect read without allowing either a SQL
     * result set or the PHP row collection to grow without bound. The optional
     * leading rows are the cache-threshold probe and are reused so the common
     * 51+ path does not reread them.
     *
     * @param array<int, array<string, mixed>> $leadingRows
     * @return iterable<int, array<string, mixed>>
     */
    private function iterateAllRegexRedirectsInBatches(array $leadingRows = array()): iterable {
        // DESIGN-AUDIT-OK(2026-08-21, owner): A total-work cap would silently make later valid regex rules unreachable, reproducing Troy's 51-rule defect.
        // The admin-owned finite table is streamed in 250-row pages, holds bounded memory, and stops as soon as a caller finds a match.
        $afterId = $this->greatestRedirectId($leadingRows);
        foreach ($leadingRows as $row) {
            yield $row;
        }

        do {
            $page = $this->queryBuilder->queryRegexRedirectsPage(array(
                'after_id' => $afterId,
                'limit' => self::REGEX_READ_BATCH_SIZE,
            ));
            if (empty($page)) {
                break;
            }

            $nextAfterId = $this->greatestRedirectId($page);
            if ($nextAfterId <= $afterId) {
                throw new UnexpectedValueException(
                    'Regex redirect keyset page did not advance past id ' . $afterId . '.'
                );
            }

            foreach ($page as $row) {
                yield $row;
            }
            $afterId = $nextAfterId;
        } while (count($page) === self::REGEX_READ_BATCH_SIZE);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function greatestRedirectId(array $rows): int {
        $greatestId = 0;
        foreach ($rows as $row) {
            $id = isset($row['id']) && is_scalar($row['id']) ? (int)$row['id'] : 0;
            $greatestId = max($greatestId, $id);
        }
        return $greatestId;
    }

    /** @return array<int, array<string, mixed>> */
    // DESIGN-AUDIT-OK(2026-06-19, owner): status=MANUAL AND disabled=0 seeks via
    //   idx_status_disabled to the few hand-made MANUAL rows first, so INSTR() runs only
    //   over that bounded subset, not a full table scan. A LIMIT would drop valid manual
    //   regex redirects (changes results), so no cap. Reviewed + accepted 2026-06-18.
    public function getManualRedirectsWithRegexMetachars(): array {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n " .
                // final_dest is a post id only for a post redirect; a term id otherwise.
                "    and {wp_abj404_redirects}.type = " . ABJ404_TYPE_POST . " \n ";

        $query .= "where status = " . ABJ404_STATUS_MANUAL . " \n " .
                "     and disabled = 0 \n " .
                "     and (INSTR(`url`, '*') > 0 " .
                "       OR INSTR(`url`, '[') > 0 " .
                "       OR INSTR(`url`, ']') > 0 " .
                "       OR INSTR(`url`, '|') > 0 " .
                "       OR INSTR(`url`, '^') > 0 " .
                "       OR INSTR(`url`, '\\\\') > 0 " .
                "       OR INSTR(`url`, '{') > 0 " .
                "       OR INSTR(`url`, '}') > 0)";
        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    public function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(', ', $postIDs);

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getAdditionalPostData.sql");
        // Expand table names and constants on the TEMPLATE, then bind the ids last (no token pass
        // may run over bound data).
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->replaceKnownConstants($query);
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);

        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, mixed> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }
}
