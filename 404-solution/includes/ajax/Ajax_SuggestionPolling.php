<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for the 404 page's suggestion polling.
 *
 * Called by `SuggestionPolling.js` on the public-facing 404 page
 * (`#abj404-suggestions-placeholder`) so anonymous visitors landing on a
 * 404 see the "did you mean" list without the page render waiting on it.
 * The first poll for a job the page opened runs that job in this request
 * (SuggestionComputeJob) and answers with the result; later polls read the
 * stored state. Compute therefore happens only when a browser runs the page
 * script, never for a client that merely fetched the 404 URL.
 *
 * SECURITY CONTRACT: anonymous-by-design.
 *
 *   This endpoint is registered for BOTH `wp_ajax_*` and
 *   `wp_ajax_nopriv_*` actions (see `WordPressHookRegistrar::registerAsyncSuggestionHooks`).
 *   There is intentionally no
 *   `userIsPluginAdmin()` / `current_user_can()` check: the public 404
 *   page is the entire point of the polling endpoint, and a capability
 *   gate would break it for every anonymous visitor (i.e. most visitors).
 *
 *   Abuse prevention relies on three layered defences instead of a
 *   capability gate:
 *
 *     1. `check_ajax_referer('abj404_poll_suggestions', '_ajax_nonce')`:
 *        the page emitting the polling JS also emits the nonce, so a
 *        scripted abuse attempt has to first fetch the 404 page.
 *     2. The shared per-actor (user-id or IP) rate limiter via
 *        `Ajax_Php::consumeRateLimit('poll_suggestions', 120, 60)`.
 *     3. A poll only computes for a URL whose 404 page was actually
 *        rendered (the page opens the pending job; a poll never does),
 *        and a second per-actor limiter,
 *        `consumeRateLimit('compute_suggestions', 30, 60)`, caps how many
 *        computations one source can start.
 *     4. The response only carries status constants plus rendered
 *        suggestion HTML for the requested URL: the worst-case information
 *        leak is "which URLs on this site have had a stored suggestion
 *        computation", which is bounded.
 *
 *   This contract is pinned by `AjaxSuggestionPollingAnonymousByDesignTest`
 *   (structural assertion on the `_nopriv_` registration + behavioural
 *   assertion that an anonymous caller reaches the data-read step).
 *
 * Security audit history: a V13 audit (commit deb6e7d5) flagged
 * "nonce but no capability check" against this handler. Triage confirmed
 * the anonymous-by-design contract above; do not re-file.
 */
class ABJ_404_Solution_Ajax_SuggestionPolling {

    /**
     * Per-actor limit on polls that run the spelling engine. Generous for a
     * reader opening a few dozen broken links a minute, but caps the total
     * computations any one source can start.
     */
    const COMPUTE_RATE_LIMIT_MAX_REQUESTS = 30;
    const COMPUTE_RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Resolve the time source. Tests bind a `FrozenClock` via the
     * service container so the worker-stuck-at-90s threshold can be
     * asserted exactly. When no container is bound the
     * fallback is the production `SystemClock`.
     *
     * @return ABJ_404_Solution_Clock
     */
    private static function clock(): ABJ_404_Solution_Clock {
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $svc = ABJ_404_Solution_ServiceContainer::safeGet('clock');
            if ($svc instanceof ABJ_404_Solution_Clock) {
                return $svc;
            }
        }
        return new ABJ_404_Solution_SystemClock();
    }

    /**
     * Check if suggestions are ready and return them if complete.
     * Returns JSON with status and optionally HTML content.
     * @return void
     */
    public static function pollSuggestions(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-suggestion-polling')) {
            return;
        }

        // Verify nonce for CSRF protection
        if (!check_ajax_referer('abj404_poll_suggestions', '_ajax_nonce', false)) {
            wp_send_json(array('status' => 'error', 'message' => 'Security check failed'), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Rate limit polling to avoid admin-ajax.php abuse on high-traffic 404 pages.
        // Uses the same transient-based limiter as other AJAX endpoints (user ID or IP).
        if (class_exists('ABJ_404_Solution_Ajax_Php') &&
            ABJ_404_Solution_Ajax_Php::consumeRateLimit('poll_suggestions', 120, 60)) {
            wp_send_json(array('status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.'), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Sanitize input
        if (isset($_POST['url'])) {
            $rawUrl = function_exists('wp_unslash') ? wp_unslash($_POST['url']) : $_POST['url'];
            $requestedURL = abj_service('sanitizer')->normalizeUrlString($rawUrl);
        } else {
            $requestedURL = '';
        }

        if (empty($requestedURL)) {
            wp_send_json(array('status' => 'error', 'message' => 'Missing URL parameter'), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Normalize URL using centralized function for consistency
        $normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($requestedURL);
        $transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);

        // Check transient for status. Normalize at the boundary: any
        // raw-shape probing (status string check, started/created int
        // coercion) lives in SuggestionTransient::fromRaw, not here.
        $dataRaw = get_transient($transientKey);

        if ($dataRaw === false) {
            // Transient not found, computation may not have started
            wp_send_json(array('status' => 'not_found'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        $transient = ABJ_404_Solution_SuggestionTransient::fromRaw($dataRaw);
        if ($transient === null) {
            wp_send_json(array('status' => 'error', 'message' => 'Invalid transient data'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($transient->isPending()) {
            // Worker-recovery semantics live on the VO so each consumer doesn't
            // re-derive the window. Matches SuggestionWorkerStateStore's claim
            // window (the producer side of the same contract).
            $now = self::clock()->now();
            if ($transient->isWorkerStuck($now)) {
                // The browser is told "timeout"; the server must remember it too.
                // A worker killed without an in-request PHP fatal (FPM timeout,
                // process kill) never reaches SuggestionComputeJob::handleComputationCrash,
                // so this is the only place the hang is visible. The transient key
                // is a hash of the URL; the URL itself is not logged (public visitor input).
                $logger = abj_service('logging');
                if (is_object($logger) && method_exists($logger, 'warn')) {
                    $logger->warn('Suggestion computation reported as timed out: a worker claimed the job '
                        . ($now - $transient->getStartedAt()) . 's ago and never finished (stuck window '
                        . ABJ_404_Solution_SuggestionTransient::WORKER_STUCK_SECONDS . 's); transient '
                        . $transientKey . '.');
                }
                // Return 200 so the JS success handler catches it immediately (not error retry loop)
                wp_send_json(array('status' => 'timeout', 'message' => 'Computation timed out'));
                return; // @phpstan-ignore deadCode.unreachable
            }

            if (!$transient->isClaimed()) {
                // First poll from a rendered 404 page: a browser is running
                // the page script and waiting, so this is the request that
                // pays for the compute.
                self::runPendingJob($transient, $requestedURL, $normalizedURL, $transientKey);
                return;
            }

            // Another poll is computing; keep waiting.
            wp_send_json(array('status' => 'pending'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($transient->isError()) {
            // Computation crashed. No underlying detail is available to surface
            // here by design: this endpoint is hit by public 404-page visitors
            // (not authenticated admins), and the producer's shutdown handler
            // (SuggestionComputeJob::handleComputationCrash) deliberately
            // strips the PHP fatal-error message, file path, and line number
            // from the transient payload to avoid leaking implementation details
            // (paths, plugin internals) to the public web. The full crash detail
            // is recorded server-side via the plugin logger; site admins should
            // consult the plugin log for diagnosis.
            wp_send_json(array('status' => 'error', 'message' => 'Suggestion computation failed'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Suggestions ready (isComplete by elimination, status enum is closed).
        // Use normalized URL to match how ShortCode processes URLs.
        $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML(
            $transient->getSuggestionsPacket(),
            $normalizedURL
        );
        wp_send_json(array('status' => 'complete', 'html' => $html));
    }

    /**
     * Run an unclaimed job in this request and answer with its result.
     *
     * The compute rate limit is per actor (user id or IP), so one client
     * cannot turn polls for many distinct rendered 404 pages into unbounded
     * spelling-engine runs. The job's pending transient is the proof that a
     * 404 page was actually rendered for this URL; a poll alone never opens
     * a job.
     */
    private static function runPendingJob(
        ABJ_404_Solution_SuggestionTransient $transient,
        string $requestedURL,
        string $normalizedURL,
        string $transientKey
    ): void {
        if (class_exists('ABJ_404_Solution_Ajax_Php') &&
                ABJ_404_Solution_Ajax_Php::consumeRateLimit(
                    'compute_suggestions',
                    self::COMPUTE_RATE_LIMIT_MAX_REQUESTS,
                    self::COMPUTE_RATE_LIMIT_WINDOW_SECONDS
                )) {
            wp_send_json(array('status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.'), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $job = new ABJ_404_Solution_SuggestionComputeJob(self::clock());
        $result = $job->run(array(
            'transientKey' => $transientKey,
            'normalizedURL' => $normalizedURL,
            'requestedURL' => $requestedURL,
            'token' => $transient->getToken(),
        ));

        if ($result['outcome'] !== ABJ_404_Solution_SuggestionComputeJob::OUTCOME_COMPUTED) {
            // Another request owns the job, or the claim could not be stored;
            // either way the next poll sees the current state.
            wp_send_json(array('status' => 'pending'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML($result['suggestionsPacket'], $normalizedURL);
        wp_send_json(array('status' => 'complete', 'html' => $html));
    }
}
