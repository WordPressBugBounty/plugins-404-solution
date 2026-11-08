<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectChainTraversal.php';
require_once __DIR__ . '/RedirectCycleException.php';

/**
 * Follows the redirect that would be selected for each same-site URL.
 *
 * This class deliberately owns no SQL. Exact rows come from the production
 * RedirectLookupService and regex rows come from the production SpellChecker;
 * the default constructor only assembles those collaborators lazily for the
 * few runtime callers that do not inject them.
 *
 * A collaborator that cannot be resolved makes the walk 'unverifiable', never
 * 'ends': skipping the regex rules or the redirect-all setting would report a
 * chain as terminating without having looked at the hop that closes it.
 */
class ABJ_404_Solution_RedirectChainWalker implements ABJ_404_Solution_RedirectChainTraversal {

    /** A chain longer than this is treated as unsafe rather than followed. */
    const MAX_HOPS = 20;

    /** @var ABJ_404_Solution_RedirectLookupService|null */
    private $lookup;

    /** @var ABJ_404_Solution_SpellChecker|null */
    private $spellChecker;

    /** @var ABJ_404_Solution_Functions|null */
    private $functions;

    /** @var array<string, mixed> */
    private $lookupContext;

    /**
     * @param ABJ_404_Solution_RedirectLookupService|null $lookup
     * @param ABJ_404_Solution_SpellChecker|null $spellChecker
     * @param ABJ_404_Solution_Functions|null $functions
     * @param array<string, mixed> $lookupContext
     */
    public function __construct($lookup = null, $spellChecker = null, $functions = null, array $lookupContext = array()) {
        $this->lookup = $lookup;
        $this->spellChecker = $spellChecker;
        $this->functions = $functions;
        $excludedID = ABJ_404_Solution_ExactInteger::readOr($lookupContext['excluded_redirect_id'] ?? null, 1, 0);
        $this->lookupContext = $excludedID > 0 ? array('excluded_redirect_id' => $excludedID) : array();
        if (isset($lookupContext['pending_regex']) && is_array($lookupContext['pending_regex'])) {
            $this->lookupContext['pending_regex'] = $lookupContext['pending_regex'];
        }
        if ($this->lookup !== null && $excludedID > 0) {
            $this->lookup = $this->lookup->withLookupContext(array('excluded_redirect_id' => $excludedID));
        }
    }

    /** @param array<string, mixed> $context @return self */
    public function withLookupContext(array $context): self {
        return new self($this->lookup, $this->spellChecker, $this->functions, $context);
    }

    /**
     * Walk the redirect chain beginning at a same-site path.
     *
     * `$visited` contains the path that led to `$startPath` (the request path
     * is supplied by the request-time guard). The returned path includes both
     * that prefix and every destination reached by the walk.
     *
     * Status is 'ends', 'cycle', or 'unverifiable' when a collaborator the
     * walk needed could not be resolved; 'reason' then names that failure.
     *
     * @param string $startPath
     * @param array<int, string> $visited
     * @return array{status: string, path: array<int, string>, reason?: string}
     */
    public function walk(string $startPath, array $visited): array {
        $path = array();
        $identities = array();
        foreach ($visited as $item) {
            if (!is_string($item)) {
                continue;
            }
            $display = $this->relativePath($item, false);
            $identity = $this->identityPath($item);
            if ($display !== null && $identity !== null && !in_array($identity, $identities, true)) {
                $path[] = $display;
                $identities[] = $identity;
            }
        }

        $current = $this->relativePath($startPath, false);
        $currentIdentity = $this->identityPath($startPath);
        if ($current === null || $currentIdentity === null) {
            return array('status' => 'ends', 'path' => $path);
        }
        if (in_array($currentIdentity, $identities, true)) {
            $path[] = $current;
            return array('status' => 'cycle', 'path' => $path);
        }
        $path[] = $current;
        $identities[] = $currentIdentity;

        $hops = 0;
        while (true) {
            if ($hops >= self::MAX_HOPS) {
                return array('status' => 'cycle', 'path' => $path);
            }

            $destination = $this->nextDestination($current);
            if ($destination === null) {
                return array('status' => 'ends', 'path' => $path);
            }
            if (isset($destination['unverifiable'])) {
                return array('status' => 'unverifiable', 'path' => $path, 'reason' => $destination['unverifiable']);
            }

            $next = $this->relativePath($destination['url'], false);
            $nextIdentity = $this->identityPath($destination['url']);
            if ($next === null || $nextIdentity === null) {
                return array('status' => 'ends', 'path' => $path);
            }
            if (in_array($nextIdentity, $identities, true)) {
                $path[] = $next;
                return array('status' => 'cycle', 'path' => $path);
            }

            $path[] = $next;
            $identities[] = $nextIdentity;
            $current = $next;
            $hops++;
        }
    }

    /**
     * Resolve a typed redirect destination through the same row boundary used
     * for persisted and regex-selected rows, then continue the existing walk.
     *
     * @param array{type: int, destination: mixed, source: string, code?: int} $destination
     * @param array<int, string> $visited
     * @param array<string, mixed> $lookupContext
     * @return array{status: string, path: array<int, string>, reason?: string}
     */
    public function walkFromDestination(array $destination, array $visited, array $lookupContext = array()): array {
        $row = array(
            'type' => $destination['type'] ?? 0,
            'final_dest' => $destination['destination'] ?? '',
            'code' => $destination['code'] ?? 301,
        );
        $resolved = $this->rowDestination($row);
        if ($resolved === null) {
            return array('status' => 'ends', 'path' => $visited);
        }
        if (isset($resolved['unverifiable'])) {
            return array(
                'status' => 'unverifiable',
                'path' => array((string)$destination['source']),
                'reason' => $resolved['unverifiable'],
            );
        }

        $walker = $lookupContext === array() ? $this : $this->withLookupContext($lookupContext);
        return $walker->walk($resolved['url'], $visited);
    }

    /**
     * @param string $path
     * @return array{url: string}|array{unverifiable: string}|null null when the chain ends at $path.
     */
    private function nextDestination(string $path): ?array {
        $absolute = $this->absoluteUrl($path);
        $postID = function_exists('url_to_postid') ? url_to_postid($absolute) : 0;
        $live = ABJ_404_Solution_ExactInteger::readOr($postID, 1, 0) > 0;

        if (!$live) {
            $row = $this->lookupService()->getActiveRedirectForURL($path);
            if (isset($row['last_error']) && is_string($row['last_error']) && $row['last_error'] !== '') {
                return array('unverifiable' => 'Redirect lookup failed: ' . $row['last_error']);
            }
            $rowID = ABJ_404_Solution_ExactInteger::readOr($row['id'] ?? null, 1, 0);
            if (is_array($row) && $rowID > 0) {
                $destination = $this->rowDestination($row);
                if ($destination !== null) {
                    return $destination;
                }
                return null;
            }
        }

        if ($live) {
            $redirectAll = $this->redirectAllRequestsEnabled();
            if (is_string($redirectAll)) {
                return array('unverifiable' => $redirectAll);
            }
            if (!$redirectAll) {
                return null;
            }
        }

        $spellChecker = $this->spellChecker();
        if (is_string($spellChecker)) {
            return array('unverifiable' => $spellChecker);
        }
        $matchOptions = $this->lookupContext === array() ? null : $this->lookupContext;
        $match = $spellChecker->getPermalinkUsingRegEx($path, $matchOptions);
        if (is_array($match) && isset($match['last_error']) && is_string($match['last_error']) && $match['last_error'] !== '') {
            return array('unverifiable' => 'Regex redirect lookup failed: ' . $match['last_error']);
        }
        if (!is_array($match)) {
            return null;
        }
        return $this->rowDestination($match);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{url: string}|array{unverifiable: string}|null
     */
    private function rowDestination(array $row): ?array {
        $code = isset($row['code']) && is_scalar($row['code']) ? (int)$row['code'] : 0;
        if ($code === 410 || $code === 451) {
            return null;
        }

        $type = isset($row['type']) && is_scalar($row['type']) ? (int)$row['type'] : 0;
        if ($type === (int)ABJ404_TYPE_404_DISPLAYED) {
            return null;
        }

        $raw = array_key_exists('final_dest', $row) ? $row['final_dest'] : ($row['link'] ?? '');
        if ($type === (int)ABJ404_TYPE_EXTERNAL) {
            return $this->externalDestination($raw);
        }

        if (!in_array($type, array(
            (int)ABJ404_TYPE_POST,
            (int)ABJ404_TYPE_CAT,
            (int)ABJ404_TYPE_TAG,
            (int)ABJ404_TYPE_HOME,
        ), true)) {
            return array('unverifiable' => 'Redirect row has an unrecognized destination type.');
        }

        // SpellURLMatcher has already resolved an internal regex row through
        // PermalinkResolver and returns that URL as `link` rather than
        // carrying the stored object ID as `final_dest`. Continue from the
        // resolved URL; exact lookup rows take the ID path below.
        if (!array_key_exists('final_dest', $row)) {
            return $this->resolvedDestination($row['link'] ?? '');
        }

        if ($type !== (int)ABJ404_TYPE_HOME
            && ABJ_404_Solution_ExactInteger::read($raw, 1) === null) {
            return array('unverifiable' => 'Internal redirect destination is not an exact positive ID.');
        }

        return $this->resolvedPermalinkDestination($raw, $type);
    }

    /**
     * @param mixed $raw
     * @return array{url:string}|null
     */
    private function externalDestination($raw): ?array {
        return is_scalar($raw) && trim((string)$raw) !== '' ? array('url' => trim((string)$raw)) : null;
    }

    /**
     * A destination the resolver answered for but that has no live URL (a
     * deleted or unpublished post, a deleted term) ends the chain: request
     * dispatch serves the 404 page for it and issues no redirect, so no hop
     * leaves it. Only a resolver that could not answer is unverifiable.
     *
     * @param mixed $resolved
     * @return array{url:string}|null null when the destination has no live URL.
     */
    private function resolvedDestination($resolved): ?array {
        if (!is_scalar($resolved) || trim((string)$resolved) === '' || (string)$resolved === 'dunno') {
            return null;
        }
        return array('url' => trim((string)$resolved));
    }

    /**
     * @param mixed $raw
     * @return array{url:string}|array{unverifiable:string}|null
     */
    private function resolvedPermalinkDestination($raw, int $type): ?array {
        try {
            $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray((is_scalar($raw) ? (string)$raw : '') . '|' . $type, 0);
        } catch (\Throwable $e) {
            return array('unverifiable' => self::unavailableReason('permalink_resolver', $e));
        }
        return $this->resolvedDestination(is_array($permalink) && isset($permalink['link']) ? $permalink['link'] : '');
    }

    /** @return bool|string The setting, or why it could not be read. */
    private function redirectAllRequestsEnabled() {
        try {
            $optionsService = abj_service('options_repository');
        } catch (\Throwable $e) {
            return self::unavailableReason('options_repository', $e);
        }
        $options = is_object($optionsService) && method_exists($optionsService, 'getOptions')
            ? $optionsService->getOptions(true) : array();
        $optionsArray = is_array($options) ? $options : array();
        return function_exists('abj404_is_redirect_all_requests_enabled')
            ? abj404_is_redirect_all_requests_enabled($optionsArray)
            : (isset($optionsArray['redirect_all_requests']) && is_scalar($optionsArray['redirect_all_requests'])
                && (string)$optionsArray['redirect_all_requests'] === '1');
    }

    /** @return ABJ_404_Solution_RedirectLookupService */
    private function lookupService() {
        if ($this->lookup instanceof ABJ_404_Solution_RedirectLookupService) {
            return $this->lookup;
        }
        $functions = $this->functions();
        $dbCore = abj_service('db_core');
        $homePath = parse_url((string)home_url('/'), PHP_URL_PATH);
        $homeDirectory = is_string($homePath) ? rtrim($homePath, '/') : '';
        $this->lookup = new ABJ_404_Solution_RedirectLookupService(
            $functions,
            new ABJ_404_Solution_RedirectLookupRepository($dbCore, $functions, array(
                'excluded_redirect_id' => ABJ_404_Solution_ExactInteger::readOr($this->lookupContext['excluded_redirect_id'] ?? null, 1, 0),
            )),
            new ABJ_404_Solution_PluginLogicUrlNormalization($functions, $homeDirectory, strlen($homeDirectory))
        );
        return $this->lookup;
    }

    /** @return ABJ_404_Solution_SpellChecker|string The regex matcher, or why it could not be resolved. */
    private function spellChecker() {
        if ($this->spellChecker instanceof ABJ_404_Solution_SpellChecker) {
            return $this->spellChecker;
        }
        try {
            $resolved = abj_service('spell_checker');
        } catch (\Throwable $e) {
            return self::unavailableReason('spell_checker', $e);
        }
        if (!($resolved instanceof ABJ_404_Solution_SpellChecker)) {
            return 'spell_checker resolved to ' . (is_object($resolved) ? get_class($resolved) : gettype($resolved));
        }
        $this->spellChecker = $resolved;
        return $this->spellChecker;
    }

    /**
     * One line naming a service that could not be resolved and the original
     * error, for the admin-facing refusal and the log.
     *
     * @param string $service Container id.
     * @param \Throwable $e
     * @return string
     */
    public static function unavailableReason(string $service, \Throwable $e): string {
        return sprintf('%s unavailable: %s (code %s): %s',
            $service, get_class($e), (string)$e->getCode(), $e->getMessage());
    }

    /** @return ABJ_404_Solution_Functions */
    private function functions() {
        if ($this->functions === null) {
            $this->functions = abj_service('functions');
        }
        return $this->functions;
    }

    /**
     * Return a same-site path, retaining its query string, or null for another
     * host. Site subdirectories are removed because redirect sources are stored
     * relative to the WordPress home directory.
     */
    private function relativePath(string $url, bool $trimTrailingSlash = true): ?string {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        $home = parse_url((string)home_url('/'));
        if (!is_array($home)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (!$this->isSameSiteUrl($url, $home, $parts)) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path'] : '/';
        $homePath = isset($home['path']) && is_string($home['path']) ? rtrim($home['path'], '/') : '';
        $path = $this->removeHomePath($path, $homePath);
        $path = '/' . ltrim($path, '/');
        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';
        if ($trimTrailingSlash) {
            $pathOnly = rtrim($path, '/');
            $path = $pathOnly === '' ? '/' : $pathOnly;
        }
        if ($query !== '') {
            $path .= '?' . $query;
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $home
     * @param array<string, mixed>|false $parts
     */
    private function isSameSiteUrl(string $url, array $home, $parts): bool {
        if (!isset($parts['host']) && strpos($url, '//') !== 0) {
            return true;
        }
        if (!is_array($parts)) {
            return false;
        }
        return $this->sameAuthority($home, $parts);
    }

    private function removeHomePath(string $path, string $homePath): string {
        if ($homePath === '' || ($path !== $homePath && strpos($path, $homePath . '/') !== 0)) {
            return $path;
        }
        $path = substr($path, strlen($homePath));
        return $path === '' || $path[0] !== '/' ? '/' . $path : $path;
    }

    /**
     * Exact lookup treats slash variants as equivalent, while regex matching
     * must receive the original trailing slash. Query pair ordering is also
     * normalized only for cycle identity; lookup receives the original query.
     */
    private function identityPath(string $url): ?string {
        // RedirectLookupService sanitizes and rawurldecodes its input before
        // generating candidates. Use the same decoded identity so /a%20b and
        // /a b cannot evade a cycle merely because they were spelled
        // differently in the stored destination.
        $decoded = rawurldecode($url);
        $path = $this->relativePath($decoded, true);
        if ($path === null) {
            return null;
        }
        $parts = explode('?', $path, 2);
        if (!isset($parts[1]) || $parts[1] === '') {
            return $path;
        }
        $pairs = explode('&', $parts[1]);
        sort($pairs, SORT_STRING);
        return $parts[0] . '?' . implode('&', $pairs);
    }

    /** @param string $path @return string */
    private function absoluteUrl(string $path): string {
        $home = rtrim((string)home_url('/'), '/');
        return $home . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $home
     * @param array<string, mixed> $target
     */
    private function sameAuthority(array $home, array $target): bool {
        $homeHost = isset($home['host']) && is_scalar($home['host']) ? strtolower((string)$home['host']) : '';
        $targetHost = isset($target['host']) && is_scalar($target['host']) ? strtolower((string)$target['host']) : '';
        if ($homeHost === '' || $homeHost !== $targetHost) {
            return false;
        }
        return $this->normalizedPort($home) === $this->normalizedPort($target);
    }

    /** @param array<string, mixed> $parts */
    private function normalizedPort(array $parts): int {
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : 0;
        // Standard HTTP and HTTPS endpoints belong to the same WordPress site;
        // changing scheme cannot make a stored redirect chain terminate.
        return in_array($port, array(80, 443), true) ? 0 : $port;
    }
}
