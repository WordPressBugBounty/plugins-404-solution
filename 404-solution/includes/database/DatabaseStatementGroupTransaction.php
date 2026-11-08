<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseMutationFailedException.php';
require_once __DIR__ . '/DatabaseTransactionConnection.php';

/**
 * Runs an ordered group of write statements as one atomic unit on the native
 * session captured by DatabaseTransactionConnection.
 *
 * Two shapes, chosen by who already owns a transaction on that session:
 *
 *   - owned:    no transaction is open, so this class opens one, commits it, and
 *               retries the whole group (bounded) on a deadlock or lock timeout.
 *   - borrowed: a caller's transaction is open, so the group runs inside a
 *               SAVEPOINT that is released on success and rolled back on failure.
 *               The caller's transaction is never committed or rolled back here,
 *               which is why borrowed groups accept only direct DML.
 *
 * Failure handling is shared: cleanup first, then restore wpdb's last_error,
 * then report, then throw a typed ABJ_404_Solution_DatabaseMutationFailedException.
 * The transaction scope itself (capturing the session, SERIALIZABLE callbacks)
 * stays with ABJ_404_Solution_DatabaseTransactionExecutor.
 *
 * @phpstan-type GroupResult array{
 *     ok:bool,
 *     error:string,
 *     cause:Throwable|null,
 *     statement:string
 * }
 * @phpstan-type Failure array{
 *     error:string,
 *     cause:Throwable|null,
 *     statement:string
 * }
 */
final class ABJ_404_Solution_DatabaseStatementGroupTransaction {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
    }

    /**
     * Run every statement atomically, borrowing the caller's transaction (via a
     * savepoint) when one is open and owning a new one otherwise.
     *
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param array<int,string> $statementArray
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    public function run(ABJ_404_Solution_DatabaseTransactionConnection $connection, array $statementArray): void {
        if ($connection->hasActiveTransaction()) {
            $this->executeBorrowedTransaction($connection, $statementArray);
            return;
        }
        $this->executeOwnedTransaction($connection, $statementArray);
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param array<int,string> $statementArray
     */
    private function executeOwnedTransaction(ABJ_404_Solution_DatabaseTransactionConnection $connection, array $statementArray): void {
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $connection->runSetupStatement('START TRANSACTION');
            try {
                $result = $this->executeGroupedStatements($connection, $statementArray);
            } catch (Throwable $e) {
                $result = array(
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'cause' => $e,
                    'statement' => 'grouped statement loop',
                );
            }
            if ($result['ok']) {
                $commit = $connection->execute('COMMIT');
                if ($commit['query_succeeded'] && $commit['last_error'] === '') {
                    return;
                }
                $commitError = $commit['last_error'] !== '' ? $commit['last_error'] : 'Database refused COMMIT.';
                $commitCause = $commit['cause'] instanceof Throwable ? $commit['cause'] : null;
                $cleanupFailure = $connection->rollbackOwned();
                if ($cleanupFailure !== null) {
                    $commitError .= '; transaction cleanup failed: ' . $cleanupFailure->getMessage();
                }
                $failure = array('error' => $commitError, 'cause' => $commitCause, 'statement' => 'COMMIT');
                $this->restoreFailureError($connection, $failure);
                $this->reportFailureAfterCleanup($failure, false);
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    $commitError,
                    $commitCause
                );
            }

            $cleanupFailure = $connection->rollbackOwned();
            $error = $result['error'];
            if ($cleanupFailure !== null) {
                $error .= '; transaction cleanup failed: ' . $cleanupFailure->getMessage();
            }
            $failure = array('error' => $error, 'cause' => $result['cause'], 'statement' => $result['statement']);
            $this->restoreFailureError($connection, $failure);
            $this->reportFailureAfterCleanup($failure, $cleanupFailure === null);
            if ($cleanupFailure !== null || !$this->core->errorClassifier()->isDeadlockOrLockTimeoutError($result['error'])
                || $attempt >= $maxAttempts) {
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    $error,
                    $result['cause']
                );
            }
            usleep(100000 + random_int(0, 200000));
        }
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param array<int,string> $statementArray
     */
    private function executeBorrowedTransaction(ABJ_404_Solution_DatabaseTransactionConnection $connection, array $statementArray): void {
        $this->validateBorrowedStatements($statementArray);
        $savepoint = $this->newSavepointName();
        $connection->runSetupStatement('SAVEPOINT ' . $savepoint);
        try {
            $result = $this->executeGroupedStatements($connection, $statementArray);
        } catch (Throwable $e) {
            $result = array(
                'ok' => false,
                'error' => $e->getMessage(),
                'cause' => $e,
                'statement' => 'grouped statement loop',
            );
        }
        if ($result['ok']) {
            $release = $connection->execute('RELEASE SAVEPOINT ' . $savepoint);
            if ($release['query_succeeded'] && $release['last_error'] === '') {
                return;
            }
            $error = $release['last_error'] !== '' ? $release['last_error'] : 'Database refused RELEASE SAVEPOINT.';
            $failure = array(
                'error' => $error,
                'cause' => $release['cause'] instanceof Throwable ? $release['cause'] : null,
                'statement' => 'RELEASE SAVEPOINT ' . $savepoint,
            );
            $this->finishBorrowedFailure($connection, $savepoint, $failure);
            return;
        }
        $this->finishBorrowedFailure($connection, $savepoint, $result);
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param array<int,string> $statementArray
     * @return GroupResult
     */
    private function executeGroupedStatements(ABJ_404_Solution_DatabaseTransactionConnection $connection, array $statementArray): array {
        foreach ($statementArray as $statement) {
            $result = $connection->execute($statement);
            if (!$result['query_succeeded'] || $result['last_error'] !== '') {
                return array(
                    'ok' => false,
                    'error' => $result['last_error'] !== '' ? $result['last_error'] : 'Database refused grouped statement without exposing an error.',
                    'cause' => $result['cause'] instanceof Throwable ? $result['cause'] : null,
                    'statement' => $statement,
                );
            }
        }
        return array('ok' => true, 'error' => '', 'cause' => null, 'statement' => '');
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param Failure $failure
     */
    private function finishBorrowedFailure(ABJ_404_Solution_DatabaseTransactionConnection $connection, string $savepoint, array $failure): void {
        $errors = array();
        $firstCause = $failure['cause'];
        $rollbackTo = $connection->execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
        if (!$rollbackTo['query_succeeded'] || $rollbackTo['last_error'] !== '') {
            $errors[] = 'ROLLBACK TO SAVEPOINT: ' . ($rollbackTo['last_error'] !== '' ? $rollbackTo['last_error'] : 'native refusal');
            $firstCause = $firstCause ?: ($rollbackTo['cause'] instanceof Throwable ? $rollbackTo['cause'] : null);
        }
        $release = $connection->execute('RELEASE SAVEPOINT ' . $savepoint);
        if (!$release['query_succeeded'] || $release['last_error'] !== '') {
            $errors[] = 'RELEASE SAVEPOINT: ' . ($release['last_error'] !== '' ? $release['last_error'] : 'native refusal');
            $firstCause = $firstCause ?: ($release['cause'] instanceof Throwable ? $release['cause'] : null);
        }
        if ($errors !== array()) {
            $failure['error'] .= '; savepoint cleanup failed: ' . implode('; ', $errors);
        }
        $failure['cause'] = $firstCause;
        $this->restoreFailureError($connection, $failure);
        $this->reportFailureAfterCleanup($failure, false);
        throw $this->typedFailure(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
            $failure['error'],
            $failure['cause']
        );
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param Failure $failure
     */
    private function restoreFailureError(ABJ_404_Solution_DatabaseTransactionConnection $connection, array $failure): void {
        try {
            $connection->restoreLastError($failure['error']);
        } catch (Throwable $restoreFailure) {
            throw $this->typedFailure(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                $failure['error'] . '; last_error restoration failed: ' . $restoreFailure->getMessage(),
                $failure['cause'] ?: $restoreFailure
            );
        }
    }

    /** @param Failure $failure */
    private function reportFailureAfterCleanup(array $failure, bool $classifyInfrastructure = true): void {
        try {
            if (!$classifyInfrastructure || !$this->core->errorClassifier()->classifyAndHandleInfrastructureError($failure['error'])) {
                $this->logger->errorMessage('Error executing SQL transaction: ' . $failure['error']);
                $this->logger->errorMessage('SQL causing the transaction error: ' . $failure['statement']);
            }
        } catch (Throwable $reportFailure) {
            throw $this->typedFailure(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                $failure['error'] . '; failure reporting failed: ' . $reportFailure->getMessage(),
                $failure['cause'] ?: $reportFailure
            );
        }
    }

    /**
     * Reject executable comments and implicit-commit statements before mutation.
     *
     * @param array<int,string> $statementArray
     */
    private function validateBorrowedStatements(array $statementArray): void {
        foreach ($statementArray as $index => $statement) {
            if (preg_match('/\/\*!\s*|\/\*M!\s*/i', $statement) === 1) {
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    'Borrowed transaction refuses executable SQL version comments at index ' . $index . '.',
                    null
                );
            }
            $candidate = ltrim($statement);
            while ($candidate !== '') {
                if (substr($candidate, 0, 2) === '/*') {
                    $end = strpos($candidate, '*/', 2);
                    if ($end === false) {
                        throw $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, 'Borrowed transaction refuses an unterminated leading SQL comment at index ' . $index . '.', null);
                    }
                    $candidate = ltrim(substr($candidate, $end + 2));
                    continue;
                }
                if (substr($candidate, 0, 2) === '--') {
                    $marker = $candidate[2] ?? '';
                    if ($marker !== '' && preg_match('/[\s\0]/', $marker) !== 1) {
                        break;
                    }
                    $lineEnd = strcspn($candidate, "\r\n");
                    $candidate = $lineEnd === strlen($candidate) ? '' : ltrim(substr($candidate, $lineEnd));
                    continue;
                }
                if ($candidate[0] === '#') {
                    $lineEnd = strcspn($candidate, "\r\n");
                    $candidate = $lineEnd === strlen($candidate) ? '' : ltrim(substr($candidate, $lineEnd));
                    continue;
                }
                break;
            }
            if (preg_match('/^([A-Za-z]+)/', $candidate, $matches) !== 1) {
                throw $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, 'Borrowed transaction requires a direct DML statement at index ' . $index . '.', null);
            }
            $verb = strtoupper($matches[1]);
            if (!in_array($verb, array('INSERT', 'UPDATE', 'DELETE', 'REPLACE'), true)) {
                throw $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, 'Borrowed transaction refuses implicit-commit statement verb ' . $verb . ' at index ' . $index . '.', null);
            }
        }
    }

    private function newSavepointName(): string {
        $token = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processToken();
        $token = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$token);
        if (!is_string($token) || $token === '') {
            throw $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED, 'The process capability adapter returned an invalid savepoint token.', null);
        }
        static $counter = 1;
        $id = $counter++;
        return 'abj404_txn_' . substr($token, 0, 20) . '_' . $id . '_' . substr(hash('sha256', $token . ':' . $id), 0, 10);
    }

    private function typedFailure(string $code, string $message, ?Throwable $cause): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException($code, $message, $cause);
    }
}
