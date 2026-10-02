<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the frontend write side of the `abj404_suggest_<md5(url)>` transient:
 * the handoff between the request that renders the 404 page and the request
 * that fills in its suggestions.
 *
 * Two ways a request can fill that slot, and this class owns both so the key
 * derivation, the TTLs and the pending/complete state machine have one home:
 *
 *   - The suggestions were already computed while resolving the request (the
 *     spelling scan ran and produced candidates that scored under the
 *     auto-redirect threshold): publish them directly.
 *   - Nothing is computed yet and the 404 page is rendering its placeholder:
 *     open a pending job. The page's polling script runs it on its first
 *     poll (SuggestionComputeJob), so a client that never runs the script
 *     never pays for the compute.
 *
 * This lived on SpellChecker, which made a Levenshtein-scoring domain class
 * also own a transient lifecycle. SuggestionTransient owns the shared URL normalization, key shape,
 * and TTL constants used by every producer and consumer.
 */
class ABJ_404_Solution_SuggestionPublisher {

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/**
	 * @param ABJ_404_Solution_Logging $logger
	 */
	public function __construct($logger) {
		$this->logger = $logger;
	}

	/**
	 * Publish an already-computed suggestion packet so the shortcode renders it
	 * immediately instead of dispatching a background compute for work that is
	 * already done. The completed packet is authoritative over pending work and
	 * is written directly, avoiding a read-before-write producer race.
	 *
	 * @param string $fullRequestedURL The URL as requested, before normalization.
	 * @param array<int, mixed> $permalinksPacket Two-tuple from the spell checker.
	 * @return void
	 */
	public function cacheComputedSuggestionsForShortcode(string $fullRequestedURL, array $permalinksPacket): void {
		$normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($fullRequestedURL);
		$transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);
		$claim = $this->acquireStateLock($normalizedURL);
		if ($claim === null) {
			$this->logger->debugMessage('Suggestion cache write skipped because another writer owns ' .
				esc_html($normalizedURL));
			return;
		}

		try {
			// allow-cache-empty: factory-built typed array; SuggestionTransient::completeArray
			// always returns a non-empty associative array with at minimum a 'status' key.
			$stored = ABJ_404_Solution_TransientStore::store(
				$transientKey,
				ABJ_404_Solution_SuggestionTransient::completeArray(
					$normalizedURL,
					$permalinksPacket,
					abj_clock()->now(),
					''
				),
				ABJ_404_Solution_SuggestionTransient::COMPLETE_TTL_SECONDS
			);
		} finally {
			$this->releaseStateLock($claim);
		}

		if (!$stored) {
			$this->logger->warn('[SUGGESTION_CACHE_WRITE_FAILED] Could not store completed suggestions for ' .
				esc_html($normalizedURL) . '. Recovery: the shortcode will compute suggestions synchronously.');
			return;
		}

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	/**
	 * Open a pending suggestion job for a 404 page that is about to render the
	 * suggestions placeholder. Nothing is computed here: the page's own polling
	 * script runs the job on its first poll (Ajax_SuggestionPolling ->
	 * SuggestionComputeJob), so compute is paid only when a browser that will
	 * show the result is waiting for it. A client that fetches the page and
	 * never runs its script (a scanner, a crawler) leaves an unclaimed job that
	 * expires after PENDING_TTL_SECONDS.
	 *
	 * The job token identifies this job instance so a stale worker can never
	 * publish over, or mark as crashed, a job that was re-opened after it.
	 *
	 * @param string $requestedURL The URL as requested, before normalization.
	 * @return bool True when a pending job now exists for the URL (opened here
	 *              or already open), so the caller may render the placeholder.
	 *              False when no job could be recorded; the caller then
	 *              computes synchronously so the reader still gets suggestions.
	 */
	public function openPendingJob(string $requestedURL): bool {
		$normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($requestedURL);
		$transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);

		$claim = $this->acquireStateLock($normalizedURL);
		if ($claim === null) {
			$this->logger->debugMessage('Suggestion job not opened: another writer owns ' . esc_html($normalizedURL));
			return false;
		}

		try {
			$existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));
			if ($existing !== null && $existing->isPending()) {
				return true;
			}
			if ($existing !== null && $existing->isComplete()) {
				// Completed while this request was rendering; the caller re-reads it
				// on the next render, and a poll answers it immediately.
				return true;
			}

			// allow-cache-empty: pendingArray always returns a typed, non-empty state packet.
			$stored = ABJ_404_Solution_TransientStore::store(
				$transientKey,
				ABJ_404_Solution_SuggestionTransient::pendingArray(
					$normalizedURL,
					wp_generate_password(32, false),
					0,
					abj_clock()->now()
				),
				ABJ_404_Solution_SuggestionTransient::PENDING_TTL_SECONDS
			);
		} finally {
			$this->releaseStateLock($claim);
		}

		if (!$stored) {
			$this->logger->warn('[SUGGESTION_PENDING_WRITE_FAILED] Could not persist the suggestion job for ' .
				esc_html($normalizedURL) . '. Recovery: the page computes suggestions synchronously.');
			return false;
		}

		$this->logger->debugMessage('Suggestion job opened for ' . esc_html($normalizedURL) .
			'; the first poll from the page computes it.');
		return true;
	}

	/** @return array{key: string, owner: string}|null */
	private function acquireStateLock(string $normalizedURL): ?array {
		$key = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($normalizedURL);
		$owner = abj_service('sync_utils')
			->synchronizerAcquireLockTry($key);
		return $owner === '' ? null : array('key' => $key, 'owner' => $owner);
	}

	/** @param array{key: string, owner: string} $claim */
	private function releaseStateLock(array $claim): void {
		abj_service('sync_utils')
			->synchronizerReleaseLock($claim['owner'], $claim['key']);
	}
}
