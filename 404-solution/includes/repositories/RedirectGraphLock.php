<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connection-owned serialization of one site's redirect graph.
 *
 * Source locks cannot protect reciprocal edges with different sources. This
 * lock covers the whole graph, including the empty graph, without requiring
 * InnoDB, a schema migration, or a transaction that could commit a caller's
 * transaction. The SQL ownership predicate fences reconnect/retry writes.
 * // allow-no-test-found: exercised through real REST requests in RestApiRedirectRefusedWriteTest
 */
final class ABJ_404_Solution_RedirectGraphLock {
    /** @var array<string,array{owner:self,depth:int}> Continuously held connection scopes. */
    private static $scopes = array();
    /** @var string Prepared SQL expression, not a user-supplied identifier. */
    private $name;
    /** @var string Database/table expression on this wpdb instance. */
    private $key;
    /** @var int Acquisitions this object must release. */
    private $depth = 0;

    public function __construct(string $table) {
        global $wpdb;
        // DAO-bypass-approved: lock identity must be prepared on the active connection.
        $name = $wpdb->prepare(
            "CONCAT('abj404:graph:', LEFT(SHA2(CONCAT(DATABASE(), ':', %s), 256), 48))", $table
        );
        // An unbound name would ask for GET_LOCK(, 2) and report the plugin's
        // own defect as "lock unavailable, not stored".
        if (!is_string($name) || $name === '') {
            throw new ABJ_404_Solution_StatementPreparationException('the redirect graph lock name');
        }
        $this->name = $name;
        $this->key = spl_object_id($wpdb) . ':' . $name;
    }

    /** Empty string means acquired; every other answer refuses the mutation. */
    public function acquire(): string {
        global $wpdb;
        if (isset(self::$scopes[$this->key])) {
            if (!self::$scopes[$this->key]['owner']->isOwned()) {
                return 'Redirect graph lock lost: database connection changed during admission or mutation.';
            }
            self::$scopes[$this->key]['depth']++;
            $this->depth++;
            return '';
        }
        // DAO-bypass-approved: do not retry connection-owned lock acquisition through the query recovery pipeline.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT GET_LOCK(' . $this->name . ', 2)');
        if ($answer === 1 || $answer === '1') {
            self::$scopes[$this->key] = array('owner' => $this, 'depth' => 1);
            $this->depth++;
            // A request may have cached rules before waiting for another writer.
            // Only a fresh physical acquisition discards that prior graph;
            // nested row operations borrow the same continuously held scope.
            ABJ_404_Solution_RedirectRegexCacheStore::clearStatic();
            return '';
        }
        $error = trim((string)($wpdb->last_error ?? ''));
        return 'Redirect graph lock unavailable: ' . ($error !== '' ? $error
            : ($answer === 0 || $answer === '0' ? 'lock wait timed out.' : 'database returned no lock ownership.'));
    }

    public function ownershipPredicate(): string {
        return 'IS_USED_LOCK(' . $this->name . ') = CONNECTION_ID()';
    }

    public function isOwned(): bool {
        global $wpdb;
        // DAO-bypass-approved: observe ownership on the connection that will perform the mutation.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT ' . $this->ownershipPredicate());
        return $answer === 1 || $answer === '1';
    }

    public function release(): string {
        if ($this->depth < 1 || !isset(self::$scopes[$this->key])) {
            return 'Redirect graph lock release failed: this object has no acquisition to release.';
        }
        $this->depth--;
        self::$scopes[$this->key]['depth']--;
        if (self::$scopes[$this->key]['depth'] > 0) { return ''; }
        $owner = self::$scopes[$this->key]['owner'];
        unset(self::$scopes[$this->key]);
        return $owner->releasePhysical();
    }

    /** Release the one native acquisition after every borrower has finished. */
    private function releasePhysical(): string {
        global $wpdb;
        // DAO-bypass-approved: release exactly one acquisition on the active database connection, including exceptional exits.
        $answer = ABJ_404_Solution_DatabaseDriverCall::getVar($wpdb, 'SELECT RELEASE_LOCK(' . $this->name . ')');
        if ($answer === 1 || $answer === '1') { return ''; }
        return 'Redirect graph lock release failed: ' . ((string)($wpdb->last_error ?? '') ?: 'connection no longer owns the lock.');
    }

    /**
     * Run one mutation while this connection owns the graph lock.
     *
     * @template T of int|string
     * @param callable(): T $operation
     * @param array{onFailure: callable(string): T, onReleaseFailure: callable(string): void} $failureHandlers
     * @return T
     */
    public function withOwnership(callable $operation, array $failureHandlers) {
        $error = $this->acquire();
        if ($error !== '') {
            return $failureHandlers['onFailure']($error);
        }

        try {
            $value = $operation();
            if ($this->isOwned()) {
                return $value;
            }
            $error = 'Redirect graph lock lost: database connection changed during admission or mutation.';
        } finally {
            $releaseError = $this->release();
            if ($releaseError !== '') {
                $failureHandlers['onReleaseFailure']($releaseError);
            }
        }

        return $failureHandlers['onFailure']($error);
    }
}
