<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides when a database admin notice has been proven recovered.
 *
 * DatabaseSqlErrorReporter owns SQL diagnostics and repair dispatch. This
 * policy owns the cause-specific notice lifecycle after final repair hooks
 * have produced the result envelope. It depends on infrastructure state and
 * SQL command parsing, never on the reporter itself.
 */
class ABJ_404_Solution_DatabaseNoticeRecoveryPolicy {

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_DatabaseQueryTimeoutManager */
    private $queryTimeoutManager;

    /** @var callable(): bool */
    private $quotaCooldownChecker;

    /** @var array<string, string> Notice type to its recovery predicate. */
    private $recoveryStrategies = array(
        'disk_full' => 'hasRecoveredWrite',
        'read_only' => 'hasRecoveredWrite',
        'lock_timeout' => 'hasRecoveredStatement',
        'corrupted_temp_table' => 'hasRecoveredStatement',
        'query_quota' => 'hasRecoveredQuota',
        'collation' => 'isLegacyNoticeObsolete',
    );

    /**
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState
     * @param ABJ_404_Solution_DatabaseQueryTimeoutManager $queryTimeoutManager
     * @param callable(): bool $quotaCooldownChecker
     */
    public function __construct(
        ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState,
        ABJ_404_Solution_DatabaseQueryTimeoutManager $queryTimeoutManager,
        callable $quotaCooldownChecker
    ) {
        $this->noticeState = $noticeState;
        $this->queryTimeoutManager = $queryTimeoutManager;
        $this->quotaCooldownChecker = $quotaCooldownChecker;
    }

    /**
     * Observe the final result of one query boundary.
     *
     * @param string $query Final SQL statement sent to the driver.
     * @param array<string, mixed> $result
     * @return void
     */
    public function observeQueryResult(string $query, array $result): void {
        $notice = $this->noticeState->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($notice) || !isset($notice['type']) || !is_string($notice['type'])) {
            return;
        }

        $type = $notice['type'];
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($lastError !== '') {
            $this->recordMatchingFailure($query, $notice, $type, $lastError);
            return;
        }

        if (($result['query_succeeded'] ?? false) !== true
            || (($result['last_error'] ?? '') !== '')
            || (($result['timed_out'] ?? false) === true)) {
            return;
        }

        $this->clearAfterSuccessfulQuery($query, $notice, $type, $result);
    }

    /**
     * Attach a statement fingerprint to a matching query-owned failure.
     *
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param string $type
     * @param string $lastError
     * @return void
     */
    private function recordMatchingFailure(string $query, array $notice, string $type, string $lastError): void {
        $strategy = $this->recoveryStrategies[$type] ?? null;
        if ($strategy === null || $strategy === 'isLegacyNoticeObsolete') {
            return;
        }
        $noticeError = isset($notice['error_string']) && is_scalar($notice['error_string'])
            ? (string)$notice['error_string'] : '';
        if ($noticeError === '' || $noticeError !== $lastError) {
            return;
        }

        $notice['recovery_query_hash'] = $this->queryHash($query);
        $this->noticeState->setRuntimeFlag(
            'abj404_plugin_db_notice',
            $notice,
            ABJ_404_Solution_DatabaseNoticeStateHolder::DB_WRITE_BLOCK_COOLDOWN_SECONDS
        );
    }

    /**
     * Apply one cause-specific recovery rule after a real successful driver return.
     *
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param string $type
     * @param array<string, mixed> $result
     * @return void
     */
    private function clearAfterSuccessfulQuery(string $query, array $notice, string $type, array $result): void {
        $strategy = $this->recoveryStrategies[$type] ?? null;
        if ($strategy === null || !$this->{$strategy}($query, $notice, $result)) {
            return;
        }
        $this->noticeState->clearPluginDbNoticeIfType($type);
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param array<string, mixed> $result
     * @return bool
     */
    private function hasRecoveredWrite(string $query, array $notice, array $result): bool {
        if ($this->noticeState->shouldSkipNonEssentialDbWrites()
            || !$this->hasPositiveAffectedRows($result)
            || !$this->hasPositiveRawMutationCount($result)) {
            return false;
        }
        return $this->isAllowedWriteCommand($query, $notice)
            && $this->matchesFailedStatement($query, $notice);
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param array<string, mixed> $result
     * @return bool
     */
    private function hasRecoveredStatement(string $query, array $notice, array $result): bool {
        return $this->matchesFailedStatement($query, $notice);
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param array<string, mixed> $result
     * @return bool
     */
    private function hasRecoveredQuota(string $query, array $notice, array $result): bool {
        return !(bool)call_user_func($this->quotaCooldownChecker);
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @param array<string, mixed> $result
     * @return bool
     */
    private function isLegacyNoticeObsolete(string $query, array $notice, array $result): bool {
        return true;
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @return bool
     */
    private function matchesFailedStatement(string $query, array $notice): bool {
        $hash = $notice['recovery_query_hash'] ?? null;
        return is_string($hash) && hash_equals($hash, $this->queryHash($query));
    }

    /** @param array<string, mixed> $result @return bool */
    private function hasPositiveAffectedRows(array $result): bool {
        return ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0) > 0;
    }

    /** @param array<string, mixed> $result @return bool */
    private function hasPositiveRawMutationCount(array $result): bool {
        return ABJ_404_Solution_ExactInteger::readOr($result['query_rows_affected'] ?? null, 0, 0) > 0;
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $notice
     * @return bool
     */
    private function isAllowedWriteCommand(string $query, array $notice): bool {
        if (!method_exists($this->queryTimeoutManager, 'queryStatementCommand')) {
            return false;
        }
        $command = $this->queryTimeoutManager->queryStatementCommand($query);
        $allowed = array('INSERT' => true, 'UPDATE' => true, 'REPLACE' => true);
        if (($notice['type'] ?? null) === 'read_only') {
            $allowed['DELETE'] = true;
        }
        return isset($allowed[$command]);
    }

    /** @param string $query @return string */
    private function queryHash(string $query): string {
        return hash('sha256', trim($query));
    }

    /**
     * Clear a missing-table notice only after its producer confirms the same
     * table exists. Older notices recover their identity from error_string.
     *
     * @param string $recoveredTable
     * @param ABJ_404_Solution_DatabaseErrorTableInspector $tableInspector
     * @return void
     */
    public function clearRecoveredMissingTableNotice(
        string $recoveredTable,
        ABJ_404_Solution_DatabaseErrorTableInspector $tableInspector
    ): void {
        $notice = $this->noticeState->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($notice) || ($notice['type'] ?? null) !== 'missing_table' || $recoveredTable === '') {
            return;
        }
        $noticeTable = $notice['table_name'] ?? null;
        if (!is_string($noticeTable) || $noticeTable === '') {
            $error = is_string($notice['error_string'] ?? null) ? $notice['error_string'] : '';
            $noticeTable = $tableInspector->extractMissingTableNameFromError($error);
        }
        if ($noticeTable === $recoveredTable) {
            $this->noticeState->clearPluginDbNoticeIfType('missing_table');
        }
    }
}
