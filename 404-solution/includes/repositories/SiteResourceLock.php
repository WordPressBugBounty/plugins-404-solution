<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/DatabaseDriverCall.php';

/** Bounded, connection-owned exclusion for initialization of one site's resources. */
final class ABJ_404_Solution_SiteResourceLock {
    /** @var string Prepared SQL expression identifying the database, site and resource. */
    private $name;
    /** @var array<string, int> Nested acquisitions on one site's active wpdb connection. */
    private static $depth = array();
    /** @var string Identity of the site resource on this wpdb instance. */
    private $key;
    /** @var bool Whether this object acquired its share of exclusion. */
    private $held = false;
    /** @var int Site captured when this lock was constructed. */
    private $blogId;
    /** @var string Database table prefix captured when this lock was constructed. */
    private $prefix;
    /** @var string Options table captured when this lock was constructed. */
    private $optionsTable;
    /** @var string Posts table captured when this lock was constructed. */
    private $postsTable;
    /** @var string Database name captured when this lock was constructed. */
    private $databaseName;
    /** @var string Exact binary SQL literal for the captured physical database. */
    private $databaseLiteral;
    /** @var int|null wpdb object identity captured when this lock was constructed. */
    private $wpdbObjectId;
    /** @var object|null The exact wpdb connection that owns this lock. */
    private $wpdbConnection;

    /** @param string $resource Internal resource identity, never user input. */
    public function __construct(string $resource) {
        global $wpdb;
        $this->blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $this->prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        $this->optionsTable = is_object($wpdb) && isset($wpdb->options) ? (string) $wpdb->options : '';
        $this->postsTable = is_object($wpdb) && isset($wpdb->posts) ? (string) $wpdb->posts : '';
        if (!is_object($wpdb)) {
            throw new ABJ_404_Solution_StatementPreparationException('the site resource lock connection');
        }
        $prefix = $this->prefix;
        // wpdb::select() changes the physical connection database without
        // updating wpdb::$dbname in the local WordPress core. Capture the
        // server-selected database, not that mutable PHP cache.
        $selectedDatabase = ABJ_404_Solution_DatabaseDriverCall::getCheckedVar($wpdb, 'SELECT DATABASE()');
        $this->databaseName = is_string($selectedDatabase) ? $selectedDatabase : '';
        if ($this->databaseName === '') {
            throw new ABJ_404_Solution_StatementPreparationException('the selected site database');
        }
        $databaseHex = strtoupper(bin2hex($this->databaseName));
        $this->databaseLiteral = 'BINARY 0x' . $databaseHex;
        $this->wpdbObjectId = spl_object_id($wpdb);
        $this->wpdbConnection = $wpdb;
        // DAO-bypass-approved: locks must use the connection that performs the guarded work, without query recovery retries.
        $prefixLiteral = $this->prepareLiteral($wpdb, $prefix);
        $resourceLiteral = $this->prepareLiteral($wpdb, $resource);
        if ($prefixLiteral === '' || $resourceLiteral === '') {
            throw new ABJ_404_Solution_StatementPreparationException('the site resource lock name');
        }
        $name = "CONCAT('abj404:site:', LEFT(SHA2(CONCAT(" . $this->databaseLiteral
            . ", ':', " . $prefixLiteral . ", ':', " . $resourceLiteral . "), 256), 48))";
        $this->name = $name;
        $this->key = spl_object_id($wpdb) . ':' . $this->name;
    }

    /** @return bool False refuses mutation; callers may retry on a later request. */
    public function acquire(): bool {
        global $wpdb;
        if (!is_object($wpdb) || $this->wpdbConnection === null
            || spl_object_id($wpdb) !== $this->wpdbObjectId) {
            return false;
        }
        if (!$this->isForCurrentSite()) {
            return false;
        }
        $wpdb = $this->wpdbConnection;
        if ($this->held) {
            if ($this->isOwned()) { return true; }
            $this->held = false;
            if (isset(self::$depth[$this->key])) {
                unset(self::$depth[$this->key]);
            }
            return false;
        }
        // MySQL 5.6 releases a previous named lock when acquiring another.
        // Both initialization callers share this resource, and nested callers
        // borrow the held lock without another database acquisition/release.
        if (isset(self::$depth[$this->key])) {
            // The depth map is in-process, while GET_LOCK ownership belongs
            // to the current MySQL connection. A wpdb reconnect can preserve
            // the PHP object (and this key) after MySQL released the lock;
            // borrowing that stale depth would authorize an unguarded write.
            if (!$this->isOwned()) {
                unset(self::$depth[$this->key]);
                return false;
            }
            self::$depth[$this->key]++;
            $this->held = true;
            return true;
        }
        // DAO-bypass-approved: never reconnect/retry a connection-owned acquisition.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT GET_LOCK(' . $this->name . ', 2)');
        if ($answer === 1 || $answer === '1') {
            self::$depth[$this->key] = 1;
            $this->held = true;
            return true;
        }
        abj404_logPhpFallback('early-boot', 'Site initialization lock unavailable: '
            . ((string)($wpdb->last_error ?? '') ?: 'lock busy or ownership unavailable.'));
        return false;
    }

    public function ownershipPredicate(): string {
        // The named lock is connection-owned, but MySQL permits the same
        // connection to select another database. Bind both physical scope and
        // lock ownership so a foreign selected database cannot authorize a
        // mutation even while it still sees the same connection id.
        return 'BINARY DATABASE() = ' . $this->databaseLiteral
            . ' AND IS_USED_LOCK(' . $this->name . ') = CONNECTION_ID()';
    }

    /**
     * A named lock is connection-owned, but switch_to_blog() repoints this
     * same wpdb object at another site's tables. Keep those scopes distinct.
     */
    public function isForCurrentSite(): bool {
        global $wpdb;
        if (!is_object($wpdb) || $this->wpdbObjectId === null
            || spl_object_id($wpdb) !== $this->wpdbObjectId) {
            return false;
        }
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        // This is a cheap logical-site fence. The physical database is
        // intentionally not read from cached wpdb::$dbname: wpdb::select()
        // changes the server-selected database without updating that PHP
        // property, and some connection adapters do not expose it at all.
        // isOwned() supplies the authoritative binary DATABASE() predicate.
        return $blogId === $this->blogId
            && (string) ($wpdb->prefix ?? '') === $this->prefix
            && (string) ($wpdb->options ?? '') === $this->optionsTable
            && (string) ($wpdb->posts ?? '') === $this->postsTable;
    }

    public function isOwned(): bool {
        if (!$this->isForCurrentSite()) {
            return false;
        }
        $wpdb = $this->wpdbConnection;
        if (!is_object($wpdb)) {
            return false;
        }
        // DAO-bypass-approved: observe ownership on the connection that will perform the mutation.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT ' . $this->ownershipPredicate());
        return $answer === 1 || $answer === '1';
    }

    /** @return void Release in finally, including exceptions during initialization. */
    public function release(): void {
        $wpdb = $this->wpdbConnection;
        if (!$this->held) { return; }
        $this->held = false;
        if (!isset(self::$depth[$this->key])) { return; }
        if (--self::$depth[$this->key] > 0) { return; }
        unset(self::$depth[$this->key]);
        if (!is_object($wpdb)) {
            abj404_logPhpFallback('early-boot', 'Site initialization lock release skipped: connection unavailable.');
            return;
        }
        // DAO-bypass-approved: release on the same connection, without query recovery retries.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT RELEASE_LOCK(' . $this->name . ')');
        if ($answer !== 1 && $answer !== '1') {
            abj404_logPhpFallback('early-boot', 'Site initialization lock release failed: '
                . ((string)($wpdb->last_error ?? '') ?: 'connection no longer owns the lock.'));
        }
    }

    /** @param object $wpdb */
    private function prepareLiteral($wpdb, string $value): string {
        $prepare = array($wpdb, 'prepare');
        if (!is_callable($prepare)) {
            return '';
        }
        $literal = call_user_func($prepare, '%s', $value);
        if (!is_string($literal)) {
            return '';
        }
        // Native wpdb removes placeholder escapes at query-filter priority 0;
        // this lock expression is later appended to such queries, so normalize
        // the same boundary representation before storing it.
        $remove = array($wpdb, 'remove_placeholder_escape');
        if (!is_callable($remove)) {
            return is_callable(array($wpdb, 'placeholder_escape')) ? '' : $literal;
        }
        $literal = call_user_func($remove, $literal);
        return is_string($literal) ? $literal : '';
    }
}
