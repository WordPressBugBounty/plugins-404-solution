<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormHitsRollupSourceTest

/**
 * Resolves the four denormalized derived columns (logshits, last_used,
 * dest_for_view, published_status) on wp_abj404_redirects for an explicit chunk
 * of redirect ids.
 *
 * Shared collaborator for the two bulk-write paths that populate those columns:
 *
 *   - the one-time post-upgrade backfill
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}, Step 3a), and
 *   - the nightly full reconcile / Tier-3 backstop
 *     ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormReconcile}, Step 3d).
 *
 * Both feed it a chunk of ids and differ only in the $recompute flag they pass
 * through to {@see ABJ_404_Solution_RedirectsDenormColumnSql}: the backfill
 * isolates not-yet-resolved rows by the dest_for_view IS NULL sentinel
 * ($recompute = false); the reconcile recomputes every row regardless of its
 * current value ($recompute = true) so it catches even raw-SQL writers that
 * bypassed the Step 3c real-time hooks. Centralizing the per-chunk write here
 * means a new redirect type, a changed publish rule, or a tweaked rollup join is
 * edited once and both paths pick it up; they can never drift.
 *
 * Stateless static helper, exactly like its sibling
 * {@see ABJ_404_Solution_RedirectsDenormColumnSql}: callers pass the
 * DatabaseCore + logger per call. Chunk writes run in the DatabaseCore transaction
 * executor, which classifies and logs storage failures. The resolver returns
 * false on failure, preserving retry eligibility on transactional and
 * nontransactional tables, and leaving the reconcile cursor unacknowledged.
 */
class ABJ_404_Solution_RedirectsDenormChunkResolver {

    /**
     * Populate the four derived columns for an explicit list of redirect ids.
     *
     * dest_for_view + published_status are resolved by one per-type projection
     * CASE, mirroring staged-build stages S4-S8, with a catch-all branch that
     * drains any unmatched row.
     * logshits + last_used are rolled up from the wp_abj404_logs_hits rollup by
     * canonical URL (NOT raw logsv2: report.md Finding 2) when that rollup table
     * exists; when it is absent the columns keep their 0 / NULL defaults so a
     * degraded site still resolves dest_for_view.
     *
     * Idempotent: re-running on the same ids recomputes the same values from
     * the same sources. Each row publishes its available canonical, sort and
     * hit fields with the completion sentinel; transactional engines also
     * roll back the whole failed chunk.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore         The DAO used for every write.
     * @param ABJ_404_Solution_Logging      $logger         Warns on a stopping write error.
     * @param string                        $redirectsTable Fully-qualified redirects table name.
     * @param array<int, int>               $ids            Redirect ids in this chunk.
     * @param bool                          $recompute      false: guard on the
     *   dest_for_view IS NULL sentinel (backfill); true: recompute every supplied
     *   id, including populated rows of unknown types (full reconcile).
     * @return bool True if the chunk resolved cleanly, false if a write errored.
     */
    public static function resolveChunk(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger,
        string $redirectsTable,
        array $ids,
        bool $recompute
    ): bool {
        // Compatibility adapter: callers that need multiple chunks use forPass().
        return (self::forPass($dbCore, $logger, $redirectsTable, $recompute))($ids);
    }

    /**
     * Create a fresh metadata context for one bounded pass; a later request
     * must call this again so schema changes are rediscovered.
     * @return callable(array<int,int>):bool
     */
    public static function forPass(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        string $redirectsTable,
        bool $recompute
    ): callable {
        $metadata = null;
        return static function (array $ids) use (&$metadata, $dbCore, $logger, $redirectsTable, $recompute): bool {
            if (empty($ids)) {
                return true;
            }
            if ($metadata === null) {
                $metadata = self::passMetadata($dbCore, $logger, $redirectsTable);
            }
            return $metadata === null ? false : self::publishChunk(
                $dbCore,
                $logger,
                $redirectsTable,
                array('ids' => $ids, 'recompute' => $recompute, 'metadata' => $metadata)
            );
        };
    }

    /** @return array{columns:array<string,bool>,hits_source:array{logs_hits_table:string,comparable_redirect_url:string}|null}|null */
    private static function passMetadata(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        string $redirectsTable
    ): ?array {
        $error = '';
        $columns = ABJ_404_Solution_RedirectsSchemaProbe::columnSet($dbCore, $redirectsTable, null, $error);
        if ($error !== '' || empty($columns)) {
            $logger->warn('RedirectsDenormChunkResolver: metadata probe failed: ' . ($error ?: 'empty columns'));
            return null;
        }
        $hitsError = '';
        $hitsSource = self::readHitsRollupSource($dbCore, $hitsError);
        if ($hitsError !== '') {
            $logger->warn('RedirectsDenormChunkResolver: hits metadata probe failed: ' . $hitsError);
            return null;
        }
        return array('columns' => $columns, 'hits_source' => $hitsSource);
    }

    /**
     * @param array{
     *   ids:array<int,int>,
     *   recompute:bool,
     *   metadata:array{columns:array<string,bool>,hits_source:array{logs_hits_table:string,comparable_redirect_url:string}|null}
     * } $chunk
     */
    private static function publishChunk(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        string $redirectsTable,
        array $chunk
    ): bool {
        $idClause = ABJ_404_Solution_RedirectsDenormColumnSql::buildIdClauses($chunk['ids'])['aliased'];
        $recompute = $chunk['recompute'];
        $metadata = $chunk['metadata'];
        $statements = ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements(array(
            'redirects_table' => $redirectsTable,
            'id_clause' => $idClause,
            'recompute' => $recompute,
            'include_dest_sort_key' => isset($metadata['columns']['dest_sort_key']),
            'include_url_sort_key' => isset($metadata['columns']['url_sort_key']),
            'include_canonical_url' => isset($metadata['columns']['canonical_url']),
            'hits_source' => $metadata['hits_source'],
        ));
        // The single projection statement publishes destination/status/sort key
        // and, when available, hit columns together. InnoDB rolls back failed
        // chunks; MyISAM retries the same ids, and callers acknowledge cursors
        // only after this transaction succeeds.
        try {
            $dbCore->executeAsTransaction($statements);
        } catch (Throwable $error) {
            $logger->warn('RedirectsDenormChunkResolver: stopping after chunk transaction error: ' . $error->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Write one bounded logs_hits batch back to redirects.
     *
     * The coordinator supplies beforeQuery to renew its lease and apply the
     * request budget immediately before each metadata, id, and UPDATE query.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param array{after_id?:mixed,limit?:mixed,before_query?:callable():array<string,mixed>|null} $options
     * @return array{cursor:int,complete:bool,error:string}
     */
    public static function writeHitsChunk(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        array $options
    ): array {
        $afterId = $options['after_id'] ?? 0;
        if (!array_key_exists('after_id', $options) || !is_int($afterId) || $afterId < 0) {
            return array('cursor' => 0, 'complete' => false, 'error' => 'Invalid after_id.');
        }
        $limit = $options['limit'] ?? self::WRITE_BACK_CHUNK_SIZE;
        if (!is_int($limit) || $limit < 1 || $limit > self::WRITE_BACK_CHUNK_SIZE) {
            return array('cursor' => $afterId, 'complete' => false, 'error' => 'Invalid limit.');
        }
        $beforeQuery = $options['before_query'] ?? null;
        if ($beforeQuery !== null && !is_callable($beforeQuery)) {
            return array('cursor' => $afterId, 'complete' => false, 'error' => 'Invalid before_query callback.');
        }

        $availability = self::writeHitsAvailability($dbCore, $logger, $beforeQuery);
        if (!$availability['available']) {
            if ($availability['error'] !== '') {
                return array('cursor' => $afterId, 'complete' => false, 'error' => $availability['error']);
            }
            return array('cursor' => $afterId, 'complete' => true, 'error' => '');
        }

        $redirectsTable = $dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $logsHitsTable = $dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $queryError = '';
        $ids = self::nextHitsIds($dbCore, array(
            'redirects_table' => $redirectsTable,
            'after_id' => $afterId,
            'limit' => $limit,
            'before_query' => $beforeQuery,
        ), $queryError);
        if ($queryError !== '') {
            return array('cursor' => $afterId, 'complete' => false, 'error' => $queryError);
        }
        if (empty($ids)) {
            return array('cursor' => $afterId, 'complete' => true, 'error' => '');
        }

        $canonicalRedirectUrl = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $comparableRedirectUrl = $dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $canonicalRedirectUrl,
            array('table' => $logsHitsTable, 'column' => 'requested_url'),
            $beforeQuery
        );
        $query = ABJ_404_Solution_RedirectsDenormColumnSql::buildHitsRollupFromRollupTableStatement(
            array(
                'redirects_table' => $redirectsTable,
                'logs_hits_table' => $logsHitsTable,
                'id_clause' => ABJ_404_Solution_RedirectsDenormColumnSql::buildIdClauses($ids)['aliased'],
                'comparable_redirect_url' => $comparableRedirectUrl,
            )
        );
        $updateError = self::writeHitsUpdate($dbCore, $query, $beforeQuery);
        if ($updateError !== '') {
            return array('cursor' => $afterId, 'complete' => false, 'error' => $updateError);
        }
        return array('cursor' => (int)max($ids), 'complete' => count($ids) < $limit, 'error' => '');
    }

    private const WRITE_BACK_CHUNK_SIZE = 1000;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param callable():array<string,mixed>|null $beforeQuery
     * @return array{available:bool,error:string}
     */
    private static function writeHitsAvailability(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        ?callable $beforeQuery
    ): array {
        $error = '';
        if (self::redirectsTableKnownAbsent($dbCore, $beforeQuery, $error)) {
            $logger->debugMessage('writeBackLogsHitsColumnsChunk skipped: redirects table absent.');
            return array('available' => false, 'error' => '');
        }
        if ($error !== '') {
            return array('available' => false, 'error' => $error);
        }
        $columns = ABJ_404_Solution_RedirectsSchemaProbe::columnSet($dbCore, $dbCore->doTableNameReplacements('{wp_abj404_redirects}'), $beforeQuery, $error);
        if ($error !== '') {
            return array('available' => false, 'error' => $error);
        }
        if (!isset($columns['dest_for_view'])) {
            $logger->debugMessage('writeBackLogsHitsColumnsChunk skipped: denorm columns absent (schema drift).');
            return array('available' => false, 'error' => '');
        }
        if ($dbCore->noticeState()->isWriteBlockActive()) {
            $logger->debugMessage('writeBackLogsHitsColumnsChunk skipped: DB write block active (read-only / disk full).');
            return array('available' => false, 'error' => '');
        }
        $error = '';
        if (!ABJ_404_Solution_RedirectsSchemaProbe::tableExists($dbCore, '{wp_abj404_logs_hits}', $beforeQuery, $error)) {
            if ($error !== '') {
                return array('available' => false, 'error' => $error);
            }
            $logger->debugMessage('writeBackLogsHitsColumnsChunk skipped: logs_hits rollup table absent.');
            return array('available' => false, 'error' => '');
        }
        return array('available' => true, 'error' => '');
    }

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param callable():array<string,mixed>|null $beforeQuery
     * @param-out string $error
     */
    private static function redirectsTableKnownAbsent(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ?callable $beforeQuery,
        ?string &$error
    ): bool {
        $error = '';
        if ($beforeQuery !== null) {
            $exists = ABJ_404_Solution_RedirectsSchemaProbe::tableExists($dbCore, '{wp_abj404_redirects}', $beforeQuery, $error);
            return $error === '' && !$exists;
        }
        $readiness = new ABJ_404_Solution_TableReadinessGate(
            $dbCore,
            $dbCore->tableNameResolver()
        );
        return $readiness->isKnownAbsent('{wp_abj404_redirects}');
    }

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param array{redirects_table:string,after_id:int,limit:int,before_query:callable():array<string,mixed>|null} $options
     * @param-out string $error
     * @return list<int>
     */
    private static function nextHitsIds(
        ABJ_404_Solution_DatabaseCore $dbCore,
        array $options,
        ?string &$error
    ): array {
        $error = '';
        $redirectsTable = $options['redirects_table'];
        $afterId = $options['after_id'];
        $limit = $options['limit'];
        $beforeQuery = $options['before_query'];
        $queryOptions = array();
        if ($beforeQuery !== null) {
            $queryOptions = $beforeQuery();
            if (!is_array($queryOptions)) {
                $error = 'before_query must return query options.';
                return array();
            }
        }
        if ($beforeQuery !== null) {
            $queryOptions['log_errors'] = false;
        }
        $query = 'SELECT id FROM ' . $redirectsTable . ' FORCE INDEX (PRIMARY) WHERE id > ' . $afterId
            . ' ORDER BY id ASC LIMIT ' . $limit;
        $result = $beforeQuery === null
            ? $dbCore->queryAndGetResults($query)
            : $dbCore->queryAndGetResults($query, $queryOptions);
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? trim((string)$result['last_error']) : '';
        if ($lastError !== '') {
            $error = $lastError;
            return array();
        }
        if (!empty($result['timed_out'])) {
            $error = 'Database query timed out.';
            return array();
        }
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            $error = 'Database query failed without an error message.';
            return array();
        }
        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            $error = 'Malformed redirect id query result.';
            return array();
        }
        $ids = array();
        foreach ($result['rows'] as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['id'] ?? null, 1) : null;
            if ($id === null || $id <= $afterId) {
                $error = 'Malformed redirect id row.';
                return array();
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param callable():array<string,mixed>|null $beforeQuery
     */
    private static function writeHitsUpdate(
        ABJ_404_Solution_DatabaseCore $dbCore,
        string $query,
        ?callable $beforeQuery
    ): string {
        $queryOptions = array();
        if ($beforeQuery !== null) {
            $queryOptions = $beforeQuery();
            if (!is_array($queryOptions)) {
                return 'before_query must return query options.';
            }
        }
        $result = $beforeQuery === null
            ? $dbCore->queryAndGetResults($query)
            : $dbCore->queryAndGetResults($query, $queryOptions);
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? trim((string)$result['last_error']) : '';
        if ($lastError !== '') {
            return $lastError;
        }
        if (!empty($result['timed_out'])) {
            return 'Database query timed out.';
        }
        // The centralized wpdb harvester records a false mutation return as
        // query_succeeded=false even when the driver exposes no error text.
        // Do not acknowledge the chunk on that weaker signal, and fail closed
        // if a malformed/legacy result omits the success flag entirely.
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            return 'Database query failed without an error message.';
        }
        return '';
    }

    /**
     * Read the logshits/last_used rollup source for a chunk, or null when the
     * logs_hits rollup table is absent (a stripped install, or one whose first
     * rollup rebuild has not run yet, rolls nothing up and keeps the 0 defaults).
     *
     * Reads the pre-aggregated logs_hits rollup, NOT raw logsv2 (report.md
     * Finding 2): the old logsv2 `GROUP BY` ran in full once per chunk and timed
     * out on busy sites. logs_hits is keyed by canonical requested_url and is the
     * same source the real-time write-back uses, so the chunk values stay
     * consistent with what the write-back would set.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param-out string $error
     * @return array{logs_hits_table:string,comparable_redirect_url:string}|null
     */
    private static function readHitsRollupSource(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ?string &$error = null
    ): ?array {
        $error = '';
        if (!ABJ_404_Solution_RedirectsSchemaProbe::tableExists($dbCore, '{wp_abj404_logs_hits}', null, $error)) {
            return null;
        }
        $logsHitsTable = $dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $canonicalRedirectUrl = "COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))";
        $comparableRedirectUrl = $dbCore->collationHelper()->coerceExpressionToColumnCollation(
            $canonicalRedirectUrl,
            array('table' => $logsHitsTable, 'column' => 'requested_url')
        );
        return array(
            'logs_hits_table' => $logsHitsTable,
            'comparable_redirect_url' => $comparableRedirectUrl,
        );
    }

}
