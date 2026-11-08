<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-request state store for regex redirect lookup caching.
 *
 * Redirect matching and redirect mutation both need to clear or inspect this
 * cache, so the state lives in one focused owner instead of on the broad
 * RedirectsRepository facade.
 */
class ABJ_404_Solution_RedirectRegexCacheStore {

    /** Maximum number of regex redirects to cache per request. */
    const MAX_COUNT = 50;

    /** @var array<int, array<string, mixed>>|null */
    private static $regexRedirectsCache = null;

    /** @var bool */
    private static $regexCacheDisabled = false;

    /**
     * Blog id the cache above (or the disabled flag) was populated for. This
     * is a CLASS-STATIC cache -- shared by every RedirectsBulkReader /
     * RedirectsRepository instance in the process, not just one object --
     * memoizing rows read from the per-blog {wp_prefix}_abj404_redirects
     * table. The underlying query is correctly blog-scoped ($wpdb->prefix
     * follows switch_to_blog()), but without this guard the cache sitting in
     * front of it is not: a multisite process that switches blogs mid-request
     * (a WP-CLI network-wide script looping get_sites()/switch_to_blog(), or
     * an Action Scheduler worker draining queued cross-site actions
     * sequentially) would resolve blog A's regex redirects once, cache them
     * process-wide, then silently keep serving blog A's rows to blog B (and
     * every blog after it) for the rest of the process. Every real caller of
     * getRedirectsWithRegEx() (WPCLIRedirectCommandService, RestApiReadService,
     * ViewReadService) inherits this guard automatically since they never
     * touch the cache fields directly.
     *
     * @var int|null
     */
    private static $regexRedirectsCacheBlogId = null;

    /** @var array{blogId:int,version:int,rows:array<int,array<string,mixed>>|null}|null */
    private static $manualSnapshot = null;
    /** @var int Invalidates incomplete streams across mutations and ownership scopes. */
    private static $manualVersion = 0;

    /**
     * Reuse a complete, bounded candidate population only during graph ownership.
     * @internal The repository invokes this inside its continuously held mutex.
     * @param callable():void $operation
     */
    public static function runWithManualSnapshot(callable $operation): void {
        if (self::$manualSnapshot !== null) { $operation(); return; }
        self::$manualSnapshot = array('blogId' => self::currentBlogId(),
            'version' => ++self::$manualVersion, 'rows' => null);
        try { $operation(); }
        finally { self::$manualSnapshot = null; }
    }

    /** Null means no snapshot is permitted for the current blog. */
    public static function manualSnapshotVersion(): ?int {
        if (self::$manualSnapshot === null || self::$manualSnapshot['blogId'] !== self::currentBlogId()) {
            return null;
        }
        return self::$manualSnapshot['version'];
    }

    /** @return array<int,array<string,mixed>>|null Null is cold; an empty array is a complete miss. */
    public static function manualSnapshotRows(): ?array {
        $snapshot = self::$manualSnapshot;
        return $snapshot !== null && $snapshot['blogId'] === self::currentBlogId() ? $snapshot['rows'] : null;
    }

    /** @param array<int,array<string,mixed>> $rows Entire healthy stream, never a truncated prefix. */
    public static function rememberManualSnapshot(array $rows, int $version): void {
        if (self::$manualSnapshot !== null && self::manualSnapshotVersion() === $version && count($rows) <= self::MAX_COUNT) {
            self::$manualSnapshot['rows'] = $rows;
        }
    }

    public function clear(): void {
        self::clearStatic();
    }

    public static function clearStatic(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
        self::$regexRedirectsCacheBlogId = null;
        if (self::$manualSnapshot !== null) {
            self::$manualSnapshot['rows'] = null;
            self::$manualSnapshot['version'] = ++self::$manualVersion;
        }
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        self::invalidateIfBlogChanged();
        return self::$regexRedirectsCache;
    }

    /** @param array<int, array<string, mixed>>|null $cache */
    public static function setRegexRedirectsCache($cache): void {
        self::$regexRedirectsCache = $cache;
        self::$regexRedirectsCacheBlogId = self::currentBlogId();
    }

    public static function isRegexCacheDisabled(): bool {
        self::invalidateIfBlogChanged();
        return self::$regexCacheDisabled;
    }

    public static function setRegexCacheDisabled(bool $disabled): void {
        self::$regexCacheDisabled = $disabled;
        self::$regexRedirectsCacheBlogId = self::currentBlogId();
    }

    /**
     * Drop the cache (and the disabled flag) if the active WordPress blog
     * has changed since the cache was populated. A no-op when nothing has
     * been cached yet, so an uninitialized cache never records a blog id.
     *
     * @return void
     */
    private static function invalidateIfBlogChanged(): void {
        if (self::$regexRedirectsCache === null && self::$regexCacheDisabled === false) {
            return;
        }

        if (self::$regexRedirectsCacheBlogId !== self::currentBlogId()) {
            self::$regexRedirectsCache = null;
            self::$regexCacheDisabled = false;
            self::$regexRedirectsCacheBlogId = null;
        }
    }

    /** @return int */
    private static function currentBlogId(): int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
    }
}
