<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX adapter for log URL autocomplete.
 */
class ABJ_404_Solution_Ajax_ViewLogs {

    /** @return void */
    public static function echoViewLogsFor() {
        /** @var ABJ_404_Solution_LogsRepository $logsRepository */
        $logsRepository = ABJ_404_Solution_Ajax_ServiceResolver::required('logs_repository');
        /** @var ABJ_404_Solution_Functions $f */
        $f = ABJ_404_Solution_Ajax_ServiceResolver::required('functions');
        $feedback = new ABJ_404_Solution_Ajax_SearchFeedback();

        $authResult = abj_service('ajax_security_gate')->authorizeAdminWithNonce('abj404_ajax');
        if (!$authResult['ok']) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem($authResult['message']), 200);
            return;
        }

        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('view_logs', 100, 60)) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem(__('Rate limit exceeded. Please try again later.', '404-solution')), 200);
            return;
        }

        $term = $f->strtolower(ABJ_404_Solution_RequestInputNormalizer::readText(
            $_GET, array('name' => 'term')));
        $term = substr($term, 0, 100);

        self::sendJson(self::autocompletePayload($logsRepository, $feedback, $term), 200);
    }

    /**
     * The autocomplete items for a log URL term, or the single error item that
     * tells the admin why the lookup failed. A failed read is surfaced in the
     * dropdown with the failure's own message, never an empty list that would
     * read as "no matches". The query executor has already logged the failure.
     *
     * @param ABJ_404_Solution_LogsRepository $logsRepository
     * @param ABJ_404_Solution_Ajax_SearchFeedback $feedback
     * @param string $term
     * @return array<int, array<string, mixed>>
     */
    private static function autocompletePayload($logsRepository, $feedback, $term) {
        $suggestion = array();
        $suggestion['label'] = __('(Show All Logs)', '404-solution');
        $suggestion['category'] = 'Special';
        $suggestion['value'] = 0;
        $specialSuggestion = array();
        $specialSuggestion[] = $suggestion;

        try {
            $rows = $logsRepository->getLogsIDandURLLike(
                $term,
                ABJ404_MAX_AJAX_DROPDOWN_SIZE,
                ABJ_404_Solution_AdminTableResponseParts::queryBudgetOptions()
            );
        } catch (ABJ_404_Solution_ViewQueryFailureException $failure) {
            return ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem($failure->getMessage());
        }
        $results = self::formatLogResults($rows);
        $suggestions = $feedback->provideSearchFeedback($results, $term);
        return array_merge($specialSuggestion, $suggestions);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    public static function formatLogResults($rows) {
        $suggestions = array();

        foreach ($rows as $row) {
            $reqUrl = isset($row['requested_url']) ? $row['requested_url'] : '';
            $logsId = isset($row['logsid']) ? $row['logsid'] : '';
            $suggestion = array();
            $suggestion['label'] = is_scalar($reqUrl) ? (string)$reqUrl : '';
            $suggestion['category'] = 'Normal';
            $suggestion['value'] = is_scalar($logsId) ? (string)$logsId : '';

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param mixed $payload
     * @param int $status
     * @return void
     */
    private static function sendJson($payload, $status = 200) {
        ABJ_404_Solution_Ajax_Php::sendJson($payload, $status);
    }
}
