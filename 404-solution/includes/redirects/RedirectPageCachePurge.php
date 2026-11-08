<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped queue of page-cache purges owed by redirect mutations.
 *
 * Every write path that changes what a source URL returns (insert, edit,
 * trash, restore, status change, delete, condition save, cron cleanup) queues
 * the affected rows here. One flush at the end of the request hands the
 * de-duplicated URLs to ABJ_404_Solution_PageCacheAdapter, so a bulk action
 * over 500 rows costs one purge round, not 500, and the purge runs after the
 * write it follows has committed.
 *
 * Only rows that answer visitors matter: manual, automatic and regex
 * redirects. A captured, ignored or later row still serves the 404, so
 * queuing it would purge on every 404 a busy site logs and defeat the cache.
 * A regex row matches URLs no list can name, so it purges the whole site, as
 * does any queue longer than MAX_URLS.
 *
 * URLs are made absolute when queued, against the blog that owns the row; the
 * flush switches to each queued blog on multisite.
 */
final class ABJ_404_Solution_RedirectPageCachePurge {

    /**
     * Above this many URLs one site-wide purge is cheaper than per-URL purges,
     * and stays clear of the response-header size LiteSpeed's per-URL purge
     * header grows into.
     */
    const MAX_URLS = 100;

    /** Runs before LiteSpeed Cache sends its purge header at shutdown priority 0. */
    const SHUTDOWN_PRIORITY = -100;

    /** @var array<int, array{urls: array<string, true>, siteWide: bool}> Pending purges keyed by blog id. */
    private static $pending = array();

    /** @var bool Whether flush() is hooked for this request. */
    private static $scheduled = false;

    /**
     * Queue the rows a stored-row read returned, or the whole site when the read
     * failed. queueRows() skips a row it cannot name, which is right for a row
     * that no longer exists and wrong for one the database refused to return:
     * the cached page of its source would outlive the write.
     *
     * @param array{rows: array<int, mixed>, last_error: string} $read Result of a by-id read.
     * @return void
     */
    public static function queueRead(array $read): void {
        if ($read['last_error'] !== '') {
            self::queueSiteWide();
            return;
        }
        self::queueRows($read['rows']);
    }

    /**
     * Queue the source URLs of redirect rows, before or after a change.
     *
     * Pass both the stored row and its new state when a write changes either:
     * the old source stops answering and the new one starts.
     *
     * @param array<int, mixed> $rows Rows carrying `url` and `status`.
     * @return void
     */
    public static function queueRows(array $rows): void {
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['url'], $row['status']) || !is_scalar($row['url'])) {
                continue;
            }
            $status = ABJ_404_Solution_ExactInteger::read($row['status'], PHP_INT_MIN);
            if ($status === ABJ404_STATUS_REGEX) {
                self::queueSiteWide();
            } elseif (self::answersVisitors($status)) {
                self::queueUrls(array((string)$row['url']));
            }
        }
    }

    /**
     * Queue source URLs whose rows' statuses are unknown, such as the URL a
     * duplicate cleanup collapsed.
     *
     * @param array<int, string> $sources Stored (site-relative) source URLs.
     * @return void
     */
    public static function queueUrls(array $sources): void {
        $home = rtrim((string)home_url('/'), '/');
        $blogId = self::currentBlogId();
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            self::pendingFor($blogId);
            if (self::$pending[$blogId]['siteWide']) {
                return;
            }
            self::$pending[$blogId]['urls'][$home . '/' . ltrim($source, '/')] = true;
            if (count(self::$pending[$blogId]['urls']) > self::MAX_URLS) {
                self::queueSiteWide();
                return;
            }
        }
    }

    /**
     * Queue a purge of every redirect status listed, as for a bulk action
     * over whole statuses. Purges the site when any listed status answers
     * visitors.
     *
     * @param array<int, int|string> $statuses ABJ404_STATUS_* values.
     * @return void
     */
    public static function queueStatuses(array $statuses): void {
        foreach ($statuses as $status) {
            if (self::answersVisitors(ABJ_404_Solution_ExactInteger::read($status, PHP_INT_MIN))) {
                self::queueSiteWide();
                return;
            }
        }
    }

    /** @return void */
    public static function queueSiteWide(): void {
        $blogId = self::currentBlogId();
        self::pendingFor($blogId);
        self::$pending[$blogId]['siteWide'] = true;
        // Once the whole site is owed, individual URLs are redundant. Keep
        // retention bounded during bulk writes, rather than only at shutdown.
        self::$pending[$blogId]['urls'] = array();
    }

    /**
     * Hand everything queued to the page caches and empty the queue. Hooked
     * on shutdown; a later write in the same process schedules a new flush.
     *
     * @return void
     */
    public static function flush(): void {
        $pending = self::$pending;
        self::$pending = array();
        self::$scheduled = false;
        $adapter = new ABJ_404_Solution_PageCacheAdapter();
        foreach ($pending as $blogId => $entry) {
            $urls = array_keys($entry['urls']);
            $siteWide = $entry['siteWide'];
            $switched = $blogId !== self::currentBlogId() && function_exists('switch_to_blog');
            if ($switched) {
                switch_to_blog($blogId);
            }
            try {
                $adapter->purge(array('urls' => $siteWide ? array() : $urls, 'siteWide' => $siteWide));
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        }
    }

    /**
     * Test seam: drop the queue and forget the scheduled hook without purging.
     * A ParaTest worker runs hundreds of tests in one process with add_action
     * stubbed, so without this a write in one test would leave URLs queued and
     * the flush marked scheduled for every later test. Not used in production.
     */
    public static function resetRequestLocalsForTesting(): void {
        self::$pending = array();
        self::$scheduled = false;
    }

    /** Create the blog's queue entry and make sure a flush is scheduled. */
    private static function pendingFor(int $blogId): void {
        if (!isset(self::$pending[$blogId])) {
            self::$pending[$blogId] = array('urls' => array(), 'siteWide' => false);
        }
        if (self::$scheduled) {
            return;
        }
        self::$scheduled = true;
        // A write made by another shutdown callback, after the early flush has
        // already run, still needs a flush later in the same action.
        $priority = (function_exists('doing_action') && doing_action('shutdown')) ? PHP_INT_MAX : self::SHUTDOWN_PRIORITY;
        add_action('shutdown', array(self::class, 'flush'), $priority);
    }

    /** Whether rows of this status change what a visitor's request returns. */
    private static function answersVisitors(?int $status): bool {
        return in_array($status, array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX), true);
    }

    private static function currentBlogId(): int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
    }
}
