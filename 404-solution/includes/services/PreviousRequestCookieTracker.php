<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks the prior frontend request used by redirect loop prevention.
 */
class ABJ_404_Solution_PreviousRequestCookieTracker {

    const LOOP_WINDOW_SECONDS = 10;
    const LOOP_SOURCE_LIMIT = 5;
    const LOOP_HISTORY_LIMIT = 8;

    /** @var array<string, bool> One decision per source per PHP request. */
    private $sourceDecisions = array();

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging|null $logging
     */
    function __construct($logging = null) {
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /** @return string */
    function readCookieWithPreviousRqeuestShort(): string {
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieNameShort = $cookieName . '_SHORT';

        if (array_key_exists($cookieNameShort, $_COOKIE) &&
                array_key_exists($cookieName, $_COOKIE) &&
                is_scalar($_COOKIE[$cookieName])) {
            return (string)$_COOKIE[$cookieName];
        }

        return '';
    }

    /**
     * Catch HTTP, JavaScript and meta-refresh loops for any client that keeps
     * cookies, whoever owns the other hops; page renders do not clear history.
     * Five source visits with gaps under ten seconds stop 2-, 3- and 4-hop HTTP
     * cycles at hops 9, 13 and 17, before browsers' roughly 20-redirect limit;
     * a fifth fast repeat is rarer in legitimate use than a fourth.
     * Cookie-less clients, cycles taking ten seconds or more, and cycles with
     * more than eight sources fail open. A person or script with a cookie jar
     * hitting one redirecting source five times with gaps under ten seconds
     * gets a refusal, cleared after ten seconds of quiet.
     *
     * @param string $sourceKey Normalized source identity from RedirectLoopGuard.
     * @return bool Whether this source has exhausted its short bounce window.
     */
    public function repeatedRedirectSource(string $sourceKey): bool {
        $source = hash('sha256', $sourceKey);
        if (array_key_exists($source, $this->sourceDecisions)) {
            return $this->sourceDecisions[$source];
        }
        $now = abj_clock()->now();
        $cookieName = ABJ404_PP . '_REDIRECT_SOURCES';
        $raw = $_COOKIE[$cookieName] ?? null;
        // wp_magic_quotes() escapes COOKIE before template/admin callbacks.
        // Decode the original JSON bytes, not WordPress's escaped copy.
        $history = is_string($raw) && strlen($raw) <= 2048
            ? json_decode(wp_unslash($raw), true) : null;
        $clean = array();
        if (is_array($history)) {
            foreach (array_slice($history, -self::LOOP_HISTORY_LIMIT, null, true) as $key => $entry) {
                if (is_string($key) && preg_match('/\A[a-f0-9]{64}\z/D', $key) === 1
                    && is_array($entry) && isset($entry['last'], $entry['count'])
                    && is_int($entry['last']) && is_int($entry['count'])
                    && $entry['last'] <= $now && $now - $entry['last'] < self::LOOP_WINDOW_SECONDS
                    && $entry['count'] > 0 && $entry['count'] <= self::LOOP_SOURCE_LIMIT) {
                    $clean[$key] = $entry;
                }
            }
        }
        $entry = $clean[$source] ?? array('last' => $now, 'count' => 0);
        $entry['last'] = $now;
        $entry['count'] = min(self::LOOP_SOURCE_LIMIT, $entry['count'] + 1);
        unset($clean[$source]);
        $clean[$source] = $entry;
        $clean = array_slice($clean, -self::LOOP_HISTORY_LIMIT, null, true);
        if (!headers_sent()) {
            setcookie($cookieName, (string)json_encode($clean), array(
                'expires' => $now + self::LOOP_WINDOW_SECONDS, 'path' => '/',
                'httponly' => true, 'samesite' => 'Lax',
            ));
        }
        return $this->sourceDecisions[$source] = $entry['count'] >= self::LOOP_SOURCE_LIMIT;
    }

    /** @return void */
    function setCookieWithPreviousRequest(): void {
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $requestedUrlRaw = abj_service('sanitizer')->normalizeUrlString($requestUri);
        $requestedUrlCleaned = preg_replace('/\?.*$/', '', $requestedUrlRaw);
        $requestedUrl = is_string($requestedUrlCleaned) ? $requestedUrlCleaned : $requestedUrlRaw;

        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieNameShort = $cookieName . '_SHORT';
        try {
            setcookie($cookieName, $requestedUrl, abj_clock()->now() + (60 * 4), "/");
            setcookie($cookieNameShort, $requestedUrl, abj_clock()->now() + (5), "/");

            if (!isset($_COOKIE[$cookieName . '_UPDATE_URL']) ||
                    empty($_COOKIE[$cookieName . '_UPDATE_URL'])) {
                $updateUrlRaw = abj_service('sanitizer')->normalizeUrlString($requestUri);
                $updateUrlCleaned = preg_replace('/\?.*$/', '', $updateUrlRaw);
                $updateUrl = is_string($updateUrlCleaned) ? $updateUrlCleaned : $updateUrlRaw;
                setcookie($cookieName . '_UPDATE_URL', $updateUrl, abj_clock()->now() + (60 * 4), "/");
            }

        } catch (Exception $e) {
            $this->logger->warnCaught('There was an issue setting a cookie; falling back to the script-set cookie.', $e);
            $expireTime = date("D, d M Y H:i:s T", abj_clock()->now() + (60 * 4));
            $template = ABJ_404_Solution_FileSystemService::readFileContents(
                dirname(__DIR__) . '/html/previousRequestCookieFallbackScript.html',
                false
            );
            echo "\n" . str_replace(
                array('{cookie_name}', '{requested_url}', '{expires}'),
                array(esc_js($cookieName), esc_js($requestedUrl), esc_js($expireTime)),
                $template
            ) . "\n";
        }

        abj_service('request_context')->requested_url = $requestedUrl;
    }
}
