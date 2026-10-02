<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs one pending page-suggestion job to completion inside the current
 * request.
 *
 * A job is opened when the 404 page renders its suggestions placeholder
 * (SuggestionPublisher::openPendingJob) and is run by the first poll from
 * that page's own script (Ajax_SuggestionPolling). Running it there, rather
 * than from a loopback request fired by the 404 request, means the spelling
 * engine only runs when a reader's browser is waiting for the answer: a
 * scanner that fetches 404 URLs and never executes the page script costs no
 * suggestion compute at all, and a host that cannot make loopback requests
 * still gets suggestions.
 *
 * The job owns the worker side of the `abj404_suggest_<md5(url)>` state
 * machine: claim (single-flight), crash marker, compute, publish. State
 * transitions go through SuggestionWorkerStateStore so claim, completion and
 * crash marking share one set of critical sections.
 */
final class ABJ_404_Solution_SuggestionComputeJob {

    /** The job ran here and its packet is available to the caller. */
    public const OUTCOME_COMPUTED = 'computed';

    /** Another request owns the job, or it already finished. */
    public const OUTCOME_OWNED_ELSEWHERE = 'owned_elsewhere';

    /** The claim could not be persisted; nothing was computed. */
    public const OUTCOME_CLAIM_WRITE_FAILED = 'claim_write_failed';

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    public function __construct(ABJ_404_Solution_Clock $clock) {
        $this->clock = $clock;
    }

    /**
     * Claim the pending job for $job['normalizedURL'] and, when this request
     * wins the claim, compute and publish its suggestions.
     *
     * The computed packet is returned even when publishing it fails, so the
     * reader who is waiting still sees suggestions; the failure is logged
     * with its outcome for the site admin.
     *
     * @param array{transientKey: string, normalizedURL: string, requestedURL: string, token: string} $job
     * @return array{outcome: string, suggestionsPacket: array<int, mixed>}
     */
    public function run(array $job): array {
        $stateStore = $this->stateStore();
        $claim = $stateStore->claimWorker(array(
            'transientKey' => $job['transientKey'],
            'normalizedURL' => $job['normalizedURL'],
            'token' => $job['token'],
        ));
        if ($claim['status'] === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_WRITE_FAILED) {
            abj404_logPhpFallback('suggestion-claim-write-failed',
                '[SUGGESTION_CLAIM_WRITE_FAILED] Could not persist the worker claim for ' .
                $job['transientKey'] . '. Recovery: the next poll retries the claim.');
            return array('outcome' => self::OUTCOME_CLAIM_WRITE_FAILED, 'suggestionsPacket' => array());
        }
        if ($claim['status'] !== ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_CLAIMED) {
            return array('outcome' => self::OUTCOME_OWNED_ELSEWHERE, 'suggestionsPacket' => array());
        }
        $workerStartedAt = $claim['startedAt'];

        // Registered before the expensive computation so a fatal inside it
        // (memory exhaustion, max_execution_time) marks the job 'error' and
        // the page's next poll can stop waiting.
        register_shutdown_function(
            array(__CLASS__, 'handleComputationCrash'),
            array(
                'transientKey' => $job['transientKey'],
                'token' => $job['token'],
                'requestedURL' => $job['requestedURL'],
                'workerStartedAt' => $workerStartedAt,
            )
        );

        $logger = abj_service('logging');
        $logger->debugMessage('SuggestionComputeJob: starting computation for ' . esc_html($job['requestedURL']));

        $suggestionsPacket = $this->computeSuggestions($job['requestedURL']);

        $outcome = $stateStore->publishCompleted(array(
            'transientKey' => $job['transientKey'],
            'normalizedURL' => $job['normalizedURL'],
            'requestedURL' => $job['requestedURL'],
            'token' => $job['token'],
            'workerStartedAt' => $workerStartedAt,
            'suggestionsPacket' => $suggestionsPacket,
        ));
        $this->logPublishOutcome($outcome, $job['requestedURL'], $logger);

        $suggestionCount = isset($suggestionsPacket[0]) ? count((array)$suggestionsPacket[0]) : 0;
        $logger->debugMessage('SuggestionComputeJob: completed computation for ' .
            esc_html($job['requestedURL']) . ' - found ' . $suggestionCount . ' suggestions');

        return array('outcome' => self::OUTCOME_COMPUTED, 'suggestionsPacket' => $suggestionsPacket);
    }

    /**
     * Run the spelling engine for one requested URL with the site's
     * suggestion options.
     *
     * @return array<int, mixed> Two-tuple suggestion packet.
     */
    private function computeSuggestions(string $requestedURL): array {
        $abj404logic = abj_service('plugin_logic');
        $spellChecker = abj_service('spell_checker');
        $urlSlugOnly = $abj404logic->urlNormalization()->removeHomeDirectory($requestedURL);
        $options = abj_service('options_repository')->getOptions();

        // Gate 4 is the early return that fires when the N-gram prefilter
        // finds zero candidates at Dice >= 0.3. That is useful in the
        // synchronous redirect path, but it suppresses page suggestions for
        // long or low-overlap 404 URLs. A reader is waiting on this result,
        // so prioritize recall and let the full Levenshtein fallback produce
        // the best available suggestions.
        $spellChecker->setSkipNgramGate4(true);

        $suggestOpts = ABJ_404_Solution_SuggestionDisplayOptions::fromOptionsArray($options);
        $suggestionsPacket = $spellChecker->findMatchingPosts(
            $urlSlugOnly,
            $suggestOpts->getSuggestCatsString(),
            $suggestOpts->getSuggestTagsString()
        );
        return is_array($suggestionsPacket) ? array_values($suggestionsPacket) : array();
    }

    /**
     * @param ABJ_404_Solution_Logging $logger
     */
    private function logPublishOutcome(string $outcome, string $requestedURL, $logger): void {
        if ($outcome === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_STORED) {
            return;
        }
        if ($outcome === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_STALE) {
            $logger->debugMessage('Skipped stale suggestion result for ' .
                esc_html($requestedURL) . ' because state ownership changed.');
            return;
        }
        if ($outcome === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_BUSY) {
            $logger->warn('[SUGGESTION_RESULT_LOCK_UNAVAILABLE] Computation completed but another writer owns ' .
                esc_html($requestedURL) . '. Recovery: this reader was answered directly; the owning writer ' .
                'publishes the stored result.');
            return;
        }
        $logger->errorMessage('[SUGGESTION_RESULT_WRITE_FAILED] Computation completed but its result could not ' .
            'be stored for ' . esc_html($requestedURL) . '. Recovery: this reader was answered directly; ' .
            'the next page view computes again.');
    }

    /**
     * Shutdown handler to detect fatal errors during computation.
     * Updates the job to 'error' so the page's next poll stops waiting.
     *
     * - Only acts on fatal error types; a normal shutdown is a no-op.
     * - A later successful claim overwrites the marker with 'complete'.
     * - register_shutdown_function() is additive, so ErrorHandler's own
     *   fatal handler still runs.
     *
     * @param array{transientKey: string, token: string, requestedURL: string, workerStartedAt: int|null} $request
     * @param array{type: int, message: string, file: string, line: int}|null $error Provided by tests; PHP's last error otherwise.
     * @return void
     */
    public static function handleComputationCrash(array $request, $error = null): void {
        if ($error === null) {
            $error = error_get_last();
        }

        // E_USER_ERROR and E_RECOVERABLE_ERROR are fatal in many environments.
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (!$error || !($error['type'] & $fatalTypes)) {
            return;
        }

        $outcome = self::crashStateStore()->publishCrashMarker(array(
            'transientKey' => $request['transientKey'],
            'normalizedURL' => ABJ_404_Solution_SuggestionTransient::normalizedUrl($request['requestedURL']),
            'token' => $request['token'],
            'workerStartedAt' => $request['workerStartedAt'],
        ));
        if ($outcome === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_BUSY) {
            abj404_logPhpFallback('suggestion-crash-marker-lock-unavailable',
                '[SUGGESTION_CRASH_MARKER_LOCK_UNAVAILABLE] Could not lock the crash marker for ' .
                $request['transientKey'] . '. Recovery: another writer owns the current state.');
        } elseif ($outcome === ABJ_404_Solution_SuggestionWorkerStateStore::OUTCOME_WRITE_FAILED) {
            abj404_logPhpFallback('suggestion-crash-marker-write-failed',
                '[SUGGESTION_CRASH_MARKER_WRITE_FAILED] Could not store the crash marker for ' .
                $request['transientKey'] . '. Recovery: inspect the preceding PHP fatal error and retry the request.');
        }

        // Full detail goes to the log only; the public poll response never
        // carries paths or messages.
        $logMessage = sprintf(
            "Suggestion computation crashed for URL '%s' (transient: %s): %s in %s on line %d",
            $request['requestedURL'],
            $request['transientKey'],
            $error['message'],
            basename($error['file']),
            $error['line']
        );

        if (class_exists('ABJ_404_Solution_Logging')) {
            try {
                abj_service('logging')->errorMessage($logMessage);
            } catch (Exception $e) {
                abj404_logPhpFallback('fatal-handler-fallback', $logMessage . ' (logger failed: ' . $e->getMessage() . ')');
            }
        } else {
            abj404_logPhpFallback('fatal-handler-fallback', $logMessage);
        }
    }

    private function stateStore(): ABJ_404_Solution_SuggestionWorkerStateStore {
        return new ABJ_404_Solution_SuggestionWorkerStateStore(abj_service('sync_utils'), $this->clock);
    }

    /** The crash handler runs at shutdown without an instance. */
    private static function crashStateStore(): ABJ_404_Solution_SuggestionWorkerStateStore {
        $clock = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('clock') : null;
        return new ABJ_404_Solution_SuggestionWorkerStateStore(
            abj_service('sync_utils'),
            $clock instanceof ABJ_404_Solution_Clock ? $clock : new ABJ_404_Solution_SystemClock()
        );
    }
}
