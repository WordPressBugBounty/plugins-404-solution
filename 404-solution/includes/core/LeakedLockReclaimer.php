<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * An owner record whose holder is gone: when it counts as abandoned, how it is
 * removed, and how the reclaim is reported.
 *
 * Every caller in the plugin releases its lock in a finally block, so a record
 * that outlives its holder means the holder never unwound: a fatal, memory
 * exhaustion, a request timeout, or a host kill (an LVE or a SIGKILL) that
 * skipped PHP's shutdown functions. That is a hosting event the plugin recovers
 * from, and it is found by two different routes -- the dying request's own
 * shutdown pass, and a later request trying to take the same key.
 *
 * Both routes live here because they are one event class and had drifted into
 * two severities. In production report 391 (dianthus.zuidplas.net, 4.3.5,
 * LiteSpeed on CloudLinux) a suggestion-state record sat abandoned for 27.7
 * hours on a site that worked the whole time; the shutdown route reported that
 * condition at warning level while the stale-break route reported it at error
 * level, which is the level ABJ_404_Solution_DebugLogReader keys on to decide
 * what the plugin phones home. Which request happened to notice therefore
 * decided whether the maintainer got mailed a crash report. Keeping the policy
 * and the reporting in one class is what stops that recurring.
 *
 * Storage belongs to ABJ_404_Solution_LockOwnerStore and the acquire/release
 * protocol to ABJ_404_Solution_SynchronizationUtils; neither decision is made
 * here.
 */
class ABJ_404_Solution_LeakedLockReclaimer {

	/** Absolute ceiling, in seconds, on how long any lock may look legitimately
	 * held before a later acquirer breaks it.
	 *
	 * The stale-lock threshold is derived from max_execution_time (a request
	 * cannot legitimately outlive it), but that value is host-controlled and
	 * unbounded. westcoat.kinsta.cloud reported max_execution_time=43200, which
	 * the old "* 2" heuristic turned into a 24-hour window: a lock leaked by a
	 * fatal on 2026-07-11 04:36 was not broken until 2026-07-12 04:40, after
	 * 86615 seconds, and the site served a 4.2.0 schema to 4.3.1 code the whole
	 * time. No critical section in this plugin legitimately runs for minutes, so
	 * the derived value is capped here regardless of what the host allows.
	 * @var int */
	const LOCK_STALE_CEILING_SECONDS = 300;

	/** Stale-lock threshold used when max_execution_time reports no limit
	 * (0 / empty, as under CLI, WP-CLI and many cron contexts).
	 * @var int */
	const LOCK_STALE_FALLBACK_SECONDS = 60;

	/** @var ABJ_404_Solution_LockOwnerStore */
	private $ownerStore;

	/**
	 * @param ABJ_404_Solution_LockOwnerStore $ownerStore the storage the record
	 *   being reclaimed lives in. The same instance the protocol claims through,
	 *   so a mode latch thrown here is the one the next claim sees.
	 */
	public function __construct(ABJ_404_Solution_LockOwnerStore $ownerStore) {
		$this->ownerStore = $ownerStore;
	}

	/** Remove the owner record filed under $internalSynchronizedKey if it has
	 * been sitting there longer than a live request could have held it.
	 *
	 * Does nothing when the key is unowned or its record is still young enough
	 * that a request could legitimately be inside the critical section.
	 *
	 * @param string $internalSynchronizedKey the storage key, as
	 *   ABJ_404_Solution_LockOwnerStore::createInternalKey() builds it.
	 * @return void
	 */
	function breakIfLeaked($internalSynchronizedKey) {
		$uniqueID = $this->ownerStore->readOwner($internalSynchronizedKey);

		if (empty($uniqueID)) {
			return;
		}

		$uniqueIDInfo = explode("_", $uniqueID);

		$createTime = $uniqueIDInfo[0];

		$timePassed = abj_clock()->nowFloat() - (float)$createTime;

		$staleThreshold = $this->staleThresholdSeconds();

		// it should have been released by now.
		if ($timePassed <= $staleThreshold) {
			return;
		}

		// The conditional delete IS the arbitration: it removes the record only
		// if that record still holds the value named here, and answers whether
		// it did. Concurrent finders of one stale record therefore arrive here
		// together and leave with different answers, exactly one of them true.
		$removedIt = $this->ownerStore->deleteOwner(array(
			'key' => $internalSynchronizedKey,
			'owner' => $uniqueID,
		));
		$valueAfterDelete = $this->ownerStore->readOwner($internalSynchronizedKey);

		// Options storage is only proven broken when the record that is still
		// sitting there is the SAME one we just deleted. A different value means
		// another request legitimately claimed the key in the meantime, which is
		// the protocol working rather than the storage failing, and latching the
		// whole site onto file-based records over it would be a false alarm.
		// (deleteOwner() only removes a record whose value the caller named, so
		// losing that race leaves the new owner's record untouched, which is
		// exactly what should happen.)
		if ($valueAfterDelete === $uniqueID && !$this->ownerStore->isFileMode()) {
			$this->ownerStore->switchToFileSyncMode('the options table would not delete the '
				. 'record for key ' . $internalSynchronizedKey . ' (value: ' . $uniqueID
				. ') and still reported that same value afterwards');
			return;
		}

		if (!$removedIt) {
			// Another request broke the same stale record first, or took the key
			// over after it was broken. Its own reclaim is the one that gets
			// reported; reporting from here as well is how a single leaked record
			// produced one report per concurrent finder in report 391.
			return;
		}

		$this->report("Reclaimed a synchronization lock whose holder never released it (the "
			. "request that took it ended without reaching its release call, e.g. a fatal "
			. "error, memory exhaustion, or a host request timeout). Held for " . $timePassed
			. " seconds, past the " . $staleThreshold . " second stale-lock threshold. "
			. "Key: " . $internalSynchronizedKey . ", value: " . $uniqueID
			. ", value after delete: " . $valueAfterDelete
			. ", File sync mode: " . json_encode($this->ownerStore->isFileMode()));
	}

	/** Report a record this request itself leaked and its shutdown pass has now
	 * removed.
	 *
	 * The other detection route. It comes through this class rather than
	 * reporting where it is found, so the two cannot drift apart on how loudly
	 * one condition is announced.
	 *
	 * @param string $internalSynchronizedKey
	 * @param string $uniqueID the owner record that was removed
	 * @return void
	 */
	function reportReleasedByShutdown($internalSynchronizedKey, $uniqueID) {
		$this->report("Released a synchronization lock that this request acquired but had not "
			. "given back (either the request ended without reaching the release call -- a fatal "
			. "error, memory exhaustion, or a timeout inside the critical section -- or the "
			. "release call ran and the store refused it, which the shutdown pass then retried). "
			. "Key: " . $internalSynchronizedKey . ", value: " . $uniqueID);
	}

	/** How long, in seconds, an owner record may sit before it is treated as
	 * leaked.
	 *
	 * Derived from max_execution_time because a live request cannot outlive it,
	 * but capped at LOCK_STALE_CEILING_SECONDS because that ini value is
	 * host-controlled and unbounded. See the constant for the incident this
	 * ceiling exists to prevent.
	 *
	 * @return int
	 */
	function staleThresholdSeconds() {
		$maxExecutionTime = ini_get('max_execution_time');
		$executionTime = ABJ_404_Solution_ExactInteger::read($maxExecutionTime, 1);

		if (empty($maxExecutionTime) || $executionTime === null) {
			return self::LOCK_STALE_FALLBACK_SECONDS;
		}

		return min($executionTime * 2, self::LOCK_STALE_CEILING_SECONDS);
	}

	/** Write one reclaim line.
	 *
	 * Warning level, and this is the only place that decides so. Three reasons,
	 * in order of weight:
	 *
	 *   - The plugin recovered. The record is gone, the caller goes on to take
	 *     the lock, and the work happens. ABJ_404_Solution_DebugLogReader keys on
	 *     the "(ERROR)" token to decide what gets phoned home, so error level
	 *     here mails the maintainer a crash report about a request that
	 *     completed.
	 *   - The cause is not the plugin's to fix. A holder that never reached its
	 *     release call was killed by the host, and only the site's operator can
	 *     do anything about that.
	 *   - Which request notices is arbitrary. The same leak reported at warning
	 *     level when the dying request's shutdown pass catches it, and at error
	 *     level when a later request does, is one event class carrying two
	 *     severities on a coin flip.
	 *
	 * Nothing is swallowed: the line keeps the key, the record, the age and the
	 * threshold, and warnings ride along in the support excerpt of any report
	 * the site does send.
	 *
	 * @param string $message
	 * @return void
	 */
	private function report($message) {
		$logger = abj_service('logging');
		$logger->warn($message);
	}
}
