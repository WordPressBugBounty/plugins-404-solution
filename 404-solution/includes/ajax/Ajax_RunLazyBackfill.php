<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint that advances lazy redirect-table backfills from a browser request.
 */
class ABJ_404_Solution_Ajax_RunLazyBackfill {

    public const NONCE_ACTION = 'abj404_runLazyBackfill';

    /**
     * Log canonical work receives at most two seconds inside one shared
     * five-second work budget. The remaining time goes to the redirect pass,
     * which publishes canonical URLs with the other derived fields.
     * Short cooperative slices keep maintenance from monopolizing the shared
     * database while the user opens another admin page. Persisted cursors let
     * the next browser poll resume; a chunk already admitted may finish after
     * its deadline. Daily cron retains its normal per-component budgets.
     */
    private const BROWSER_PASS_BUDGET_SEC = 2.0;
    private const BROWSER_REQUEST_BUDGET_SEC = 5.0;

    /** @return void */
    public function handle(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-run-lazy-backfill')) {
            return;
        }

        $requestReader = ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader();
        $subpage = $requestReader->getPostOrGetSanitize('subpage', '');
        $context = array(
            'action' => 'abj404_run_lazy_backfill',
            'subpage' => $subpage,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext(
            $context,
            'Ajax_RunLazyBackfill::handle'
        );
        $isPluginAdmin = false;

        try {
            if (!ABJ_404_Solution_AjaxAdminEndpointSupport::requireAdminWithNonceOrRespond(
                self::NONCE_ACTION,
                $context,
                'abj404_run_lazy_backfill'
            )) {
                return;
            }
            $isPluginAdmin = true;

            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('run_lazy_backfill', 120, 60)) {
                ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure(
                    'AJAX rate limit in abj404_run_lazy_backfill.',
                    $context
                );
                ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_AjaxErrorEnvelope::build(
                    'Rate limit exceeded. Please try again later.',
                    null,
                    false
                );
                ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 429);
                return;
            }

            if (!$this->runBackfillPass($context)) {
                ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_AjaxErrorEnvelope::build(
                    'Server error while running lazy backfill.',
                    array('context' => $context, 'reason' => 'database_upgrades service unavailable'),
                    $isPluginAdmin
                );
                ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 500);
                return;
            }

            $response = array(
                'sorts' => array(
                    'url' => $this->sortStatus('url'),
                    'dest' => $this->sortStatus('dest'),
                ),
            );
            // Sort controls can become usable before the larger log backlog
            // finishes. Keep those states separate so the browser continues
            // maintenance without keeping an already-ready sort unavailable.
            $response['maintenance'] = array('complete' => $this->maintenanceComplete($response['sorts']));

            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit(
                array('success' => true, 'data' => $response),
                200
            );
        } catch (\Throwable $e) {
            $isPluginAdmin = ABJ_404_Solution_AjaxAdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin);
            $details = array(
                'exception' => array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ),
                'context' => $context,
            );
            ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure(
                'AJAX exception in abj404_run_lazy_backfill.',
                $details,
                $e
            );
            $capturedOutput = ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            $payload = ABJ_404_Solution_AjaxErrorEnvelope::build(
                'Server error while running lazy backfill.',
                $details,
                $isPluginAdmin
            );
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 500);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runBackfillPass(array $context): bool {
        $upgrades = function_exists('abj_service_optional') ? abj_service_optional('database_upgrades') : null;
        if (!is_object($upgrades) || !method_exists($upgrades, 'components')) {
            ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure(
                'Lazy backfill skipped because database_upgrades service is unavailable.',
                $context
            );
            return false;
        }

        $components = $upgrades->components();
        $deadline = abj_clock()->nowFloat() + self::BROWSER_REQUEST_BUDGET_SEC;
        if (is_object($components) && method_exists($components, 'canonicalUrlBackfillUpgrade')) {
            $canonical = $components->canonicalUrlBackfillUpgrade();
            if (is_object($canonical) && method_exists($canonical, 'backfillLogsv2CanonicalUrl')) {
                $canonicalBudget = min(self::BROWSER_PASS_BUDGET_SEC, max(0.0, $deadline - abj_clock()->nowFloat()));
                $canonical->backfillLogsv2CanonicalUrl($canonicalBudget);
            }
        }
        if (is_object($components) && method_exists($components, 'redirectsDenormBackfillUpgrade')) {
            $denorm = $components->redirectsDenormBackfillUpgrade();
            if (is_object($denorm) && method_exists($denorm, 'runDeferredDenormBackfillPass')) {
                $remainingBudget = max(0.0, $deadline - abj_clock()->nowFloat());
                $denorm->runDeferredDenormBackfillPass($remainingBudget);
            }
        }

        return true;
    }

    /** @param array<string, array{ready:bool,percent:int,status:string,message:string}> $sorts */
    private function maintenanceComplete(array $sorts): bool {
        if (!$sorts['url']['ready'] || !$sorts['dest']['ready']) {
            return false;
        }
        $dbCore = function_exists('abj_service_optional') ? abj_service_optional('db_core') : null;
        if (!$dbCore instanceof ABJ_404_Solution_DatabaseCore) {
            return false;
        }
        // A fresh reader avoids reusing a pre-drain request cache. Its shared
        // completion contract checks both the latch and actual NULL rows.
        $canonical = new ABJ_404_Solution_LogsHitsCanonicalUrlJoinHelper($dbCore);
        return $canonical->isRedirectsCanonicalUrlBackfillComplete()
            && $canonical->isLogsv2CanonicalUrlBackfillComplete();
    }

    /**
     * @return array{ready: bool, percent: int, status: string, message: string}
     */
    private function sortStatus(string $orderby): array {
        $viewRead = function_exists('abj_service_optional') ? abj_service_optional('view_read_service') : null;
        if (!is_object($viewRead)
            || !method_exists($viewRead, 'sortReadinessStatusForOrderby')
            || !method_exists($viewRead, 'sortBackfillPercentForOrderby')) {
            return array(
                'ready' => false,
                'percent' => 0,
                'status' => 'unavailable',
                'message' => '',
            );
        }

        $status = (string)$viewRead->sortReadinessStatusForOrderby($orderby);
        $ready = $status === ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY;
        $percent = $ready ? 100 : max(0, min(99, (int)$viewRead->sortBackfillPercentForOrderby($orderby)));
        $message = $ready ? '' : sprintf(
            __('Sorting by this column is being prepared for your number of URLs (%d%% complete). The list shows newest first until it is ready.', '404-solution'),
            $percent
        );

        return array(
            'ready' => $ready,
            'percent' => $percent,
            'status' => $status,
            'message' => $message,
        );
    }
}
