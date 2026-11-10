<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performs normalized redirect row lookups against the redirects table.
 */
class ABJ_404_Solution_RedirectLookupRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var array<int, string>|null Request-local schema snapshot. */
    private $columns = null;

    /** @var string Error from the most recent schema read; never cache a failure. */
    private $columnsError = '';

    /** @var array<string, int> */
    private $lookupContext;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param array<string, int> $lookupContext
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $functions, array $lookupContext = array()) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
        $excludedID = isset($lookupContext['excluded_redirect_id']) ? (int)$lookupContext['excluded_redirect_id'] : 0;
        $this->lookupContext = $excludedID > 0 ? array('excluded_redirect_id' => $excludedID) : array();
    }

    /** @param array<string, int> $context @return self */
    public function withLookupContext(array $context): self {
        return new self($this->dbCore, $this->f, $context);
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @param string $scope exact, routing, or pass-only path fallback.
     * @return array<string, mixed>
     */
    public function getActiveRedirectForNormalizedUrl($url, $degradedMode = false, string $scope = 'exact'): array {
        $parts = explode('?', $url, 2);
        $path = $parts[0];
        $suffix = isset($parts[1]) ? '?' . $parts[1] : '';
        $url1 = $url;
        $url2 = (substr($path, -1) === '/' ? rtrim($path, '/') : $path . '/') . $suffix;

        $query = $this->expandExactUrlMatches(
            ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPermalinkFromURL.sql"));

        if ($degradedMode && $this->redirectsTableMissingScheduledColumns()) {
            $query = $this->stripScheduledRedirectPredicates($query);
        }
        if ($degradedMode && $this->columnsError !== '') {
            return array('id' => 0, 'last_error' => $this->columnsError);
        }

        if ($scope !== 'exact') {
            $columns = $this->getRedirectsTableColumns();
            if ($this->columnsError !== '') {
                return array('id' => 0, 'last_error' => $this->columnsError);
            }
            if ($columns === array()) { return array('id' => 0); }
            if (in_array('query_mode', array_map('strtolower', $columns), true)) {
                $predicate = $scope === 'pass'
                    ? "and r.query_mode = 'pass'"
                    : "and (r.query_mode IS NULL OR r.query_mode IN ('ignore', 'pass'))";
                $query = str_replace('where   r.url', 'where 1=1 ' . $predicate . ' AND r.url', $query);
            } elseif ($scope === 'pass') {
                return array('id' => 0);
            }
        }

        $excludedID = isset($this->lookupContext['excluded_redirect_id'])
            ? (int)$this->lookupContext['excluded_redirect_id'] : 0;
        if ($excludedID > 0) {
            $orderPos = stripos($query, 'order by');
            if ($orderPos !== false) {
                $query = substr($query, 0, $orderPos) . "and r.id <> {excluded_redirect_id}\n" . substr($query, $orderPos);
            }
        }

        // The visitor's URL is bound LAST. Table names and constants are expanded on the
        // TEMPLATE first, and the URL travels to the executor as a positional query
        // parameter, so no `{token}` pass (table names, constants, translation) ever runs over
        // bytes an anonymous visitor chose. The old order bound the URL and then ran the
        // passes over the finished statement: `/a{Some msgid}` became a translation inside the
        // quoted literal.
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->replaceKnownConstants($query);
        $queryData = array("url1" => $url1, "url2" => $url2);
        if ($excludedID > 0) {
            $queryData['excluded_redirect_id'] = $excludedID;
        }
        list($preparedQuery, $orderedValues) = $this->prepare_query($query, $queryData);
        $results = ABJ_404_Solution_DatabaseReadResultValidator::confirm(
            $this->dbCore->queryAndGetResults($preparedQuery, array('query_params' => $orderedValues)),
            'active redirect lookup'
        );

        return $this->firstRedirectRow($results);
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForNormalizedUrl($url, int $excludedId = 0): array {
        return $this->firstRedirectRow($this->getLiveRowsForNormalizedUrl($url, $excludedId));
    }

    /** All live rows of one stored source, including legacy duplicates.
     * @return array{rows: array<int, array<string, mixed>>, last_error: string} Query result retaining database errors.
     */
    public function getLiveRowsForNormalizedUrl(string $url, int $excludedId = 0): array {
        // Index-assisted exact match: the plain `url = {url}` predicate is
        // sargable on the `url`(190) prefix index and narrows to the (typically
        // one) row sharing this URL ignoring collation; the StoredValueMatch
        // refinement then enforces byte-exact equality. A byte compare is the
        // strictest possible comparison, so its match set is always a subset of
        // the column-collation equality -- the result is identical to the bare
        // byte-exact predicate, but the optimizer can use the index instead of
        // full-scanning the redirects table on every new-URL capture (a hot path
        // under scanner-flood 404 traffic). StoredValueMatch converts both sides
        // to one charset first, so the compare holds on a site whose connection
        // charset differs from the table's.
        // allow-unbounded-select: single-URL equality lookup on a hot capture path; returns the few rows matching one URL
        // Table names are expanded on the template and the URL is bound last, as a positional
        // query parameter (see getActiveRedirectForNormalizedUrl).
        $query = $this->dbCore->doTableNameReplacements('select * from {wp_abj404_redirects} where url = {url} and ' .
            ABJ_404_Solution_StoredValueMatch::equals('url', '{url}') .
            " and disabled = 0 AND id <> {excluded_id} ");
        list($preparedQuery, $orderedValues) = $this->prepare_query($query, array("url" => $url, 'excluded_id' => $excludedId));
        $results = ABJ_404_Solution_DatabaseReadResultValidator::confirm(
            $this->dbCore->queryAndGetResults($preparedQuery, array('query_params' => $orderedValues)),
            'live redirect lookup'
        );

        $rawRows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : array();
        $rows = array();
        foreach ($rawRows as $row) {
            if (is_array($row)) { $rows[] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY); }
        }
        return array('rows' => $rows, 'last_error' => isset($results['last_error']) && is_string($results['last_error']) ? $results['last_error'] : '');
    }

    /**
     * Rows only: display and list consumers that render whatever came back.
     * A caller that mutates or answers "not found" from the result must use
     * readRedirectsByIDs(), because here a refused read and a missing row are
     * both an empty array.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectsByIDs($ids): array {
        return $this->readRedirectsByIDs($ids)['rows'];
    }

    /**
     * Rows by id with the executor's error retained. Empty rows with an empty
     * `last_error` prove the ids have no stored row; empty rows with an error
     * prove nothing, and a write that was about to be admitted against the
     * stored row must be refused rather than treated as "no row to check".
     *
     * @param array<int, int|string> $ids
     * @return array{rows: array<int, array<string, mixed>>, last_error: string} Query result retaining database errors.
     */
    public function readRedirectsByIDs($ids): array {
        if (!is_array($ids) || empty($ids)) {
            return array('rows' => array(), 'last_error' => '');
        }
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);

        // allow-unbounded-select: caller-supplied id keyset (where id IN an absint-filtered list); bounded by the explicit id list
        $query = "select * from {wp_abj404_redirects} " .
                "where id in (" . $multipleIds . ")";
        $result = ABJ_404_Solution_DatabaseReadResultValidator::confirm(
            $this->dbCore->queryAndGetResults($query),
            'redirect ID lookup'
        );
        $rawRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();

        $rows = array();
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
            }
        }
        return array(
            'rows' => $rows,
            'last_error' => isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '',
        );
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    private function firstRedirectRow(array $results): array {
        // Empty rows prove absence only after a successful query. Keep the
        // executor's error so safety-sensitive consumers can refuse a write.
        if (isset($results['last_error']) && is_string($results['last_error']) && $results['last_error'] !== '') {
            return array('id' => 0, 'last_error' => $results['last_error']);
        }
        $redirect = array();
        $rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : array();

        if (empty($rows) || !is_array($rows[0])) {
            return array('id' => 0);
        }

        foreach ($rows[0] as $key => $value) {
            $redirect[$key] = $value;
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }

    /**
     * @return bool
     */
    private function redirectsTableMissingScheduledColumns(): bool {
        $cacheKey = 'abj404_redirects_scheduled_cols_status';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if ($cached === 'missing') { return true; }
            if ($cached === 'present') { return false; }
        }

        $columns = $this->getRedirectsTableColumns();

        if (empty($columns)) {
            return false;
        }

        $colsLower = array_map('strtolower', $columns);
        $missing = !in_array('start_ts', $colsLower, true)
                || !in_array('end_ts', $colsLower, true);

        if (function_exists('set_transient')) {
            $hour = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
            // allow-cache-empty: value is always 'missing' or 'present' (non-empty string literal)
            set_transient(
                $cacheKey,
                $missing ? 'missing' : 'present',
                $missing ? 5 * 60 : 24 * $hour
            );
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    private function getRedirectsTableColumns(): array {
        $this->columnsError = '';
        if ($this->columns !== null) { return $this->columns; }
        global $wpdb;
        if (!isset($wpdb)) {
            $this->columnsError = 'Redirect schema lookup unavailable: wpdb is not initialized';
            return [];
        }
        // The redirects table is named by the executor's own token; nothing is
        // spliced into the statement text ahead of its `{wp_...}` pass.
        $result = ABJ_404_Solution_DatabaseReadResultValidator::confirm(
            $this->dbCore->queryAndGetResults(
                "SHOW COLUMNS FROM `{wp_abj404_redirects}`",
                array('log_errors' => false)
            ),
            'redirect schema lookup'
        );
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->columnsError = $lastError;
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Case-insensitive: MySQL drivers return SHOW COLUMNS metadata
            // key casing inconsistently (Field vs field).
            $row = array_change_key_case($row, CASE_LOWER);
            if (isset($row['field']) && is_string($row['field'])) {
                $columns[] = $row['field'];
            }
        }
        $this->columns = $columns;
        return $columns;
    }

    /**
     * Fill the lookup template's `{url1_is_exact}` and `{url2_is_exact}` markers with the
     * byte-exact match predicate, so the formula has one source (StoredValueMatch) and the
     * template keeps only the sargable `r.url IN (...)` prefilter in plain SQL.
     *
     * @param string $template
     * @return string
     */
    private function expandExactUrlMatches(string $template): string {
        return str_replace(
            array('{url1_is_exact}', '{url2_is_exact}'),
            array(
                ABJ_404_Solution_StoredValueMatch::equals('r.url', "'{url1}'"),
                ABJ_404_Solution_StoredValueMatch::equals('r.url', "'{url2}'"),
            ),
            $template
        );
    }

    /**
     * @param string $sql
     * @return string
     */
    private function stripScheduledRedirectPredicates(string $sql): string {
        $stripped = preg_replace(
            '/^[^\n]*\br\.(?:start_ts|end_ts)\b[^\n]*\R?/m',
            '',
            $sql
        );
        return is_string($stripped) ? $stripped : $sql;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                return $matches[0];
            }
            $value = $data[$key];
            $ordered_values[] = $value;
            $placeholder_type = is_int($value) ? '%d' : '%s';
            return $placeholder_type;
        }, $query);

        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
    }
}
