<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

require_once __DIR__ . '/../database/DatabaseCollationHelper.php';

/**
 * Reads published posts, pages and images from WordPress tables. Published tags and
 * categories live in PublishedTermsProvider.
 *
 * Every value that reaches these queries (a slug, a search term, a configured post type,
 * a keyword from a caller's extra clause) is BOUND, never spliced into the SQL text. The
 * query executor rewrites `{wp_...}` tokens across the whole statement and only then binds
 * `query_params`, so a value placed in the text would be rewritten along with the
 * template ("{wp_posts}" in a slug would reach the database as "wp_posts"). Each clause is
 * therefore a SqlFragment: its text carries `%s` placeholders and its params carry the
 * values, and SqlFragmentTemplate joins the fragments into the template in one pass.
 *
 * @phpstan-import-type SqlFragment from ABJ_404_Solution_DatabaseQueryBuilderInterface
 */
class ABJ_404_Solution_PublishedContentRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var mixed Options provider exposing getOptions(): array. */
    private $optionsProvider;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_DatabaseCollationHelper */
    private $collationHelper;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logging
     * @param mixed $optionsProvider Object exposing getOptions(): array.
     * @param ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
     * @param ABJ_404_Solution_DatabaseCollationHelper $collationHelper
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions,
        $logging,
        $optionsProvider,
        $errorClassifier,
        $collationHelper
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
        $this->logger = $logging;
        $this->optionsProvider = $optionsProvider;
        $this->errorClassifier = $errorClassifier;
        $this->collationHelper = $collationHelper;
    }

    /** @return string */
    private function getPostsTableName(): string {
        global $wpdb;
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '' ? $wpdb->prefix : 'wp_';
        return $prefix . 'posts';
    }

    /** @return array<string, mixed> */
    private function getRuntimeOptions(): array {
        $provider = $this->optionsProvider !== null ? $this->optionsProvider : abj_service('options_repository');
        if (is_object($provider) && method_exists($provider, 'getOptions')) {
            $options = $provider->getOptions();
            return is_array($options) ? $options : array();
        }
        return array();
    }

    /**
     * Find published posts and pages using named query criteria.
     * Unknown keys and non-scalar values are ignored for forward compatibility.
     *
     * `extra_where_clause` is SQL text. A value it needs travels as a `%s` / `%d`
     * placeholder in the text plus an entry in `extra_where_params`, in order. A clause
     * with no params is fixed text: its `%` characters stay literal.
     *
     * @param array{slug?: string, search_term?: string, limit_results?: string, order_results?: string, extra_where_clause?: string, extra_where_params?: array<int, int|float|string>} $criteria
     * @return array<int, object>
     */
    public function getPublishedPagesAndPostsIDs(array $criteria = array()) {
        $postsTableName = $this->getPostsTableName();

        $slug = $this->publishedCriteriaString($criteria, 'slug');
        $searchTerm = $this->publishedCriteriaString($criteria, 'search_term');
        $limitResults = $this->publishedCriteriaString($criteria, 'limit_results');
        $orderResults = $this->publishedCriteriaString($criteria, 'order_results');
        $extraWhereClause = $this->publishedCriteriaString($criteria, 'extra_where_clause');

        $recognizedPostTypes = $this->dbCore->tableNameResolver()->buildPostTypeSqlList($this->getRuntimeOptions());
        if ($recognizedPostTypes['sql'] === '') {
            return array();
        }

        $slugClause = $this->buildPostSlugClause($slug, $postsTableName);
        $slots = array(
            'recognizedPostTypes' => $recognizedPostTypes,
            'specifiedSlug' => $slugClause['clause'],
            'searchTerm' => $this->buildPostSearchClause($searchTerm),
            'extraWhereClause' => $this->buildExtraWhereClause($extraWhereClause, $this->publishedCriteriaParams($criteria, 'extra_where_params')),
            'limit-results' => $this->buildFixedClause($limitResults, "limit "),
            'order-results' => $this->buildFixedClause($orderResults, "order by "),
        );
        $statement = $this->buildPublishedPagesQuery($slots);

        $first = $this->readRows($statement, true);
        $fallback = $this->applyCollationFallback($statement, $first['queryError'], $first['rows']);
        $fallback = $this->applyInvalidDataSlugFallback($statement, $fallback['queryError'], $fallback['rows'], $slugClause['slug'], $slots);
        $this->handlePublishedPagesQueryError($fallback['queryError'], $statement['sql']);

        return $fallback['rows'];
    }

    /**
     * @param array<string, mixed> $criteria
     * @param string $key
     * @return string
     */
    private function publishedCriteriaString(array $criteria, string $key): string {
        $value = $criteria[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string, mixed> $criteria
     * @param string $key
     * @return array<int, int|float|string>
     */
    private function publishedCriteriaParams(array $criteria, string $key): array {
        $value = $criteria[$key] ?? array();
        $params = array();
        foreach (is_array($value) ? $value : array() as $param) {
            if (is_int($param) || is_float($param) || is_string($param)) {
                $params[] = $param;
            }
        }
        return $params;
    }

    /**
     * @param string $slug
     * @param string $postsTableName
     * @return array{slug: string, clause: SqlFragment}
     */
    private function buildPostSlugClause($slug, string $postsTableName): array {
        if ($slug == "") {
            return array('slug' => '', 'clause' => ABJ_404_Solution_SqlFragmentTemplate::none());
        }

        $cleanSlug = $this->f->sanitizeInvalidUTF8($slug);
        $columnCollation = $this->getPostNameColumnCollation($postsTableName);
        // The clause below pins CHARACTER SET utf8mb4, so it may only be emitted
        // when the column's collation belongs to that family; otherwise the two
        // halves disagree and the engine rejects the read with errno 1253.
        if ($columnCollation !== null
                && ABJ_404_Solution_DatabaseCollationHelper::isUtf8mb4Collation($columnCollation)) {
            // isUtf8mb4Collation() has already established this sanitizes to a
            // non-empty utf8mb4 name, so there is no empty case left to handle. The name is
            // an identifier read from the schema, not visitor data, so it belongs in the text.
            $resolvedCollation = $this->collationHelper->sanitizeCollationIdentifier($columnCollation);
            $clause = array(
                'sql' => " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE " . $resolvedCollation . " = %s \n ",
                'params' => array($cleanSlug),
            );
            return array('slug' => $cleanSlug, 'clause' => $clause);
        }

        if (abj_service('sanitizer')->containsUtf8mb4Characters($cleanSlug)) {
            return array('slug' => $cleanSlug, 'clause' => ABJ_404_Solution_SqlFragmentTemplate::none());
        }

        return array('slug' => $cleanSlug, 'clause' => $this->plainSlugClause($cleanSlug));
    }

    /**
     * @param string $slug
     * @return SqlFragment
     */
    private function plainSlugClause(string $slug): array {
        return array('sql' => " */\n and wp_posts.post_name = %s \n ", 'params' => array($slug));
    }

    /** @return string|null */
    private function getPostNameColumnCollation(string $postsTableName) {
        $collationResult = $this->dbCore->queryAndGetResults(
            "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'post_name'",
            array('query_params' => array($postsTableName), 'log_errors' => false)
        );
        $collationRows = isset($collationResult['rows']) && is_array($collationResult['rows']) ? $collationResult['rows'] : array();
        if (empty($collationRows) || !is_array($collationRows[0])) {
            return null;
        }

        $first = reset($collationRows[0]);
        return is_scalar($first) ? (string)$first : null;
    }

    /**
     * @param string $searchTerm
     * @return SqlFragment
     */
    private function buildPostSearchClause($searchTerm): array {
        if ($searchTerm == "") {
            return ABJ_404_Solution_SqlFragmentTemplate::none();
        }

        // Strip control characters and validate UTF-8 before the value is bound.
        // Pattern 10: defense-in-depth against invalid-UTF-8 bytes reaching MySQL.
        $sanitized = sanitize_text_field($searchTerm);
        return array(
            'sql' => " */\n and lower(wp_posts.post_title) like %s \n ",
            'params' => array('%' . $this->f->strtolower($sanitized) . '%'),
        );
    }

    /**
     * @param string $extraWhereClause
     * @param array<int, int|float|string> $params
     * @return SqlFragment
     */
    private function buildExtraWhereClause($extraWhereClause, array $params): array {
        if ($extraWhereClause == "") {
            return ABJ_404_Solution_SqlFragmentTemplate::none();
        }
        $sql = " */\n " . $extraWhereClause;
        return $params === array()
            ? ABJ_404_Solution_SqlFragmentTemplate::literal($sql)
            : array('sql' => $sql, 'params' => $params);
    }

    /**
     * A caller's `limit` or `order by` text, which carries no values.
     *
     * @param string $text
     * @param string $keyword
     * @return SqlFragment
     */
    private function buildFixedClause($text, string $keyword): array {
        if (empty($text)) {
            return ABJ_404_Solution_SqlFragmentTemplate::none();
        }
        return ABJ_404_Solution_SqlFragmentTemplate::literal(" */\n  " . $keyword . $text);
    }

    /**
     * @param array<string, SqlFragment> $slots
     * @return SqlFragment
     */
    private function buildPublishedPagesQuery(array $slots): array {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        return ABJ_404_Solution_SqlFragmentTemplate::fill($query, $slots);
    }

    /**
     * @param SqlFragment $statement
     * @param bool $logErrors
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function readRows(array $statement, bool $logErrors): array {
        $options = array('result_type' => OBJECT, 'query_params' => $statement['params']);
        if (!$logErrors) {
            $options['log_errors'] = false;
        }
        $result = $this->dbCore->queryAndGetResults($statement['sql'], $options);
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        return array('queryError' => $queryError, 'rows' => $this->objectRows($result['rows'] ?? array()));
    }

    /**
     * @param SqlFragment $statement
     * @param string $queryError
     * @param array<int, object> $rows
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function applyCollationFallback(array $statement, string $queryError, array $rows): array {
        if (empty($queryError) || !$this->errorClassifier->taxonomy()->schema()->isCollationError($queryError)) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $fallbackQuery = $fpreg->regexReplace(
            'CONVERT\(wpt\.name USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
            'wpt.name',
            $statement['sql']
        );
        $fallbackQuery = $fpreg->regexReplace(
            'CONVERT\(usefulterms\.grouped_terms USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
            'usefulterms.grouped_terms',
            is_string($fallbackQuery) ? $fallbackQuery : $statement['sql']
        );
        $fallback = $this->readRows(
            array('sql' => is_string($fallbackQuery) ? $fallbackQuery : $statement['sql'], 'params' => $statement['params']),
            false
        );
        if (!empty($fallback['queryError'])) {
            return array('queryError' => $fallback['queryError'], 'rows' => $rows);
        }

        return $fallback;
    }

    /**
     * @param SqlFragment $statement
     * @param string $queryError
     * @param array<int, object> $rows
     * @param string $slug
     * @param array<string, SqlFragment> $slots
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function applyInvalidDataSlugFallback(
        array $statement,
        string $queryError,
        array $rows,
        string $slug,
        array $slots
    ): array {
        if (empty($queryError) || !$this->errorClassifier->taxonomy()->schema()->isInvalidDataError($queryError) ||
                $slug === '' ||
                strpos($statement['sql'], 'CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4)') === false) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        $slots['specifiedSlug'] = $this->plainSlugClause($slug);
        $fallback = $this->readRows($this->buildPublishedPagesQuery($slots), false);
        if (!empty($fallback['queryError'])) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        return $fallback;
    }

    private function handlePublishedPagesQueryError(string $queryError, string $query): void {
        if ($queryError === '') {
            return;
        }

        if (stripos($queryError, 'unknown column') !== false &&
                stripos($queryError, 'content_keywords') !== false) {
            $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $queryError);
            return;
        }

        if (!$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
    }

    /**
     * @param mixed $rows
     * @return array<int, object>
     */
    private function objectRows($rows): array {
        if (!is_array($rows)) {
            return array();
        }

        $objects = array();
        foreach ($rows as $row) {
            if (is_object($row)) {
                $objects[] = $row;
            }
        }
        return $objects;
    }
}
