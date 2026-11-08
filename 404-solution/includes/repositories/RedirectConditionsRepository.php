<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data access for conditional redirect rows.
 *
 * Keeps conditions-table existence checks, row validation, and persistence
 * isolated from redirect row lookup and write orchestration.
 */
class ABJ_404_Solution_RedirectConditionsRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_TableReadinessGate */
    private $readiness;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $logging = null) {
        $this->dbCore = $dbCore;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->readiness = new ABJ_404_Solution_TableReadinessGate(
            $dbCore,
            $dbCore->tableNameResolver()
        );
    }

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array {
        $result = $this->readRedirectConditions($redirectId);
        if (($result['query_succeeded'] ?? null) !== true) {
            return [];
        }

        return $result['rows'];
    }

    /**
     * Read condition rows while retaining the acknowledgement needed by the
     * routing evaluator. The rows-only method above remains the display/API
     * contract; an empty array from that method must not authorize a redirect.
     *
     * @param int $redirectId
     * @return array{rows: array<int, array<string, mixed>>, last_error: string,
     *     query_succeeded: bool, timed_out: bool, table_available: bool}
     */
    public function readRedirectConditions(int $redirectId): array {
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirect_conditions}')) {
            return array(
                'rows' => array(),
                'last_error' => 'The redirect conditions table is unavailable.',
                'query_succeeded' => false,
                'timed_out' => false,
                'table_available' => false,
            );
        }

        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id, redirect_id, logic, condition_type, operator, value, sort_order
             FROM `{$table}`
             WHERE redirect_id = %d
             ORDER BY sort_order ASC, id ASC",
            array('query_params' => array($redirectId), 'log_errors' => false)
        );

        $normalized = $this->normalizeConditionReadResult($result);
        if (($normalized['query_succeeded'] ?? null) !== true
            && ($normalized['table_available'] ?? true) !== false) {
            $this->logger->warn(
                "getRedirectConditions: DB error for redirect_id={$redirectId}: " .
                (string)($normalized['last_error'] ?? 'The conditions read failed.')
            );
        }
        return $normalized;
    }

    /**
     * @param mixed $result
     * @return array{rows: array<int, array<string, mixed>>, last_error: string,
     *     query_succeeded: bool, timed_out: bool, table_available: bool}
     */
    private function normalizeConditionReadResult($result): array {
        $failure = static function (string $error, bool $timedOut = false): array {
            return array(
                'rows' => array(),
                'last_error' => $error,
                'query_succeeded' => false,
                'timed_out' => $timedOut,
                'table_available' => true,
            );
        };

        if (!is_array($result)) {
            return $failure('The redirect conditions read returned an invalid result envelope.');
        }

        if (!array_key_exists('last_error', $result) || !is_string($result['last_error'])) {
            return $failure('The redirect conditions read returned malformed error metadata.');
        }
        $lastError = $result['last_error'];
        if ($lastError !== '') {
            return $failure($lastError);
        }

        if (array_key_exists('timed_out', $result) && !is_bool($result['timed_out'])) {
            return $failure('The redirect conditions read returned malformed timeout metadata.');
        }
        if (($result['timed_out'] ?? false) === true) {
            return $failure('The redirect conditions read timed out.', true);
        }

        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            return $failure('The redirect conditions read failed without a database error.');
        }
        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            return $failure('The redirect conditions read returned malformed rows.');
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) {
                return $failure('The redirect conditions read returned malformed row data.');
            }
            $rows[] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return array(
            'rows' => $rows,
            'last_error' => '',
            'query_succeeded' => true,
            'timed_out' => false,
            'table_available' => true,
        );
    }

    /**
     * Replace the full condition set for a redirect: the prior rows are
     * deleted and the new set inserted inside a single database transaction
     * (routed through DatabaseTransactionExecutor via
     * dbCore->executeAsTransaction()) so a DB failure partway through
     * (the DELETE, or any one INSERT) rolls back the whole replacement
     * instead of leaving stale, missing, or partial condition rows while
     * reporting success. An empty $conditions array still runs the DELETE
     * inside the same transaction, so a failed delete-only replacement is
     * now surfaced too (previously it returned success unconditionally).
     *
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return string Error message on failure ('' on success).
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): string {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        if ($this->readiness->isKnownAbsent('{wp_abj404_redirect_conditions}')) {
            $this->logger->warn("saveRedirectConditions: conditions table missing, skipping save for redirect_id={$redirectId}.");
            return __('The redirect conditions table is unavailable; conditions were not saved. [ABJ404_CONDITIONS_TABLE_UNAVAILABLE]', '404-solution')
                . ' ' . __('Reload this page and try again. If the error continues, send this message to support.', '404-solution');
        }

        global $wpdb;
        $statements = array();
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; the built statements execute together through dbCore->executeAsTransaction().
        $statements[] = $wpdb->prepare("DELETE FROM `{$table}` WHERE redirect_id = %d", $redirectId);

        foreach ($conditions as $index => $cond) {
            $normalized = $this->normalizeConditionForInsert($cond, $index);
            if ($normalized === null) {
                continue;
            }

            // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; the built statements execute together through dbCore->executeAsTransaction().
            $statements[] = $wpdb->prepare(
                "INSERT INTO `{$table}` (`redirect_id`, `logic`, `condition_type`, `operator`, `value`, `sort_order`)
                 VALUES (%d, %s, %s, %s, %s, %d)",
                $redirectId,
                $normalized['logic'],
                $normalized['type'],
                $normalized['operator'],
                $normalized['value'],
                $normalized['sort_order']
            );
        }

        try {
            // executeAsTransaction() (DatabaseTransactionExecutor) already logs
            // a genuine (non-infrastructure) SQL error via errorMessage() and
            // handles infrastructure errors (disk full / read-only) as a
            // degrade-gracefully warning -- no redundant logging needed here.
            $this->dbCore->executeAsTransaction($statements);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            return $message !== '' ? $message : 'saveRedirectConditions: transaction failed';
        }

        // Conditions decide who this redirect answers, so the cached response
        // for its source is stale.
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->dbCore, abj_service('functions'));
        ABJ_404_Solution_RedirectPageCachePurge::queueRead($lookup->readRedirectsByIDs(array($redirectId)));

        return '';
    }

    /**
     * @param mixed $cond
     * @return array{logic: string, type: string, operator: string, value: string, sort_order: int}|null
     */
    private function normalizeConditionForInsert($cond, int $index) {
        if (!is_array($cond)) {
            return null;
        }

        $logic = isset($cond['logic']) && is_string($cond['logic'])
            ? strtoupper(trim($cond['logic'])) : 'AND';
        if (!in_array($logic, ['AND', 'OR'], true)) {
            $logic = 'AND';
        }

        $type = isset($cond['condition_type']) && is_string($cond['condition_type'])
            ? trim($cond['condition_type']) : '';
        if (!in_array($type, $this->allowedConditionTypes(), true)) {
            $this->logger->warn("saveRedirectConditions: unknown condition_type '{$type}', skipping.");
            return null;
        }

        $operator = isset($cond['operator']) && is_string($cond['operator'])
            ? trim($cond['operator']) : 'equals';
        if (!in_array($operator, $this->allowedOperators(), true)) {
            $operator = 'equals';
        }

        $value = isset($cond['value']) && is_string($cond['value'])
            ? trim($cond['value']) : '';
        if (strlen($value) > 1024) {
            $value = substr($value, 0, 1024);
        }

        $sortOrder = isset($cond['sort_order'])
            ? absint(is_scalar($cond['sort_order']) ? $cond['sort_order'] : 0)
            : $index;

        return array(
            'logic' => $logic,
            'type' => $type,
            'operator' => $operator,
            'value' => $value,
            'sort_order' => $sortOrder,
        );
    }

    /** @return array<int, string> */
    private function allowedConditionTypes(): array {
        return [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
    }

    /** @return array<int, string> */
    private function allowedOperators(): array {
        return [
            'equals', 'contains', 'regex',
            'not_equals', 'not_contains', 'cidr',
        ];
    }

}
