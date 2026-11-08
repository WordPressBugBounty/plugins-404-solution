<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL query-string and JSON-in-querystring transformation service.
 *
 * Responsibilities:
 *  - sortQueryString: produce a canonical, alphabetized rebuild of a parsed
 *    URL's `query` part so identical requests with reordered parameters
 *    hash to the same storage key.
 *  - removePageIDFromQueryString: strip `p=N` (WordPress's page-id
 *    parameter, so a redirect destination or suggestion link does not
 *    re-trigger a 404) and any plugin-owned control arg listed in
 *    REMOVED_QUERY_ARG_KEYS (request-scoped opt-ins that are meaningless,
 *    or actively wrong, once carried onto a stored/outbound URL).
 *  - decodeComplicatedData: urldecode then json_decode an encoded request
 *    payload (with JSON.stringify single-quote unescape), used by the
 *    AJAX update-options path that ships form data as a single
 *    URL-encoded JSON blob.
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split, parent task i802). The three
 * methods share the responsibility of normalizing query-string-shaped
 * input/output across the dispatch and admin-AJAX surfaces; they are
 * distinct from percent-encoding (UrlEncoder), control-byte
 * sanitization (Sanitizer), and string primitives (MbStringAdapter).
 *
 * Depends on Sanitizer for sanitizeUrlComponent / normalizeUrlString
 * (sortQueryString and removePageIDFromQueryString already routed
 * through the sibling Sanitizer service) and on Logging for the JSON
 * decode error path.
 */
class ABJ_404_Solution_QueryStringHelper {

    /**
     * The plugin's noscript-fallback suggestion opt-in query arg
     * (t_260924_170058_682): a no-JS reader who clicked "Show suggested
     * pages" re-requests the current page with this arg set to '1'.
     * ABJ_404_Solution_ShortCode::isNoscriptSuggestOptIn() reads it from the
     * request and ABJ_404_Solution_ShortcodeSuggestionsPresenter::
     * noscriptSuggestUrl() writes it into the placeholder's fallback link;
     * both reference this constant instead of repeating the literal.
     *
     * @var string
     */
    const SUGGEST_OPT_IN_QUERY_ARG = 'abj404_suggest';

    /**
     * Query-string keys that removePageIDFromQueryString() never carries onto
     * a redirect destination or a rendered suggestion link: WordPress's own
     * page-id parameter, and this plugin's own request-scoped opt-in args.
     * Carrying one of these forward turns a one-request opt-in into a
     * permanent (and wrong) part of every link built from that request; see
     * SUGGEST_OPT_IN_QUERY_ARG's docblock for the concrete incident.
     *
     * @var array<int, string>
     */
    const REMOVED_QUERY_ARG_KEYS = array('p', self::SUGGEST_OPT_IN_QUERY_ARG);

    /** @var ABJ_404_Solution_Sanitizer */
    private $sanitizer;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /**
     * @param ABJ_404_Solution_Sanitizer $sanitizer
     * @param ABJ_404_Solution_Logging|null $logger Optional: when omitted,
     *   decodeComplicatedData() lazy-resolves through the service container
     *   so early-boot and direct test instantiation still get logging.
     */
    public function __construct(ABJ_404_Solution_Sanitizer $sanitizer, $logger = null) {
        $this->sanitizer = $sanitizer;
        $this->logger    = $logger;
    }

    /**
     * Sort the QUERY parts of the requested URL.
     * This is in place because these are stored as part of the URL in the database and used for forwarding to another page.
     * This is done because sometimes different query parts result in a completely different page. Therefore we have to
     * take into account the query part of the URL (?query=part) when looking for a page to redirect to.
     *
     * Here we sort the query parts so that the same request will always look the same.
     *
     * @param array<string, string> $urlParts
     * @return string
     */
    public function sortQueryString(array $urlParts): string {
        if (!array_key_exists('query', $urlParts) || $urlParts['query'] == '') {
            return '';
        }

        $queryParts = array();
        parse_str($urlParts['query'], $queryParts);

        ksort($queryParts);

        $sanitized = $this->sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /**
     * Canonical request identity: retain only WordPress public routing vars.
     * The query_vars filter includes names registered by plugins without
     * mutating WP::public_query_vars. Without a registry, retain the full URL
     * rather than collapse distinct routes during early boot.
     *
     * @param string $url Site-relative source URL.
     * @return string Path with sorted routing parameters, no fragment.
     */
    public function routingKey(string $url): string {
        global $wp;
        if (!is_object($wp) || !isset($wp->public_query_vars) || !is_array($wp->public_query_vars)) {
            return $url;
        }
        $names = apply_filters('query_vars', $wp->public_query_vars);
        if (!is_array($names)) {
            return $url;
        }
        $parts = explode('?', explode('#', $url, 2)[0], 2);
        $query = array();
        parse_str($parts[1] ?? '', $query);
        $names = array_filter($names, 'is_string');
        $query = array_intersect_key($query, array_fill_keys($names, true));
        $sorted = $this->sortQueryString(array('query' => http_build_query($query)));
        return $parts[0] . ($sorted === '' ? '' : '?' . $sorted);
    }

    /**
     * Parse the persisted query-mode vocabulary. Empty CSV/form values mean
     * the nullable default; malformed or unknown writes must be refused.
     * Unknown stored values are only eligible for existing exact lookups.
     *
     * @param mixed $mode
     * @return string|null
     * @throws InvalidArgumentException
     */
    public static function queryMode($mode): ?string {
        if ($mode === null || $mode === '') {
            return null;
        }
        if (!is_string($mode) || !in_array($mode, array('exact', 'ignore', 'pass'), true)) {
            throw new InvalidArgumentException('Invalid query_mode; expected null, exact, ignore, or pass.');
        }
        return $mode;
    }

    /**
     * Read a stored policy without coercing unknown future values into a wider match.
     * @param array<string, mixed> $row
     * @return string|null
     */
    public static function storedQueryMode(array $row): ?string {
        $mode = $row['query_mode'] ?? null;
        return is_string($mode) ? $mode : null;
    }

    /**
     * Canonical identity for a source under its query policy. Regex patterns
     * themselves are never passed here; their request subjects may be.
     */
    public function redirectSourceKey(string $url, ?string $mode, bool $generated = false): string {
        if ($mode === 'pass') { return explode('?', $url, 2)[0]; }
        if ($mode === 'ignore' || ($mode === null && $generated)) { return $this->routingKey($url); }
        return $url;
    }

    /**
     * Decode native or Redirection import policy before a row can be written.
     * @param array<string, mixed> $row
     * @param bool $redirection Omitted Redirection flags mean exact.
     * @return string|null
     */
    public static function importQueryMode(array $row, bool $redirection = false): ?string {
        if (array_key_exists('query_mode', $row)) { return self::queryMode($row['query_mode']); }
        $rawData = $row['match_data'] ?? null;
        if ($rawData === null || $rawData === '') { return $redirection ? 'exact' : null; }
        $data = is_string($rawData) ? json_decode($rawData, true) : $rawData;
        if (!is_array($data)) { throw new InvalidArgumentException('Invalid match_data JSON.'); }
        $source = $data['source'] ?? $data;
        if (!is_array($source)) { throw new InvalidArgumentException('Invalid match_data source.'); }
        return self::queryMode($source['flag_query'] ?? 'exact');
    }

    /**
     * Remove the page-ID arg ('p', which would cause a 404 on the
     * destination otherwise) and any plugin-owned control arg in
     * REMOVED_QUERY_ARG_KEYS from a query string. Used both for redirect
     * destinations and for the query part appended to rendered suggestion
     * links (getCommentPartAndQueryPartOfRequest()), so a control arg that
     * only means something for the CURRENT request never leaks onto a
     * stored or outbound URL.
     *
     * @param string $queryString
     * @return string
     */
    public function removePageIDFromQueryString($queryString) {
        $queryParts = array();
        parse_str($queryString, $queryParts);

        foreach (self::REMOVED_QUERY_ARG_KEYS as $removedKey) {
            if (array_key_exists($removedKey, $queryParts)) {
                unset($queryParts[$removedKey]);
            }
        }

        $sanitized = $this->sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /**
     * First urldecode then json_decode the data, then return it.
     * All of this encoding and decoding is so that [] characters are supported.
     *
     * @param string $data
     * @return mixed
     */
    public function decodeComplicatedData($data) {
        $dataDecoded = urldecode((string)$data);

        // Tolerate a caller that did not unslash first. wp_magic_quotes()
        // escapes the apostrophes encodeURI() leaves literal, and json_decode
        // rejects the resulting backslash-apostrophe as an escape sequence.
        // Ajax_UpdateOptions unslashes at the boundary now; this stays so the
        // parser never breaks on valid input from any other caller.
        $dataStripped = str_replace("\'", "'", $dataDecoded);
        $fixedData = json_decode($dataStripped, true);

        $jsonErrorNumber = json_last_error();
        if ($jsonErrorNumber != 0) {
            $errorMsg = json_last_error_msg();
            $lastMessagePart = ", Decoded: " . $dataDecoded;
            if ($dataStripped != null && mb_strlen($dataStripped) > 1) {
                $lastMessagePart = ", Stripped: " . $dataStripped;
            }

            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $logger->errorMessage("Error " . $jsonErrorNumber . " parsing JSON in "
                    . __CLASS__ . "->" . __FUNCTION__ . "(). Error message: " . $errorMsg . $lastMessagePart);
            }
        }

        return $fixedData;
    }

    /** @return ABJ_404_Solution_Logging|null */
    private function resolveLogger() {
        if ($this->logger !== null) {
            return $this->logger;
        }
        if (function_exists('abj_service_optional')) {
            $resolved = abj_service_optional('logging');
            if ($resolved instanceof ABJ_404_Solution_Logging) {
                return $resolved;
            }
        }
        return null;
    }
}
