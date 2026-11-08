<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Boundary to the full-page cache plugins a site may run in front of us.
 *
 * A page cache answers a request before WordPress loads, so a redirect that
 * changes what a URL should return is invisible until the cached copy of that
 * URL is purged. W3 Total Cache ("Cache 404 pages") and LiteSpeed Cache (by
 * default, on a LiteSpeed server) store 404 responses; without a purge a new
 * redirect keeps serving the stale 404 until the cache entry expires.
 *
 * Each known cache is one registry entry: how to tell it is active, how to
 * purge one URL, and how to purge the whole site. Every purge also fires the
 * `abj404_purge_page_cache` action so caches this file does not know (host
 * caches, CDN plugins, WP Rocket) can hook in:
 *
 *     add_action('abj404_purge_page_cache', function (array $urls, bool $siteWide) {
 *         // $urls: absolute URLs whose cached response is stale; empty when $siteWide.
 *     }, 10, 2);
 *
 * A failing third-party purge is logged as a warning and never reaches the
 * caller: the redirect write it follows has already succeeded.
 */
final class ABJ_404_Solution_PageCacheAdapter {

    /** Third-party functions this boundary owns. */
    private const OWNED_FUNCTIONS = array(
        'w3tc_flush_url',
        'w3tc_flush_posts',
        'wpsc_delete_url_cache',
        'wp_cache_clear_cache',
    );

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /** @param ABJ_404_Solution_Logging|null $logger Defaults to the logging service. */
    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /** @return array<int, string> Functions whose direct use this boundary owns. */
    public static function ownedFunctions(): array {
        return self::OWNED_FUNCTIONS;
    }

    /**
     * Purge the given URLs, or the whole site, from every active page cache.
     *
     * @param array{urls: array<int, string>, siteWide: bool} $request
     *   urls     absolute URLs whose cached responses are stale (ignored when siteWide)
     *   siteWide purge every cached page of the current site instead
     * @return array<int, string> Ids of the known caches that were asked to purge.
     */
    public function purge(array $request): array {
        $urls = $request['siteWide'] ? array() : array_values($request['urls']);
        if (!$request['siteWide'] && $urls === array()) {
            return array();
        }
        $purged = array();
        foreach (self::backends() as $id => $backend) {
            if (!$backend['active']()) {
                continue;
            }
            $this->guarded($id, function () use ($backend, $urls, $request): void {
                if ($request['siteWide']) {
                    $backend['site']();
                    return;
                }
                foreach ($urls as $url) {
                    $backend['url']($url);
                }
            });
            $purged[] = $id;
        }
        $this->guarded('abj404_purge_page_cache', static function () use ($urls, $request): void {
            do_action('abj404_purge_page_cache', $urls, $request['siteWide']);
        });
        return $purged;
    }

    /**
     * The known page caches, keyed by plugin slug.
     *
     * @return array<string, array{active: callable(): bool, url: callable(string): void, site: callable(): void}>
     */
    private static function backends(): array {
        return array(
            'w3-total-cache' => array(
                'active' => static function (): bool { return function_exists('w3tc_flush_url'); },
                'url' => static function (string $url): void { w3tc_flush_url($url); },
                // Page cache only; w3tc_flush_all() would also drop the object and database caches.
                'site' => static function (): void {
                    if (function_exists('w3tc_flush_posts')) { w3tc_flush_posts(); }
                },
            ),
            'wp-super-cache' => array(
                'active' => static function (): bool { return function_exists('wpsc_delete_url_cache'); },
                'url' => static function (string $url): void {
                    // WP Super Cache never caches a query-string request and
                    // refuses to purge one, so there is nothing to purge.
                    if (strpos($url, '?') === false) { wpsc_delete_url_cache($url); }
                },
                'site' => static function (): void {
                    if (function_exists('wp_cache_clear_cache')) {
                        wp_cache_clear_cache(is_multisite() ? get_current_blog_id() : 0);
                    }
                },
            ),
            'litespeed-cache' => array(
                'active' => static function (): bool { return defined('LSCWP_V'); },
                'url' => static function (string $url): void { do_action('litespeed_purge_url', $url); },
                'site' => static function (): void { do_action('litespeed_purge_all'); },
            ),
        );
    }

    /** Run one third-party purge; a throw is logged and contained. */
    private function guarded(string $id, callable $purge): void {
        try {
            $purge();
        } catch (Throwable $e) {
            $logger = $this->logger !== null ? $this->logger : abj_service('logging');
            $logger->warnCaught('Page cache purge via ' . $id . ' failed; the redirect was saved, but '
                . $id . ' may serve the old response until its cache entry expires.', $e);
        }
    }
}
