<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides which ORDER BY the single-table redirects read uses.
 *
 * The admin lists sort on columns that are not all equally cheap: url and
 * destination are varchar(2048), prefix-indexable only, so ordering by them
 * directly always filesorts. The table therefore carries narrow sort-key
 * columns (url_sort_key, dest_sort_key) that become usable only after their
 * composite indexes exist and their legacy-row drain has converged, and the
 * four derived columns (dest_for_view, published_status, logshits, last_used)
 * may be absent on a schema-drifted install. This class turns the requested
 * sort plus those readiness flags into the one (column, direction) pair that
 * can be served without a wide-column filesort, and answers whether the
 * requested sort had to be substituted so the column header can say so.
 *
 * It only chooses. ABJ_404_Solution_ViewQueryBuilder assembles the statement
 * around the answer, and the allow-listing of sortable columns and the
 * direction stay with ABJ_404_Solution_ViewQueryPolicy.
 */
class ABJ_404_Solution_ViewSortResolver {

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /**
     * @param ABJ_404_Solution_ViewQueryPolicy $policy Allow-lists the sortable
     *   columns and the direction; consulted for everything that is not a
     *   narrow sort-key substitution.
     */
    public function __construct(ABJ_404_Solution_ViewQueryPolicy $policy) {
        $this->policy = $policy;
    }

    /**
     * Effective (orderby column, direction) for the single-table read ORDER BY.
     *
     * On EITHER tab, a URL or Destination sort that cannot be served index-ordered
     * right now (its narrow sort key is not ready: column missing, composite index
     * missing, or the legacy-row drain not yet converged -- see
     * RedirectsDenormSchemaReadiness::sortKeyReadyForColumn) would force a filesort over
     * the wide source column (varchar(2048), prefix-only). On a large table that
     * scan can exceed a shared host's max_statement_time, the server kills the
     * query, and the tab is stuck on its loading placeholder every load. Until the
     * sort key is ready we therefore serve the always-indexed safe default
     * (timestamp DESC, "newest first") instead. This is display-only: the user's
     * saved sort preference is left untouched and resumes automatically once the
     * sort key is ready -- and the column header is rendered non-sortable with a
     * progress tooltip meanwhile (captured tab: View_CapturedURLsTable; Page
     * Redirects tab: ABJ_404_Solution_AdminTableColumnHeaders).
     *
     * Applied to BOTH tabs (the literal "no wide-column filesort" rule): the
     * Page Redirects status filter does not make the wide-url filesort safe at
     * scale, and the readiness predicate self-heals so the real sort resumes the
     * moment the key is index-ordered.
     *
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent
     * @return array{orderby: string, order: string}
     */
    public function resolveEffectiveSort(array $tableOptions, bool $derivedPresent): array {
        if ($this->wideColumnSortPendingBackfill($tableOptions)) {
            return array('orderby' => 'timestamp', 'order' => 'DESC');
        }
        return array(
            'orderby' => $this->resolveSingleTableOrderByColumn($tableOptions, $derivedPresent),
            'order' => $this->policy->resolveOrderDirection($tableOptions),
        );
    }

    /**
     * Whether the requested sort targets a narrow sort-key-backed column
     * (url -> url_sort_key, dest -> dest_sort_key) that cannot be served
     * index-ordered yet. The coordinator sets the matching
     * _abj404_*_sort_key_present flag true only when the column exists AND its
     * composite indexes exist AND its drain latch is set
     * (RedirectsDenormSchemaReadiness::sortKeyReadyForColumn); a falsey flag means the
     * only available ordering is a wide-column filesort. Sorts on real
     * always-populated columns (logshits, last_used, score, code, type, status,
     * timestamp) are never pending and are not substituted.
     *
     * @param array<string, mixed> $tableOptions The request's `orderby` is read
     *   here and lowercased, so callers pass the options untouched.
     * @return bool
     */
    public function wideColumnSortPendingBackfill(array $tableOptions): bool {
        $rawOrderBy = strtolower(is_string($tableOptions['orderby'] ?? null) ? $tableOptions['orderby'] : '');
        if ($rawOrderBy === 'url') {
            return empty($tableOptions['_abj404_url_sort_key_present']);
        }
        if ($rawOrderBy === 'dest' || $rawOrderBy === 'final_dest') {
            return empty($tableOptions['_abj404_dest_sort_key_present']);
        }
        return false;
    }

    /**
     * Resolve the ORDER BY column for the single-table read.
     *
     * A sort on a derived column (logshits / last_used / dest) uses the real
     * column whenever the four denorm columns exist. The only fallback is
     * schema-drift tolerance: when the columns are absent entirely (the column-add
     * ALTER never completed) a derived sort would reference a missing column, so
     * it falls back to the always-present native url column.
     *
     * No backfill-completion flag is consulted. Each derived column degrades
     * gracefully for not-yet-backfilled rows by construction, so ordering on it is
     * always meaningful and self-heals as rows are resolved:
     *   - logshits is NOT NULL and defaults to 0, so an un-backfilled row simply
     *     sorts as 0 hits and rises into place once the rollup is written;
     *   - last_used is NULL for no-hit rows, which sort last;
     *   - the dest_for_view ordering groups NULL/empty destinations last via its
     *     CASE expression (see ViewQueryPolicy::resolveOrderByColumn).
     *
     * The previous implementation gated these sorts on the obsolete denorm
     * backfill-complete option and fell back to url order until it flipped. That
     * option was flipped only when no row had dest_for_view NULL, a condition a
     * live 404 site never reaches: every newly captured 404 is inserted with
     * dest_for_view NULL, so the option stayed false forever and the Hits / Last
     * Used / Destination columns were permanently sorted by url instead of by
     * their own values (the rendered Hits column looked random).
     *
     * @param array<string, mixed> $tableOptions
     * @param bool $derivedPresent Whether the four denorm columns exist on the
     *   table. False (schema drift) is the only case that forces the url fallback.
     * @return string
     */
    private function resolveSingleTableOrderByColumn(array $tableOptions, bool $derivedPresent = true): string {
        $rawOrderByValue = $tableOptions['orderby'] ?? '';
        $rawOrderBy = strtolower(is_string($rawOrderByValue) ? $rawOrderByValue : '');
        $derivedSorts = array('logshits', 'last_used', 'dest', 'final_dest');
        if (in_array($rawOrderBy, $derivedSorts, true) && !$derivedPresent) {
            return 'url';
        }
        // Destination sort: ORDER BY the narrow indexable dest_sort_key
        // (uniform direction -> (disabled, dest_sort_key, id) /
        // (status, disabled, dest_sort_key, id) serve it without a filesort) when
        // the column exists. Blank/NULL destinations then sort naturally (first
        // ascending, last descending) rather than the old always-last CASE, which
        // was the very thing that forced a filesort (computed expression over a
        // varchar(2048) prefix-only column). When the column is absent (an install
        // mid-upgrade), fall back to the CASE-on-dest_for_view filesort so the
        // sort still works -- just unindexed -- until the column-add completes.
        if (($rawOrderBy === 'dest' || $rawOrderBy === 'final_dest')
                && !empty($tableOptions['_abj404_dest_sort_key_present'])) {
            return 'dest_sort_key';
        }
        // URL sort (incl. the Page Redirects default): ORDER BY the narrow
        // indexable url_sort_key so (disabled, url_sort_key, id) /
        // (status, disabled, url_sort_key, id) serve it without a filesort. url is
        // varchar(2048), prefix-only, so ORDER BY url itself always filesorts even
        // when every value is short (MySQL will not order by a prefix index). When
        // the column is absent (an install mid-upgrade) fall back to raw url so the
        // sort still works -- unindexed -- until the column-add completes. URLs
        // longer than 191 chars sharing a 191-char prefix tie-break by id.
        if ($rawOrderBy === 'url' && !empty($tableOptions['_abj404_url_sort_key_present'])) {
            return 'url_sort_key';
        }
        return $this->policy->resolveOrderByColumn($tableOptions);
    }
}
