<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Specialized reads of the redirects table for callers that
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

    /** Number of rows fetched by each uncached regex or manual fallback page. */
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
     * the buffered mysqli result without building a second PHP row array.
     * The rows are raw (getRedirectsExport.sql columns); deciding where
     * each exported redirect points is Core's job
     * (ABJ_404_Solution_RedirectExportDestination).
     *
     * The query runs here, before any row is read, so a query that cannot run
     * (WP_Error) is distinguishable from one that ran and returned no redirects
     * (an empty generator).
     *
     * @return \Generator<int, array<string, mixed>>|WP_Error Database code and message when the query failed.
     */
    public function redirectsExportRows() {
        global $wpdb;

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsExport.sql");
        $query = $this->dbCore->doTableNameReplacements($query);

        try {
            // Read metadata on the same connection that streams the export.
            $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
            $probe = mysqli_query($wpdb->dbh, 'SELECT * FROM `' . $table . '` LIMIT 0');
            if ($probe instanceof \mysqli_result) {
                $columns = array_map(static function ($field) { return $field->name; }, mysqli_fetch_fields($probe));
                mysqli_free_result($probe);
                if (!in_array('query_mode', $columns, true)) {
                    $query = str_replace('r.query_mode', 'NULL AS query_mode', $query);
                }
            }
            $result = mysqli_query($wpdb->dbh, $query);
        } catch (\mysqli_sql_exception $error) {
            return new WP_Error($error->getCode(), $error->getMessage(), array('query' => $query));
        }
        if (!($result instanceof \mysqli_result)) {
            return new WP_Error(mysqli_errno($wpdb->dbh), mysqli_error($wpdb->dbh) ?? 'mysqli returned no message for the failed export query.', array('query' => $query));
        }
        if ($result->num_rows === 0) {
            mysqli_free_result($result);
            return $this->streamExportRows(null);
        }
        $rows = $this->streamExportRows($result);
        // Enter try/finally before handing ownership to a consumer that may
        // never iterate (for example, when the CSV file cannot be opened).
        // current() retains the first row for the consumer's foreach.
        $rows->current();
        return $rows;
    }

    /**
     * Yield each row of an open export result, freeing the result when the
     * stream ends or is discarded, even before the consumer reads a row:
     * redirectsExportRows() primes nonempty streams before returning them.
     * Empty results are freed there and represented here by null, keeping an
     * unstarted empty generator available for iteration. Cleanup also runs if
     * mysqli's default error mode (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
     * since PHP 8.1) threw mysqli_sql_exception on a dropped connection
     * mid-fetch.
     *
     * @param \mysqli_result|null $result
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamExportRows(?\mysqli_result $result): \Generator {
        if ($result === null) {
            return;
        }
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

    /**
     * Stream every enabled MANUAL redirect whose URL contains regex metacharacters.
     * Keyset pages bound the active MANUAL rows read per query; the metacharacter
     * filter is applied within each page so plain rows cannot force a full-table
     * scan before eligible rows are found.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function getManualRedirectsWithRegexMetachars(): iterable {
        $cached = ABJ_404_Solution_RedirectRegexCacheStore::manualSnapshotRows();
        if ($cached !== null) {
            foreach ($cached as $row) { yield $row; }
            return;
        }
        $version = ABJ_404_Solution_RedirectRegexCacheStore::manualSnapshotVersion();
        $remembered = array();
        $query = "select manual_page.*, {wp_posts}.id as wp_post_id,\n " .
                "    (INSTR(`url`, '*') > 0 " .
                "      OR INSTR(`url`, '[') > 0 " .
                "      OR INSTR(`url`, ']') > 0 " .
                "      OR INSTR(`url`, '|') > 0 " .
                "      OR INSTR(`url`, '^') > 0 " .
                "      OR INSTR(`url`, '\\\\') > 0 " .
                "      OR INSTR(`url`, '{') > 0 " .
                "      OR INSTR(`url`, '}') > 0) as _abj404_regex_metachars\n ";
        // allow-unbounded-select: keyset page; the loop below appends "limit REGEX_READ_BATCH_SIZE" to the inner select in $pageQuery (the LIMIT lands in a separate concatenated string than SELECT/FROM)
        $query .= "from (\n " .
                "select * from {wp_abj404_redirects}\n " .
                "where status = " . ABJ404_STATUS_MANUAL . " \n " .
                "     and disabled = 0";

        $afterId = 0;
        do {
            $pageQuery = $query . "\n " .
                "     and {wp_abj404_redirects}.id > " . (int)$afterId . " \n " .
                " order by {wp_abj404_redirects}.id asc \n " .
                " limit " . self::REGEX_READ_BATCH_SIZE . "\n " .
                ") as manual_page\n " .
                "left outer join {wp_posts} \n " .
                "  on manual_page.final_dest = {wp_posts}.id \n " .
                // final_dest is a post id only for a post redirect; a term id otherwise.
                "  and manual_page.type = " . ABJ404_TYPE_POST . " \n " .
                "order by manual_page.id asc";
            $results = $this->dbCore->queryAndGetResults($pageQuery);

            $this->assertRegexQuerySucceeded($results);

            /** @var array<int, array<string, mixed>> $rows */
            $rows = is_array($results['rows'] ?? null) ? $results['rows'] : array();
            if (empty($rows)) {
                break;
            }

            $nextAfterId = $this->greatestRedirectId($rows);
            if ($nextAfterId <= $afterId) {
                throw new ABJ_404_Solution_ViewQueryFailureException(
                    'Manual regex redirect keyset page did not advance past id ' . $afterId . '.',
                    array('operation' => 'manual_regex_lookup')
                );
            }

            foreach ($rows as $row) {
                if (empty($row['_abj404_regex_metachars'])) {
                    continue;
                }
                unset($row['_abj404_regex_metachars']);
                if ($version !== null) {
                    $remembered[] = $row;
                    if (count($remembered) > ABJ_404_Solution_RedirectRegexCacheStore::MAX_COUNT) {
                        $version = null;
                        $remembered = array();
                    }
                }
                yield $row;
            }
            $afterId = $nextAfterId;
        } while (count($rows) === self::REGEX_READ_BATCH_SIZE);
        // Reaching here proves exhaustion. A match, exception or discarded
        // generator never publishes its incomplete candidate prefix.
        if ($version !== null) {
            ABJ_404_Solution_RedirectRegexCacheStore::rememberManualSnapshot($remembered, $version);
        }
    }

    /** @param array<string,mixed> $results */
    private function assertRegexQuerySucceeded(array $results): void {
        $error = isset($results['last_error']) && is_string($results['last_error']) ? trim($results['last_error']) : '';
        if ($error !== '' || !array_key_exists('query_succeeded', $results) || $results['query_succeeded'] !== true
            || (array_key_exists('timed_out', $results) && $results['timed_out'] !== false)) {
            throw new ABJ_404_Solution_ViewQueryFailureException(
                $error !== '' ? $error : 'Manual regex lookup did not confirm query success.',
                array('operation' => 'manual_regex_lookup')
            );
        }
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
