<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin view query construction for the redirect/captured lists.
 *
 * Builds the SQL for the admin redirect/captured lists: high-impact captured
 * count, regex redirects, optimized count, and the single-table read/count
 * against wp_abj404_redirects (Denorm Step 3b). The staged view_done read path
 * that used to live here was removed when the denorm chain dropped the
 * wp_abj404_view_done table (Step 3e-D / i467); admin reads now serve straight
 * off the redirects row.
 *
 * The single-table read and count are SqlFragments: the search text and the
 * post-type slugs it matches are BOUND (`%s` placeholders plus params), never
 * spliced into the SQL text, because the query executor rewrites `{wp_...}` tokens
 * across the whole statement before it binds `query_params`.
 *
 * @phpstan-import-type SqlFragment from ABJ_404_Solution_DatabaseQueryBuilderInterface
 */
class ABJ_404_Solution_ViewQueryBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /** @var ABJ_404_Solution_ViewSortResolver */
    private $sortResolver;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
        $this->policy = new ABJ_404_Solution_ViewQueryPolicy();
        $this->sortResolver = new ABJ_404_Solution_ViewSortResolver($this->policy);
    }

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string {
        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
        $logsHitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $canonicalRedirectUrl = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $comparableRedirectUrl = $this->dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $canonicalRedirectUrl,
            array('table' => $logsHitsTable, 'column' => 'requested_url')
        );
        // allow-unbounded-select: COUNT(*) aggregate; returns a single row
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON h.requested_url = " . $comparableRedirectUrl . "
               AND BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * @param int|null $limit
     * @return array<int, array<string, mixed>>
     */
    public function queryRegexRedirects(?int $limit = null) {
        return $this->queryRegexRedirectsPage(array(
            'after_id' => 0,
            'limit' => $limit,
        ));
    }

    /**
     * Read one ascending-id page of active regex redirects.
     *
     * @param array{after_id: int, limit: int|null} $page
     * @return array<int, array<string, mixed>>
     */
    public function queryRegexRedirectsPage(array $page): array {
        $afterId = max(0, (int)($page['after_id'] ?? 0));
        $limit = $page['limit'] ?? null;
        $query = "select {wp_abj404_redirects}.*, {wp_posts}.id as wp_post_id\n "
            . "from {wp_abj404_redirects}\n "
            . "  LEFT OUTER JOIN {wp_posts} \n "
            . "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n "
            // final_dest is a post id only for a post redirect; a term id otherwise.
            . "    and {wp_abj404_redirects}.type = " . ABJ404_TYPE_POST . " \n "
            . "where status in (" . ABJ404_STATUS_REGEX . ") \n "
            . "     and disabled = 0\n"
            . "     and {wp_abj404_redirects}.id > " . $afterId . "\n"
            . "order by {wp_abj404_redirects}.id ASC";
        if ($limit !== null) {
            $query .= "\nlimit " . max(1, intval($limit));
        }

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = $this->normalizeReadResult($results, 'regex redirect lookup', true);
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = $this->policy->resolveTrashValue($tableOptions);
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, 'wp_abj404_redirects.'); // allow-prefix-literal: SQL alias bound by FROM clause

        $query = "SELECT COUNT(*) AS count\n"
            . "FROM {wp_abj404_redirects} wp_abj404_redirects\n" // allow-prefix-literal: second token is the SQL alias name, not a table reference
            . "WHERE 1 and status IN (" . $statusTypes . ") AND disabled = " . intval($trashValue) . "\n"
            . $scoreRangeClause;

        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * Execute the single-table redirects read for one page (Denorm Step 3b).
     *
     * Serves rows straight off wp_abj404_redirects, where every sortable column
     * (url, status, type, code, timestamp, score, logshits, last_used,
     * dest_for_view) is a real column -> a single-table filesort over at most one
     * page of rows, no temp-table materialize, no join. The four derived columns
     * are refreshed live per visible row by RedirectsViewLiveResolver after this
     * read; this method only fetches the ordered/filtered page.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent Whether the four denorm columns exist on the
     *   redirects table (schema-drift tolerance: false selects base columns only
     *   and the live resolver fills the derived values).
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewQueryFailureException When the database adapter reports a failed read.
     */
    public function readRedirectsSingleTable(string $sub, array $tableOptions, bool $derivedPresent = true): array {
        $query = $this->buildRedirectsSingleTableReadQuery($sub, $tableOptions, $derivedPresent);
        if (!empty($tableOptions['_abj404_allow_degraded_read'])) {
            $fallbackOptions = array_replace($tableOptions, array(
                'paged' => 1, 'perpage' => min(ABJ_404_Solution_AdminTableReadRepository::PAGE_LIMIT,
                    ABJ_404_Solution_ExactInteger::readOr($tableOptions['perpage'] ?? null, 1, 25)),
                'orderby' => 'id', 'order' => 'DESC', '_abj404_bounded_first_page' => true,
            ));
            return abj_service('admin_table_reader')->read(array(
                'statement' => $query,
                'fallback' => $this->buildRedirectsSingleTableReadQuery($sub, $fallbackOptions, $derivedPresent),
                'subpage' => $sub, 'tableOptions' => $tableOptions,
                'sortPending' => $this->sortResolver->wideColumnSortPendingBackfill($tableOptions),
            ));
        }
        $result = $this->dbCore->queryAndGetResults(
            $query['sql'],
            array_merge($this->resolveReadTimeoutOptions($tableOptions), array('query_params' => $query['params']))
        );
        $rows = $this->normalizeReadResult($result, 'redirect row query');
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the queryAndGetResults options for the single-table read/count from
     * the optional per-request timeout, with a level-9-safe numeric guard.
     *
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    private function resolveReadTimeoutOptions(array $tableOptions): array {
        $raw = $tableOptions['_abj404_query_timeout'] ?? null;
        if (!is_numeric($raw)) {
            return array();
        }
        $timeout = (int)$raw;
        return $timeout > 0 ? array('timeout' => $timeout) : array();
    }

    /**
     * Execute the single-table filtered count against wp_abj404_redirects.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return int
     * @throws ABJ_404_Solution_ViewQueryFailureException When the aggregate is unavailable or malformed.
     */
    public function countRedirectsSingleTable(string $sub, array $tableOptions, bool $derivedPresent = true): int {
        $query = $this->buildRedirectsSingleTableCountQuery($sub, $tableOptions, $derivedPresent);
        if (!empty($tableOptions['_abj404_allow_degraded_read'])) {
            return abj_service('admin_table_reader')->count(array(
                'statement' => $query, 'subpage' => $sub, 'tableOptions' => $tableOptions,
                'operation' => 'getRedirectsForViewCount',
            ));
        }
        $result = $this->dbCore->queryAndGetResults(
            $query['sql'],
            array_merge($this->resolveReadTimeoutOptions($tableOptions), array('query_params' => $query['params']))
        );
        $rows = $this->normalizeReadResult($result, 'filtered redirect count query');
        if (empty($rows) || !is_array($rows[0])) {
            throw new ABJ_404_Solution_ViewQueryFailureException(
                'Filtered redirect count query returned no aggregate row.'
            );
        }
        $raw = $rows[0]['cnt'] ?? reset($rows[0]);
        if (!is_numeric($raw)) {
            throw new ABJ_404_Solution_ViewQueryFailureException(
                'Filtered redirect count query returned a nonnumeric aggregate.'
            );
        }
        return intval($raw);
    }

    /**
     * Keep failed or malformed read envelopes from becoming an empty result.
     * Empty rows are valid only after the executor has explicitly reported a
     * successful query; callers then decide whether an empty page/count is
     * meaningful for their operation.
     *
     * @param mixed $result
     * @param string $operation
     * @return array<int, mixed>
     * @param bool $includeDiagnostics Preserve the regex reader's structured
     *   operation marker; single-table callers let their coordinator collect
     *   the full view diagnostics envelope.
     * @throws ABJ_404_Solution_ViewQueryFailureException
     */
    private function normalizeReadResult($result, string $operation, bool $includeDiagnostics = false): array {
        // The validator owns what makes a read envelope trustworthy; this
        // method owns only how a refusal is delivered here: a typed exception.
        $confirmed = ABJ_404_Solution_DatabaseReadResultValidator::confirm($result, ucfirst($operation));
        if ($confirmed['last_error'] !== '') {
            // Keep the existing machine-readable operation slug stable for
            // callers/log consumers while allowing human-readable failure text.
            throw new ABJ_404_Solution_ViewQueryFailureException(
                $confirmed['last_error'],
                $includeDiagnostics ? array('operation' => str_replace(' ', '_', $operation)) : array()
            );
        }
        return $confirmed['rows'];
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return SqlFragment The statement text and the values to bind, in placeholder order.
     */
    public function buildRedirectsSingleTableReadQuery(string $sub, array $tableOptions, bool $derivedPresent = true): array {
        $effectiveSort = $this->sortResolver->resolveEffectiveSort($tableOptions, $derivedPresent);
        $orderBy = $effectiveSort['orderby'];
        $order = $effectiveSort['order'];

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        // The derived columns are projected only when they exist, purely so the
        // live resolver can dedupe its write-back against the stored values; the
        // rendered values come from the live resolution either way. On a
        // schema-drifted table without them, the base-column projection still
        // renders a complete page.
        $derivedProjection = $derivedPresent
            ? ",\n       dest_for_view, published_status, logshits, last_used" : "";

        // Tie-break on the PK id in the sort direction (not on url): url is
        // varchar(2048) and only prefix-indexable, so an `ORDER BY <col>, url`
        // can never be index-ordered and always filesorts. With `id <dir>` a
        // single ascending composite index (disabled, <col>, id) serves both
        // ASC and DESC scans, so the Hits / Last Used sorts stop after one page
        // instead of sorting the whole active-redirect set. See
        // RedirectsDerivedSortExplainPlanTest. id is unique, so the ordering is
        // still fully deterministic for pagination.
        // The Status sort is the exception: its ties break on `timestamp, id`
        // (see statusTieBreak()), so the id never rides on an index's implicit
        // primary-key suffix.
        $where = $this->buildSingleTableWhere($sub, $tableOptions, $derivedPresent);
        // LIMIT inside the derived table prevents optimizer merging: even a
        // non-sargable search can examine only this many primary-key candidates.
        $bounded = !empty($tableOptions['_abj404_bounded_first_page']);
        $source = $bounded
            ? $this->boundedRedirectsSource($sub, $tableOptions)
            : '{wp_abj404_redirects}';
        return array(
            'sql' => 'SELECT ' . ($bounded ? '/* abj404 bounded admin page */ ' : '')
                . "id, url, status, type, final_dest, code, timestamp, engine, score"
                . $derivedProjection . "\n"
                . 'FROM ' . $source . "\n"
                . $where['sql']
                . "ORDER BY " . $orderBy . " " . $order . ", " . $this->tieBreak($orderBy, $order) . "\n"
                . "LIMIT " . $limitStart . ", " . $perpage,
            'params' => $where['params'],
        );
    }

    /**
     * The ORDER BY terms after the sorted column: the primary-key id in the sort
     * direction, preceded by `timestamp` for the Status sort.
     *
     * Why Status differs: `status IN (...) ORDER BY status <dir>, id <dir> LIMIT n`
     * is served by `status` / `idx_status_disabled`, which carry `id` only as
     * InnoDB's implicit primary-key suffix. MySQL 8.0.35 up to 8.4.10 and 9.x up
     * to 9.7.1 (bugs 117598 and 119198, never fixed in 8.0) drop the sort for
     * that shape, scan the multi-range reverse and so return the ids ascending
     * inside a descending page. Adding `timestamp` makes the index that serves
     * the sort `idx_status_disabled_timestamp_id`, which names every key part
     * explicitly, so no engine can mis-order it; the user-visible rule is
     * newest first inside a status group. RedirectsViewSortOrderEveryIndexTest
     * forces every index of the table onto every sort to hold this.
     *
     * @param string $orderBy The effective sorted column.
     * @param string $order 'ASC' or 'DESC'.
     * @return string SQL terms, no leading comma.
     */
    private function tieBreak(string $orderBy, string $order): string {
        return $orderBy === 'status'
            ? 'timestamp ' . $order . ', id ' . $order
            : 'id ' . $order;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return SqlFragment The statement text and the values to bind, in placeholder order.
     */
    public function buildRedirectsSingleTableCountQuery(string $sub, array $tableOptions, bool $derivedPresent = true): array {
        $where = $this->buildSingleTableWhere($sub, $tableOptions, $derivedPresent);
        return array(
            'sql' => "SELECT COUNT(*) AS cnt\n"
                . "FROM {wp_abj404_redirects}\n"
                . $where['sql'],
            'params' => $where['params'],
        );
    }

    /** @param array<string, mixed> $options A bounded candidate source, never a full-source filesort. */
    private function boundedRedirectsSource(string $sub, array $options): string {
        $index = $options['_abj404_bounded_status_index'] ?? '';
        if (!in_array($index, array('idx_status_disabled_timestamp_id', 'idx_status_disabled', 'status'), true)) {
            return '(SELECT * FROM {wp_abj404_redirects} FORCE INDEX (PRIMARY) ORDER BY id DESC LIMIT '
                . ABJ_404_Solution_AdminTableReadRepository::CANDIDATE_LIMIT . ') recent_redirects';
        }
        $statuses = explode(',', $this->policy->resolveStatusTypeList($sub, $options));
        $limit = max(1, intdiv(ABJ_404_Solution_AdminTableReadRepository::CANDIDATE_LIMIT, max(1, count($statuses))));
        $branches = array();
        foreach ($statuses as $status) {
            // allow-unbounded-select: every branch ends with " LIMIT $limit" (CANDIDATE_LIMIT split across the statuses); the LIMIT lands in a separate concatenated string than SELECT/FROM
            $branches[] = 'SELECT * FROM {wp_abj404_redirects} FORCE INDEX (`' . $index . '`) WHERE status = ' . (int)$status
                . ($index === 'status' ? '' : ' AND disabled = ' . $this->policy->resolveTrashValue($options))
                . ($index === 'idx_status_disabled_timestamp_id' ? ' ORDER BY timestamp DESC, id DESC' : '')
                . ' LIMIT ' . $limit;
        }
        $union = count($branches) === 1 ? $branches[0] : '(' . implode(') UNION ALL (', $branches) . ')';
        return '(' . $union . ') recent_redirects';
    }

    /**
     * The shared WHERE body for the single-table read and count: status filter,
     * trash (disabled) filter, score range, and the dest_for_view-aware
     * filterText search. Identical between read and count so a filtered count
     * always equals the unpaginated row set (i457 invariant). The filterText
     * destination-title match drops to url/label matching when the dest_for_view
     * column is absent (schema-drift tolerance).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return SqlFragment
     */
    private function buildSingleTableWhere(string $sub, array $tableOptions, bool $derivedPresent = true): array {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions, true, $derivedPresent);

        return array(
            'sql' => "WHERE status IN (" . $statusTypes . ")\n"
                . " " . $trashClause . "\n"
                . " " . $scoreRangeClause . "\n"
                . " " . $filterTextClause['sql'] . "\n",
            'params' => $filterTextClause['params'],
        );
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveStatusTypeList(string $sub, array $tableOptions): string {
        return $this->policy->resolveStatusTypeList($sub, $tableOptions);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveOrderByColumn(array $tableOptions): string {
        return $this->policy->resolveOrderByColumn($tableOptions);
    }

}
