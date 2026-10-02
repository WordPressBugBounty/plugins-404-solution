<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/TermUrlEnricher.php';

/**
 * Reads published taxonomy terms (categories and tags) from WordPress tables.
 *
 * Two kinds of read share the row shape (term_id, name, slug, taxonomy, url via
 * TermUrlEnricher), so downstream scoring code does not care which produced a row:
 *
 *  - getTermsByIds(): a bounded set chosen by id, for the n-gram prefilter. The
 *    taxonomy filter mirrors the full-scan queries:
 *      'category' -> the category set ('category', 'product_cat')
 *      'tag'      -> 'post_tag'
 *    Empty / invalid input short-circuits to an empty array with no query.
 *  - getPublishedTags() / getPublishedCategories() (and their counts): every
 *    published term, optionally narrowed to one slug or id.
 *
 * Every value that reaches these queries (a slug, a term id, a configured category) is
 * BOUND, never spliced into the SQL text. The query executor rewrites `{wp_...}` tokens
 * across the whole statement and only then binds `query_params`, so a value placed in
 * the text would be rewritten along with the template ("{wp_terms}" in a slug would
 * reach the database as "wp_terms"). Each clause is a SqlFragment: `%s` / `%d`
 * placeholders in its text and the values in its params, joined by SqlFragmentTemplate.
 *
 * @phpstan-import-type SqlFragment from ABJ_404_Solution_DatabaseQueryBuilderInterface
 */
class ABJ_404_Solution_PublishedTermsProvider {

    /** Term type for category archives. */
    const TYPE_CATEGORY = 'category';

    /** Term type for tag archives. */
    const TYPE_TAG = 'tag';

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_TermUrlEnricher */
    private $termUrlEnricher;

    /** @var ABJ_404_Solution_Functions|null Resolved from the service container when null. */
    private $f;

    /** @var mixed Options provider exposing getOptions(): array, or null for the options_repository service. */
    private $optionsProvider;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier Defaults to $dbCore->errorClassifier().
     * @param ABJ_404_Solution_TermUrlEnricher|null $termUrlEnricher Defaults to a fresh enricher.
     * @param ABJ_404_Solution_Functions|null $functions Defaults to the functions service.
     * @param mixed $optionsProvider Object exposing getOptions(): array. Defaults to the options_repository service.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logging,
        $errorClassifier = null,
        $termUrlEnricher = null,
        $functions = null,
        $optionsProvider = null
    ) {
        $this->dbCore = $dbCore;
        $this->logger = $logging;
        $this->errorClassifier = $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier();
        $this->termUrlEnricher = $termUrlEnricher !== null ? $termUrlEnricher : new ABJ_404_Solution_TermUrlEnricher();
        $this->f = $functions;
        $this->optionsProvider = $optionsProvider;
    }

    /**
     * Fetch published terms of a single type by id, enriched with their URL.
     *
     * @param array<int, int|string> $termIds Candidate term ids (sanitized via absint).
     * @param string $type 'category' or 'tag'.
     * @return array<int, object> Same shape as getPublishedCategories()/getPublishedTags().
     */
    public function getTermsByIds(array $termIds, string $type): array {
        $sanitizedIds = $this->sanitizeIds($termIds);
        if (empty($sanitizedIds)) {
            return array();
        }

        $taxonomyFilter = $this->taxonomyFilterFor($type);
        if ($taxonomyFilter === '') {
            return array();
        }

        $idList = implode(', ', $sanitizedIds);

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTermsByIds.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = strtr($query, array('{taxonomyFilter}' => $taxonomyFilter, '{termIds}' => $idList));

        return $this->readTermRows(array('sql' => $query, 'params' => array()));
    }

    /**
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedTags($slug = null, $limit = null) {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTags.sql");
        $query = $this->dbCore->doTableNameReplacements($query);

        return $this->readTermRows(ABJ_404_Solution_SqlFragmentTemplate::fill($query, array(
            'slug' => $this->termColumnClause('wp_terms.slug', $slug),
            'limit' => $this->limitFragment($limit),
        )));
    }

    /**
     * Cheap published-tag count using the SAME taxonomy filter as
     * getPublishedTags(), but COUNT(*) only (no rows loaded). Feeds the term
     * n-gram coverage readiness gate.
     *
     * @return int
     */
    public function getPublishedTagCount(): int {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTagCount.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        return $this->dbCore->queryScalarInt($query, array('log_errors' => false));
    }

    /**
     * Cheap published-category count using the SAME taxonomy filter as
     * getPublishedCategories(), but COUNT(*) only (no rows loaded). Feeds the
     * term n-gram coverage readiness gate.
     *
     * @return int
     */
    public function getPublishedCategoryCount(): int {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedCategoryCount.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $filled = ABJ_404_Solution_SqlFragmentTemplate::fill($query, array(
            'recognizedCategories' => $this->recognizedCategoriesFragment(),
        ));
        return $this->dbCore->queryScalarInt(
            $filled['sql'],
            array('log_errors' => false, 'query_params' => $filled['params'])
        );
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    public function addURLToTermsRows($rows) {
        return $this->termUrlEnricher->addURLToTermsRows($rows);
    }

    /**
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        // Built from the RESOLVED table name, so the clauses hold no template token.
        $termsTable = $this->dbCore->doTableNameReplacements('{wp_terms}');
        $termIdClause = ABJ_404_Solution_SqlFragmentTemplate::none();
        if ($term_id != null) {
            $termIdClause = array(
                'sql' => "*/ and " . $termsTable . ".term_id = %d\n",
                'params' => array(intval($term_id)),
            );
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedCategories.sql");
        $query = $this->dbCore->doTableNameReplacements($query);

        return $this->readTermRows(ABJ_404_Solution_SqlFragmentTemplate::fill($query, array(
            'recognizedCategories' => $this->recognizedCategoriesFragment(),
            'term_id' => $termIdClause,
            'slug' => $this->termColumnClause($termsTable . '.slug', $slug),
            'limit' => $this->limitFragment($limit),
        )));
    }

    /**
     * Runs a term read, logs a failure the classifier does not recognize as an
     * infrastructure problem, and returns the rows with their URLs.
     *
     * @param SqlFragment $fragment The whole statement and its bound values.
     * @return array<int, object>
     */
    private function readTermRows(array $fragment): array {
        $result = $this->dbCore->queryAndGetResults(
            $fragment['sql'],
            array('result_type' => OBJECT, 'query_params' => $fragment['params'])
        );
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError !== '' && !$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $fragment['sql']);
        }

        return $this->termUrlEnricher->addURLToTermsRows($this->objectRows($result['rows'] ?? array()));
    }

    /**
     * The optional `and <column> = <slug>` clause that closes the template's comment block.
     *
     * @param string $column Qualified column, built from the template's own alias or resolved table name.
     * @param string|null $value
     * @return SqlFragment
     */
    private function termColumnClause(string $column, $value): array {
        if ($value == null) {
            return ABJ_404_Solution_SqlFragmentTemplate::none();
        }
        return array(
            'sql' => "*/ and " . $column . " = %s\n",
            'params' => array($this->functions()->sanitizeInvalidUTF8($value)),
        );
    }

    /**
     * @param mixed $limit
     * @return SqlFragment
     */
    private function limitFragment($limit): array {
        $limitInt = ABJ_404_Solution_ExactInteger::read($limit, 1);
        if ($limitInt === null) {
            return ABJ_404_Solution_SqlFragmentTemplate::none();
        }
        return array('sql' => "LIMIT " . $limitInt, 'params' => array());
    }

    /**
     * The configured category names as an IN (...) list. An empty setting is the
     * empty string literal, which matches nothing and keeps the SQL valid.
     *
     * @return SqlFragment
     */
    private function recognizedCategoriesFragment(): array {
        $fragment = $this->dbCore->tableNameResolver()->buildCategorySqlList($this->getRuntimeOptions());
        if ($fragment['sql'] === '') {
            return array('sql' => "''", 'params' => array());
        }
        return $fragment;
    }

    /** @return ABJ_404_Solution_Functions */
    private function functions() {
        return $this->f !== null ? $this->f : abj_service('functions');
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
     * Map an n-gram cache type to its SQL taxonomy IN(...) list.
     *
     * @param string $type
     * @return string Quoted, comma-separated taxonomy list, or '' for unknown types.
     */
    private function taxonomyFilterFor(string $type): string {
        if ($type === self::TYPE_CATEGORY) {
            return "'category', 'product_cat'";
        }
        if ($type === self::TYPE_TAG) {
            return "'post_tag'";
        }
        return '';
    }

    /**
     * @param array<int, int|string> $termIds
     * @return array<int, int> De-duplicated, positive, integer ids.
     */
    private function sanitizeIds(array $termIds): array {
        $clean = array();
        foreach ($termIds as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $intId = absint($id);
            if ($intId > 0) {
                $clean[$intId] = $intId;
            }
        }
        return array_values($clean);
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
