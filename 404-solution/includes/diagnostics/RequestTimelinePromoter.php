<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides which request timelines are worth keeping and promotes them into
 * the durable ledger (ABJ_404_Solution_StrandedRequestLedger).
 *
 * A healthy request leaves nothing behind, so promotion is the whole reason a
 * later report can show what an ordinary-looking request did: a finished
 * request is promoted when it was slow, started late against its client's send
 * time, or was a retry, and a request named by a retry or a client beacon is
 * promoted with the reason it was named.
 *
 * Callers own the authorization question. Every field a timeline is keyed on
 * (request id, retry count, client send time) is request-supplied, so this
 * class must only ever be handed a request that already proved nonce and admin
 * access; the census enforces that before it calls in
 * (ABJ_404_Solution_SameSiteRequestCensus::markAuthorized()).
 *
 * Never throws: promotion is evidence gathering and must not affect a request.
 */
final class ABJ_404_Solution_RequestTimelinePromoter {

    /**
     * Request start to shutdown at or past this, and the finished request's
     * timeline is promoted into the durable ledger: slowness is the finding,
     * even when the request completed.
     */
    const SLOW_REQUEST_MS = 5000;

    /**
     * Request start minus client send time at or past this, and the timeline
     * is promoted: the gap is queueing or delivery before PHP, not work PHP
     * did.
     */
    const LATE_START_MS = 5000;

    /**
     * Promote one named request's timeline: a retry names its parent, a client
     * beacon names the attempt it reports on. The named row may be retired,
     * still in flight, or never seen; all three are recorded, because a
     * missing parent is itself a finding about where the attempt went.
     *
     * Keyed, not positional: both values are strings, and a swap would record
     * the reason as the request id.
     *
     * @param array{request_id: string, reason: string} $named `request_id` is the
     *   named request's ledger id; `reason` is one of the ledger's REASON_*
     *   constants.
     * @return void
     */
    public static function promoteNamed(array $named): void {
        try {
            $requestId = $named['request_id'];
            $reason = $named['reason'];
            if ($requestId === '' || $reason === '') {
                return;
            }
            $found = ABJ_404_Solution_SameSiteRequestRegistry::findTimeline($requestId);
            $timeline = ABJ_404_Solution_RequestPhaseTimeline::decode(
                ABJ_404_Solution_SameSiteRequestRowCodec::timelineOfValue($found['value']));
            ABJ_404_Solution_StrandedRequestLedger::recordTimeline(
                $timeline === null ? array('v' => 1, 'rid' => $requestId) : $timeline,
                array('reason' => $reason, 'state' => $found['state'])
            );
        } catch (Throwable $e) {
            self::reportFailure('request timeline promote named failed: ' . $e->getMessage());
        }
    }

    /**
     * Promote this request's own finished timeline when it is noteworthy: slow
     * start to shutdown, late start against the client send time, or a retry.
     *
     * @param int|null $elapsedMs request start to now, or null when the clock
     *   was unavailable (slowness cannot then be judged).
     * @return void
     */
    public static function promoteOwnIfNoteworthy(?int $elapsedMs): void {
        try {
            $timeline = ABJ_404_Solution_RequestPhaseTimeline::toArray();
            $rid = isset($timeline['rid']) && is_string($timeline['rid']) ? $timeline['rid'] : '';
            if ($rid === '') {
                return;
            }
            if ($elapsedMs !== null && $elapsedMs >= self::SLOW_REQUEST_MS) {
                self::record($timeline, ABJ_404_Solution_StrandedRequestLedger::REASON_SLOW);
            }
            $requestStart = $timeline['rt'] ?? null;
            $clientSent = $timeline['cs'] ?? null;
            if (is_int($requestStart) && is_int($clientSent)
                    && ($requestStart - $clientSent) >= self::LATE_START_MS) {
                self::record($timeline, ABJ_404_Solution_StrandedRequestLedger::REASON_LATE_START);
            }
            if (($timeline['rc'] ?? 0) > 0) {
                self::record($timeline, ABJ_404_Solution_StrandedRequestLedger::REASON_RETRY);
            }
        } catch (Throwable $e) {
            self::reportFailure('request timeline promote own failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $timeline
     * @param string $reason one of the ledger's REASON_* constants.
     * @return void
     */
    private static function record(array $timeline, string $reason): void {
        ABJ_404_Solution_StrandedRequestLedger::recordTimeline($timeline, array('reason' => $reason));
    }

    /**
     * @param string $message
     * @return void
     */
    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('request-timeline-promoter', $message);
        }
    }
}
