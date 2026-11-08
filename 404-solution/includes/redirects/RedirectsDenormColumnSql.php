<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormSortKeyGatingTest and RedirectsDenormHitsRollupSourceTest

/**
 * Single source of truth for the per-redirect-type SQL that resolves the
 * dest_for_view + published_status denorm columns on wp_abj404_redirects.
 *
 * The exact same per-type display mapping (POST -> post_title / publish check,
 * CAT|TAG -> term name, HOME -> blogname, EXTERNAL -> destination URL,
 * 404-displayed -> render-time label, else broken/empty) is needed in two
 * places that run it as bulk SQL:
 *
 *   - the one-time post-upgrade backfill
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}, Step 3a), and
 *   - the real-time recompute on redirect create/edit and on post/term change
 *     ({@see ABJ_404_Solution_RedirectsDenormMaintenanceService}, Step 3c).
 *
 * Keeping the mapping here means the two paths can never drift: a new redirect
 * type or a changed publish rule is edited once and both the backfill and the
 * live maintenance pick it up. (The view's row-by-row PHP resolver,
 * {@see ABJ_404_Solution_RedirectsViewLiveResolver}, mirrors the same mapping in
 * a different execution model for the visible page only.)
 *
 * The builder emits literal-id-scoped statements (ids are cast to int before
 * interpolation, so no parameter binding is needed) and is pure: it touches no
 * database, holds no state, and resolves the WordPress core table names from
 * $wpdb the same way the backfill component did.
 */
class ABJ_404_Solution_RedirectsDenormColumnSql {

    /**
     * wp_options latch names recording that the one-time legacy-row backfill of a
     * narrow sort-key column has fully converged (no row still carries a NULL
     * key). The chunked drain
     * ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}) sets the
     * latch; the admin read path
     * ({@see ABJ_404_Solution_AdminViewReadCoordinator}) consults it before
     * ordering by the sort key. A freshly-upgraded table has the column added but
     * its legacy rows not yet drained (NULL keys), so ordering by the key would
     * bucket every legacy row as equal and fall to id order -- the wrong order.
     * Until the latch is set the read path keeps ordering by the wide source
     * column (correct order, filesort), then switches to the index-ordered key.
     * Co-located with the SQL that populates these columns so the two never drift.
     *
     * @var array<string, string>
     */
    const SORT_KEY_BACKFILL_LATCH_OPTIONS = array(
        'dest_sort_key' => 'abj404_dest_sort_key_backfilled',
        'url_sort_key'  => 'abj404_url_sort_key_backfilled',
    );

    /**
     * The wp_options latch name for a narrow sort-key column, or '' for a column
     * with no latch (so callers skip the read gate / never set a latch for it).
     *
     * @param string $targetColumn One of the SORT_KEY_BACKFILL_LATCH_OPTIONS keys.
     * @return string
     */
    public static function sortKeyBackfillLatchOption(string $targetColumn): string {
        return self::SORT_KEY_BACKFILL_LATCH_OPTIONS[$targetColumn] ?? '';
    }

    /**
     * The composite indexes that make an ORDER BY on each narrow sort-key column
     * index-ordered rather than a filesort. Both must exist for the key to be
     * safe to sort by: the (disabled, key, id) composite serves the unfiltered /
     * multi-status views, and the (status, disabled, key, id) composite serves a
     * single-status-filtered view. These names mirror createRedirectsTable.sql
     * exactly -- the DDL is the source of truth, this is the read-path's record of
     * which indexes back each key so readiness can verify they were actually
     * created (the column-add ALTER and the index-add ALTER can succeed or fail
     * independently of the drain that flips the backfill latch).
     *
     * @var array<string, array<int, string>>
     */
    const SORT_KEY_COMPOSITE_INDEXES = array(
        'dest_sort_key' => array('idx_disabled_dest_sort_id', 'idx_status_disabled_dest_sort_id'),
        'url_sort_key'  => array('idx_disabled_url_sort_id', 'idx_status_disabled_url_sort_id'),
    );

    /**
     * The composite index names that back an index-ordered sort on a narrow
     * sort-key column, or an empty array for a column with no registered
     * composites. Readiness requires every returned index to exist on the table.
     *
     * @param string $targetColumn One of the SORT_KEY_COMPOSITE_INDEXES keys.
     * @return array<int, string>
     */
    public static function sortKeyCompositeIndexNames(string $targetColumn): array {
        return self::SORT_KEY_COMPOSITE_INDEXES[$targetColumn] ?? array();
    }

    /**
     * The wp_options name recording how far the chunked legacy-row drain of a
     * narrow sort-key column has progressed: the highest redirect id drained so
     * far. The drain
     * ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}) advances
     * this as it walks the id space and resets it to 0 once the column converges
     * (it sets the latch instead). Reading cursor / MAX(id) gives the admin
     * "building the index" tooltip a cheap progress fraction WITHOUT a per-load
     * COUNT over the captured majority. Co-located with the latch option above so
     * the drain writer and the progress reader can never drift.
     *
     * @param string $targetColumn One of the SORT_KEY_BACKFILL_LATCH_OPTIONS keys.
     * @return string
     */
    public static function sortKeyBackfillCursorOption(string $targetColumn): string {
        return 'abj404_' . $targetColumn . '_backfill_cursor';
    }

    /**
     * Build compact, exact primary-key predicates for an explicit id set.
     *
     * @param array<int, int> $ids
     * @return array{aliased:string,bare:string}
     * @throws InvalidArgumentException
     */
    public static function buildIdClauses(array $ids): array {
        if (empty($ids)) {
            return array('aliased' => ' AND 1 = 0', 'bare' => ' AND 1 = 0');
        }
        $unique = array();
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Redirect ids must be positive integers.');
            }
            $unique[$id] = true;
        }
        $values = array_keys($unique);
        // Preserve exact integer ordering near PHP_INT_MAX before detecting ranges.
        sort($values, SORT_REGULAR);
        $aliased = self::idPredicates('r.id', $values);
        $bare = self::idPredicates('id', $values);
        return array(
            'aliased' => ' AND ' . $aliased,
            'bare' => ' AND ' . $bare,
        );
    }

    /** @param array<int,int> $values Sorted, unique, nonempty positive IDs. */
    private static function idPredicates(string $column, array $values): string {
        $first = $values[0];
        $last = $values[count($values) - 1];
        if ($first === $last) { return $column . ' = ' . $first; }
        if ($last - $first + 1 === count($values)) {
            return $column . ' BETWEEN ' . $first . ' AND ' . $last;
        }
        // An OR tree evaluates up to every equality for each selected row.
        return $column . ' IN (' . implode(',', $values) . ')';
    }

    /**
     * Build one bounded UPDATE that sets the denormalized redirect columns for
     * one explicit id chunk.
     *
     * Type-specific post/term lookups stay inside the CASE branches that use
     * them, so unrelated core rows do not multiply the update. The same
     * destination CASE expression is reused for dest_for_view and its narrow
     * sort key.
     *
     * @param array{
     *   redirects_table:string,
     *   id_clause:string,
     *   recompute:bool,
     *   include_dest_sort_key?:bool,
     *   include_url_sort_key?:bool,
     *   include_canonical_url?:bool,
     *   hits_source?:array{logs_hits_table:string,comparable_redirect_url:string}|null
     * } $options
     * @return array<int, string>
     *
     * The `recompute` flag controls the scope predicate: false drains only the
     *   dest_for_view IS NULL backfill sentinel; true recomputes every supplied id.
     * The sort-key flags emit assignments only when the corresponding column exists.
     * The column-add ALTER runs
     *   SEPARATELY from the dest_for_view column-add and can lag it; on such a
     *   schema-drifted table emitting an UPDATE against the missing column errors
     *   and aborts the chunk. Callers probe column presence and pass false to omit.
     */
    public static function buildDestPublishedStatements(array $options): array {
        $redirectsTable = $options['redirects_table'];
        $idClause = $options['id_clause'];
        $recompute = $options['recompute'];
        $hitsSource = $options['hits_source'] ?? null;
        // Keep the conversion safe even if the optimizer evaluates an
        // irrelevant branch expression: external and unknown redirects may
        // carry arbitrary final_dest text, including an oversized integer.
        $safeFinalDestId = self::safeUnsignedFinalDestIdExpression();
        $postsTable = self::coreTableName('posts');
        $termsTable = self::coreTableName('terms');
        $optionsTable = self::coreTableName('options');
        // Resolve post and term destinations only in the CASE branch that uses
        // them. Unconditional joins make every external redirect scan both
        // core tables, even though those branches cannot match.
        $destExpression = self::resolvedDestinationExpression($optionsTable, $postsTable, $termsTable, $safeFinalDestId);
        $publishedExpression = self::publishedStatusExpression($postsTable, $termsTable, $safeFinalDestId);
        $scope = ($recompute ? '1 = 1' : 'r.dest_for_view IS NULL') . $idClause;
        $sql = "UPDATE " . $redirectsTable . " r" .
            self::hitsJoin($hitsSource) .
            " SET " . self::resolvedColumnAssignments($destExpression, $options) .
            " r.published_status = " . $publishedExpression .
            " WHERE " . $scope;
        return array($sql);
    }

    private static function safeUnsignedFinalDestIdExpression(): string {
        $normalized = "TRIM(LEADING '0' FROM r.final_dest)";
        return "CASE WHEN " . $normalized . " REGEXP '^[0-9]{1,20}$'"
            . " AND (CHAR_LENGTH(" . $normalized . ") < 20"
            . " OR STRCMP(" . $normalized . ", '18446744073709551615') <= 0)"
            . " THEN CAST(" . $normalized . " AS UNSIGNED) ELSE 0 END";
    }

    private static function resolvedDestinationExpression(string $optionsTable, string $postsTable, string $termsTable, string $safeFinalDestId): string {
        $collation = ABJ_404_Solution_DatabaseCollationHelper::DEFAULT_UTF8MB4_COLLATION;
        $convert = static function (string $expression) use ($collation): string {
            return "CONVERT(" . $expression . " USING utf8mb4) COLLATE " . $collation;
        };
        $postTitle = "COALESCE((SELECT p.post_title FROM " . $postsTable . " p WHERE p.ID = " . $safeFinalDestId . " LIMIT 1), '')";
        $termName = "COALESCE((SELECT term.name FROM " . $termsTable . " term WHERE term.term_id = " . $safeFinalDestId . " LIMIT 1), '')";
        return "CASE"
            . " WHEN r.type = " . (int)ABJ404_TYPE_POST . " THEN " . $convert($postTitle)
            . " WHEN r.type IN (" . (int)ABJ404_TYPE_CAT . ", " . (int)ABJ404_TYPE_TAG . ") THEN " . $convert($termName)
            . " WHEN r.type = " . (int)ABJ404_TYPE_HOME . " THEN " . $convert("COALESCE((SELECT option_value FROM " . $optionsTable . " WHERE option_name = 'blogname' LIMIT 1), '')")
            . " WHEN r.type = " . (int)ABJ404_TYPE_EXTERNAL . " THEN " . $convert('r.final_dest')
            . " WHEN r.type = " . (int)ABJ404_TYPE_404_DISPLAYED . " THEN " . $convert("''")
            . " ELSE " . $convert("''") . " END";
    }

    private static function publishedStatusExpression(string $postsTable, string $termsTable, string $safeFinalDestId): string {
        $postPublished = "COALESCE((SELECT CASE WHEN LOWER(p.post_status) = 'publish' THEN 1 ELSE 0 END FROM " . $postsTable . " p WHERE p.ID = " . $safeFinalDestId . " LIMIT 1), 0)";
        $termPublished = "CASE WHEN EXISTS (SELECT 1 FROM " . $termsTable . " term WHERE term.term_id = " . $safeFinalDestId . " LIMIT 1) THEN 1 ELSE 0 END";
        return "CASE"
            . " WHEN r.type = " . (int)ABJ404_TYPE_POST . " THEN " . $postPublished
            . " WHEN r.type IN (" . (int)ABJ404_TYPE_CAT . ", " . (int)ABJ404_TYPE_TAG . ") THEN " . $termPublished
            . " WHEN r.type IN (" . (int)ABJ404_TYPE_HOME . ", " . (int)ABJ404_TYPE_EXTERNAL . ", " . (int)ABJ404_TYPE_404_DISPLAYED . ") THEN 1"
            . " ELSE 0 END";
    }

    /**
     * Publish all denormalized cache fields owned by the resolver in one row write.
     * @param array{
     *   include_dest_sort_key?:bool,
     *   include_url_sort_key?:bool,
     *   include_canonical_url?:bool,
     *   hits_source?:array{logs_hits_table:string,comparable_redirect_url:string}|null
     * } $options
     */
    private static function resolvedColumnAssignments(string $expression, array $options): string {
        $assignments = 'r.dest_for_view = ' . $expression . ',';
        if ($options['include_dest_sort_key'] ?? true) {
            // Derive directly from the source expression: joined UPDATEs do not
            // promise assignment order, so reading newly assigned dest_for_view
            // could copy its old value into the key.
            $assignments .= ' r.dest_sort_key = LEFT(' . $expression . ', 191),';
        }
        if ($options['include_url_sort_key'] ?? true) {
            $assignments .= ' r.url_sort_key = LEFT(r.url, 191),';
        }
        if (($options['hits_source'] ?? null) !== null) {
            $assignments .= ' r.logshits = COALESCE(h.logshits, 0),'
                . ' r.last_used = COALESCE(h.last_used, 0),';
        }
        if ($options['include_canonical_url'] ?? false) {
            $assignments .= " r.canonical_url = COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url))),";
        }
        return $assignments;
    }

    /** @param array{logs_hits_table:string,comparable_redirect_url:string}|null $hitsSource */
    private static function hitsJoin(?array $hitsSource): string {
        if ($hitsSource === null) {
            return '';
        }
        // A just-published rollup can retain empty-table cardinality until
        // InnoDB's background statistics refresh. Keep each URL probe indexed.
        return ' LEFT JOIN ' . $hitsSource['logs_hits_table']
            . ' h FORCE INDEX (requested_url) ON h.requested_url = ' . $hitsSource['comparable_redirect_url'];
    }

    /**
     * Build the logshits/last_used rollup UPDATE that copies the pre-aggregated
     * wp_abj404_logs_hits rollup (keyed by canonical requested_url) onto the
     * matching redirect rows.
     *
     * The single source of truth for the rollup SQL the Step 3a backfill, the
     * Step 3d nightly reconcile (both via
     * {@see ABJ_404_Solution_RedirectsDenormChunkResolver}), and the real-time
     * Step 3c write-back ({@see ABJ_404_Solution_RedirectsDenormMaintenanceService})
     * all run.
     *
     * It reads logs_hits, NOT raw logsv2 (report.md Finding 2): logs_hits is the
     * materialized COUNT/MAX(timestamp)-by-canonical-URL rollup, so this is a
     * scoped index-friendly join with no per-chunk `GROUP BY` over the whole log
     * table -- the repeated full-table aggregate that timed out on busy sites.
     * It is also the consistent source: the write-back already overwrites
     * r.logshits from logs_hits after every rollup, so any value the old
     * logsv2-GROUP BY produced was transient anyway. h.requested_url is the
     * canonical key, so the join matches on COALESCE(canonical_url, CONCAT/TRIM)
     * to stay correct while the redirects-side canonical_url backfill is still in
     * flight. The outer LEFT JOIN resets a no-hit URL to 0/0 rather than keeping
     * a stale count.
     *
     * Pure: the caller resolves and passes the logs_hits table name and the
     * already-int-sanitized id clause; this method touches no database.
     *
     * @param array{redirects_table: string, logs_hits_table: string, id_clause: string, comparable_redirect_url: string} $options
     * @return string
     */
    public static function buildHitsRollupFromRollupTableStatement(array $options): string {
        $redirectsTable = $options['redirects_table'];
        $logsHitsTable = $options['logs_hits_table'];
        $idClause = $options['id_clause'];
        $comparableRedirectUrl = $options['comparable_redirect_url'];

        return "UPDATE " . $redirectsTable . " r" .
            self::hitsJoin(array(
                'logs_hits_table' => $logsHitsTable,
                'comparable_redirect_url' => $comparableRedirectUrl,
            )) .
            " SET r.logshits = COALESCE(h.logshits, 0), r.last_used = COALESCE(h.last_used, 0)" .
            " WHERE 1 = 1" . $idClause;
    }

    /**
     * Resolve a WordPress core table name from $wpdb, falling back to
     * prefix + bare name when the property is absent (e.g. a minimal test wpdb
     * proxy). Avoids depending on $wpdb->tables being populated.
     *
     * @param string $bareName One of 'posts', 'terms', 'options'.
     * @return string
     */
    public static function coreTableName(string $bareName): string {
        global $wpdb;
        if (isset($wpdb->{$bareName}) && is_scalar($wpdb->{$bareName}) && (string)$wpdb->{$bareName} !== '') {
            return (string)$wpdb->{$bareName};
        }
        $prefix = (isset($wpdb->prefix) && is_scalar($wpdb->prefix)) ? (string)$wpdb->prefix : 'wp_';
        return $prefix . $bareName;
    }
}
