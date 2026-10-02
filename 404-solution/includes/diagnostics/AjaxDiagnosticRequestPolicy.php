<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-closed opt-in policy for durable AJAX diagnostics.
 *
 * Request identity remains owned by AjaxRequestLedger. This collaborator
 * owns the separate decision of whether a request may activate expensive
 * journals and tracer callbacks through the existing debug setting.
 */
final class ABJ_404_Solution_AjaxDiagnosticRequestPolicy {

    /**
     * The one AJAX action whose per-boundary checkpoints are collected. The
     * admin table endpoint is what the timeout investigation is about; the
     * other handlers sharing the AJAX plumbing must not pay the file-write
     * overhead.
     */
    const INSTRUMENTED_ACTION = 'ajaxUpdatePaginationLinks';

    /**
     * Actions whose boot-phase lifecycle is checkpointed: the table endpoint
     * and the canary ladder that repeats its boot, auth, and dispatch path to
     * isolate transient host causes. Every other admin AJAX action and every
     * ordinary front-end request pays zero write cost for this.
     */
    const BOOT_WAYPOINT_ACTIONS = array(
        'ajaxUpdatePaginationLinks' => true,
        'ajaxRunCanaryStep' => true,
    );

    /**
     * Actions whose durable stage trace and operation tracers are armed once
     * the debug setting opts in. A superset of AjaxRequestLedger's
     * BOOT_WAYPOINT_ACTIONS, and deliberately separate from it: a boot
     * waypoint is written before the handler is even known, while these are
     * armed inside
     * ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext(),
     * which all three of these endpoints route through.
     *
     * ajaxRefreshHealthBar belongs here because the foreground status-count
     * work it triggers is one of the things the stall investigation is about;
     * omitting it left that whole record family unattributed on the endpoint
     * that most often performs it. There is no cost argument for leaving it
     * out: with the debug setting off every action here is inert anyway, and
     * with it on this is precisely the evidence the maintainer turned it on
     * to get.
     */
    const DIAGNOSTIC_TRACE_ACTIONS = array(
        'ajaxUpdatePaginationLinks' => true,
        'ajaxRunCanaryStep' => true,
        'ajaxRefreshHealthBar' => true,
    );

    /**
     * Actions that arm their own durable trace whether or not the debug
     * setting is on, because ASKING FOR THEM IS THE OPT-IN.
     *
     * `ajaxRunCanaryStep` is the whole of this set. The ladder is not ambient
     * cost: the browser fires it only after a real table request has already
     * failed, at most once an hour per browser
     * (view_updater_canary_cooldown.js), and every one of those requests is
     * already writing full checkpoint records through the ungated
     * ABJ_404_Solution_AjaxCheckpointLogger. Leaving the trace behind the
     * setting bought nothing and cost the server half of the evidence: support
     * report 2026-08-27 (Azure App Service, plugin 4.3.4) carried fifteen
     * canary client receipts in the checkpoint journal and a completely empty
     * `ajax_stage_trace` channel from the same directory, so the ladder's own
     * run could not even be scoped to the browser session that produced it.
     *
     * Deliberately NOT extended to `ajaxUpdatePaginationLinks` or
     * `ajaxRefreshHealthBar`: those run on every admin table load, which is the
     * 726 ms-against-136 ms cost the 4.3.3 gate exists to keep off a site that
     * did not ask for it.
     */
    const SELF_ARMING_TRACE_ACTIONS = array(
        'ajaxRunCanaryStep' => true,
    );

    /**
     * How long a client-reported parse failure keeps a traced action's durable
     * trace armed with the debug setting off. Support report 521 (LiteSpeed /
     * GoDaddy, 4.3.5): two ajaxUpdatePaginationLinks attempts came back HTTP
     * 200 with a 65-byte non-JSON body and the server side held nothing,
     * because with debug_mode off a traced-but-not-self-arming action writes
     * no trace and drops the client report riding the very same requests. A
     * parse failure is the same kind of opt-in the canary ladder has (the
     * browser only reports one after a real request already failed), but it
     * must stay bounded: a short window after the report, never a standing
     * per-request cost on a site that never asked for tracing.
     */
    const CLIENT_PARSE_FAILURE_TRACE_TTL_SECONDS = 1800;

    /**
     * Arm a traced action's durable trace for a bounded window, regardless of
     * the debug setting, because the browser just reported a parse failure on
     * that action.
     *
     * Idempotent by construction: re-arming refreshes the same transient at
     * the same bound. Returns whether the action is (now) one this policy
     * will trace, so the caller can journal an accurate outcome without
     * duplicating the traced-action set.
     */
    public static function armTraceForClientParseFailure(string $action): bool {
        if (!isset(self::DIAGNOSTIC_TRACE_ACTIONS[$action]) || !function_exists('set_transient')) {
            return false;
        }
        set_transient(self::armedTransientKey($action), 1,
            self::CLIENT_PARSE_FAILURE_TRACE_TTL_SECONDS);
        // Read back rather than trusting set_transient's own return: it can
        // report false for a value that is already set, and the caller
        // journals this verdict as evidence. What matters is whether the
        // window is actually open, which is the same read every traced
        // request will make.
        return self::traceArmedByClientParseFailure($action);
    }

    /** @param string $action */
    private static function armedTransientKey(string $action): string {
        return 'abj404_client_parse_failure_trace_' . $action;
    }

    /** Whether a client parse-failure report armed this action inside its window. */
    private static function traceArmedByClientParseFailure(string $action): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        return get_transient(self::armedTransientKey($action)) !== false;
    }

    /** Whether the stored debug setting explicitly enables diagnostics. */
    public static function isEnabled(): bool {
        if (!function_exists('abj404_get_settings_options')) {
            return false;
        }
        $options = abj404_get_settings_options();
        $value = $options['debug_mode'] ?? null;
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Request ID for table-only micro-boundaries, or an inert empty ID.
     *
     * @param array<array-key, mixed> $context
     */
    public static function instrumentedRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if ($action !== self::INSTRUMENTED_ACTION
                || (!self::isEnabled() && !self::isAuthorizedRetry($context))) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($context['request_id'] ?? null);
    }

    /**
     * Request ID for a durable stage trace on one of the admin AJAX endpoints
     * that opt into tracing, or an inert empty ID.
     *
     * @param array<array-key, mixed> $context
     */
    public static function diagnosticRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if (!isset(self::DIAGNOSTIC_TRACE_ACTIONS[$action])) {
            return '';
        }
        if (!isset(self::SELF_ARMING_TRACE_ACTIONS[$action])
                && !self::isEnabled() && !self::isAuthorizedRetry($context)
                && !self::traceArmedByClientParseFailure($action)) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($context['request_id'] ?? null);
    }

    /** diagnosticRequestId() against the shared AJAX debug context global. */
    public static function diagnosticRequestIdFromGlobalContext(): string {
        $context = $GLOBALS['abj404_ajax_context'] ?? null;
        return is_array($context) ? self::diagnosticRequestId($context) : '';
    }

    /** instrumentedRequestId() against the shared AJAX debug context global. */
    public static function instrumentedRequestIdFromGlobalContext(): string {
        $context = $GLOBALS['abj404_ajax_context'] ?? null;
        return is_array($context) ? self::instrumentedRequestId($context) : '';
    }

    /**
     * Whether this site's durable trace writer is armed right now, and on what.
     *
     * Composed for the support-collection manifest, which otherwise cannot tell
     * "the stage-trace channel wrote nothing because nothing went wrong" from
     * "the stage-trace channel wrote nothing because it was never switched on".
     * Those are the same three empty files on disk and completely different
     * findings, and the Azure capture spent a whole report on the difference.
     *
     * Names only fixed action strings and one boolean read of an existing
     * setting: no request data, no site identity.
     *
     * `client_armed_actions` exists for the same reason as the rest of this
     * method: without it, a payload from a site with the debug setting off
     * reports no trace could exist while a client-reported parse failure had
     * one armed, and an empty trace channel again reads as "nothing went
     * wrong" rather than "nothing wrote".
     *
     * @return array{debug_mode_enabled: bool, traced_actions: array<int, string>,
     *   self_arming_actions: array<int, string>, client_armed_actions: array<int, string>}
     */
    public static function armingState(): array {
        $clientArmed = array();
        foreach (array_keys(self::DIAGNOSTIC_TRACE_ACTIONS) as $action) {
            if (self::traceArmedByClientParseFailure((string)$action)) {
                $clientArmed[] = (string)$action;
            }
        }
        return array(
            'debug_mode_enabled' => self::isEnabled(),
            'traced_actions' => array_keys(self::DIAGNOSTIC_TRACE_ACTIONS),
            'self_arming_actions' => array_keys(self::SELF_ARMING_TRACE_ACTIONS),
            'client_armed_actions' => $clientArmed,
        );
    }

    /**
     * Request ID for an early boot waypoint, or an inert empty ID.
     *
     * Boot waypoints run before handler parsing, so this boundary reads the
     * raw WordPress request only after proving it is an AJAX request and the
     * debug setting explicitly opts into diagnostics.
     */
    public static function bootWaypointRequestId(): string {
        if (!self::isEnabled() || !function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return '';
        }
        $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
            ? (string)$_REQUEST['action'] : '';
        if (!isset(self::BOOT_WAYPOINT_ACTIONS[$action])) {
            return '';
        }
        $rawId = $_REQUEST['requestId'] ?? '';
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId(is_scalar($rawId) ? $rawId : '');
    }

    /**
     * Whether the table handler has proved this is an authenticated retry.
     *
     * The authorization marker is deliberately internal: retryCount comes
     * from an untrusted request and must never arm pre-authorization writes.
     * The handler adds the marker only after nonce and plugin-admin checks.
     * Requiring both fields makes accidental reuse on another endpoint inert.
     *
     * @param array<array-key, mixed> $context
     */
    private static function isAuthorizedRetry(array $context): bool {
        if (($context['diagnostic_retry_authorized'] ?? null) !== true) {
            return false;
        }
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        $retryCount = $context['retry_count'] ?? null;
        $retryCountInt = ABJ_404_Solution_ExactInteger::read($retryCount, 1);
        return $action === self::INSTRUMENTED_ACTION
            && $retryCountInt !== null
            && $retryCountInt <= 2;
    }
}
