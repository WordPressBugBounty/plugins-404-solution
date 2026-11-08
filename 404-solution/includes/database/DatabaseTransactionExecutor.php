<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/DatabaseMetadataLockWaitGuard.php';
require_once __DIR__ . '/DatabaseMutationFailedException.php';
require_once __DIR__ . '/DatabaseStatementGroupTransaction.php';
require_once __DIR__ . '/DatabaseTransactionConnection.php';

/**
 * Owns transaction lifecycle while DatabaseTransactionConnection owns the
 * physical native session. No operation in this class calls wpdb::query().
 *
 * Grouped write statements (executeAsTransaction) are delegated to
 * ABJ_404_Solution_DatabaseStatementGroupTransaction; this class keeps the
 * connection scope, the SERIALIZABLE callback lifecycle and the post-commit
 * completions.
 *
 * @phpstan-import-type NativeResult from ABJ_404_Solution_DatabaseTransactionConnection
 * @phpstan-type MutationRequest array{
 *     sql:string,
 *     params:array<int,mixed>,
 *     description:string
 * }
 */
class ABJ_404_Solution_DatabaseTransactionExecutor {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseMetadataLockWaitGuard */
    private $metadataLockWaitGuard;

    /** @var ABJ_404_Solution_DatabaseStatementGroupTransaction */
    private $statementGroupTransaction;

    /** @var ABJ_404_Solution_DatabaseTransactionConnection|null */
    private $connection;

    /** @var wpdb|null The wpdb envelope paired with the captured native handle. */
    private $wpdbHandle;

    /** @var ABJ_404_Solution_DatabaseTransactionConnection|null */
    private $serializableConnection;

    /** @var array<int,callable():void> Completion callbacks waiting for commit. */
    private $postCommitOperations = array();

    private const SERIALIZABLE_STATE_IDLE = 'idle';
    private const SERIALIZABLE_STATE_SETUP = 'setup';
    private const SERIALIZABLE_STATE_ACTIVE = 'active';
    private const SERIALIZABLE_STATE_COMMITTING = 'committing';
    private const SERIALIZABLE_STATE_COMMITTED = 'committed';
    private const SERIALIZABLE_STATE_ROLLED_BACK_STATEMENT = 'rolled_back_statement_failure';
    private const SERIALIZABLE_STATE_ROLLED_BACK_COMMIT = 'rolled_back_commit_failure';
    private const SERIALIZABLE_STATE_CLEANUP_FAILED = 'cleanup_failed';
    private const SERIALIZABLE_STATE_SETUP_FAILED = 'setup_failed';
    private const SERIALIZABLE_STATE_AMBIENT_REFUSED = 'ambient_refused';

    /** @var string Discriminated lifecycle state for the current logical attempt. */
    private $serializableState = self::SERIALIZABLE_STATE_IDLE;

    /**
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
        $this->metadataLockWaitGuard = new ABJ_404_Solution_DatabaseMetadataLockWaitGuard($logger);
        $this->statementGroupTransaction = new ABJ_404_Solution_DatabaseStatementGroupTransaction($core, $logger);
        $this->connection = null;
        $this->wpdbHandle = null;
        $this->serializableConnection = null;
        $this->postCommitOperations = array();
    }

    /** @param array<int,string> $statementArray */
    public function executeAsTransaction(array $statementArray): void {
        $this->withConnectionScope(function ($connection) use ($statementArray): void {
            $this->statementGroupTransaction->run($connection, $statementArray);
        });
    }

    /** True only when the captured native session currently has a caller transaction. */
    public function hasActiveTransaction(): bool {
        return $this->withConnectionScope(function ($connection): bool {
            return $connection->hasActiveTransaction();
        });
    }

    /**
     * Run a completion callback only after the current owned SERIALIZABLE
     * transaction has committed. Outside that scope, preserve the ordinary
     * immediate-completion behavior.
     *
     * @param callable():void $operation
     * @return void
     */
    public function afterCommit(callable $operation): void {
        if ($this->serializableConnection !== null) {
            $this->postCommitOperations[] = $operation;
            return;
        }
        $operation();
    }

    /**
     * Run one callback in a new SERIALIZABLE transaction on the captured
     * native session. The callback is never retried: it may have completion
     * effects. Cleanup precedes propagation and logging/classification.
     *
     * @template T
     * @param callable(ABJ_404_Solution_DatabaseTransactionConnection):T $operation
     * @return T
     */
    public function runSerializableTransaction(callable $operation) {
        if ($this->serializableConnection !== null) {
            throw $this->typedFailure(
                ABJ_404_Solution_DatabaseMutationFailedException::AMBIENT_TRANSACTION,
                'A SERIALIZABLE transaction is already owned by this executor.',
                null
            );
        }

        // Reset before binding/probing the session or entering the metadata
        // guard. A prior rolled-back attempt must never authorize this one.
        $this->serializableState = self::SERIALIZABLE_STATE_SETUP;

        return $this->withConnectionScope(function ($connection) use ($operation) {
            if ($connection->hasActiveTransaction()) {
                $this->serializableState = self::SERIALIZABLE_STATE_AMBIENT_REFUSED;
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::AMBIENT_TRANSACTION,
                    'A caller-owned transaction is already active; SERIALIZABLE work was not attempted.',
                    null
                );
            }
            $wpdb = $this->requireWpdbHandle();
            $guarded = $this->metadataLockWaitGuard->runWithBoundedWait($wpdb, array(
                'description' => 'SERIALIZABLE transaction',
                'operation' => function () use ($connection, $operation) {
                    return $this->runOwnedSerializableScope($connection, $operation);
                },
            ));
            if ($guarded['status'] !== 'completed') {
                $this->serializableState = self::SERIALIZABLE_STATE_SETUP_FAILED;
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                    $guarded['error'] !== '' ? $guarded['error'] : 'Could not establish a bounded metadata-lock timeout.',
                    $guarded['cause'] instanceof Throwable ? $guarded['cause'] : null
                );
            }
            return $guarded['value'];
        });
    }

    /**
     * @template T
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @param callable(ABJ_404_Solution_DatabaseTransactionConnection):T $operation
     * @return T
     */
    private function runOwnedSerializableScope(ABJ_404_Solution_DatabaseTransactionConnection $connection, callable $operation) {
        $postCommitOperations = array();
        $this->postCommitOperations = array();
        try {
            // SET TRANSACTION changes only the next transaction on this pinned
            // session; keep the native handle fixed through the callback and
            // commit so the DAO cannot be replayed on another connection.
            $connection->runSetupStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $connection->runSetupStatement('START TRANSACTION');
            $this->serializableState = self::SERIALIZABLE_STATE_ACTIVE;
            $this->serializableConnection = $connection;
            $value = call_user_func($operation, $connection);
            $this->serializableState = self::SERIALIZABLE_STATE_COMMITTING;
            $commit = $connection->execute('COMMIT');
            if (!$commit['query_succeeded'] || $commit['last_error'] !== '') {
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    $commit['last_error'] !== '' ? $commit['last_error'] : 'Database refused COMMIT.',
                    $commit['cause'] instanceof Throwable ? $commit['cause'] : null
                );
            }
            $this->serializableState = self::SERIALIZABLE_STATE_COMMITTED;
            $this->serializableConnection = null;
            $postCommitOperations = $this->postCommitOperations;
            $this->postCommitOperations = array();
        } catch (Throwable $failure) {
            $this->postCommitOperations = array();
            $stateBeforeCleanup = $this->serializableState;
            $ownsActiveTransaction = $stateBeforeCleanup === self::SERIALIZABLE_STATE_ACTIVE
                || $stateBeforeCleanup === self::SERIALIZABLE_STATE_COMMITTING;
            $cleanupFailure = $ownsActiveTransaction ? $connection->rollbackOwned() : null;
            if ($ownsActiveTransaction) {
                if ($cleanupFailure !== null) {
                    $this->serializableState = self::SERIALIZABLE_STATE_CLEANUP_FAILED;
                } elseif ($stateBeforeCleanup === self::SERIALIZABLE_STATE_ACTIVE) {
                    $this->serializableState = self::SERIALIZABLE_STATE_ROLLED_BACK_STATEMENT;
                } else {
                    $this->serializableState = self::SERIALIZABLE_STATE_ROLLED_BACK_COMMIT;
                }
            } else {
                $this->serializableState = self::SERIALIZABLE_STATE_SETUP_FAILED;
            }
            $this->serializableConnection = null;
            $message = $failure->getMessage();
            if ($cleanupFailure !== null) {
                $message .= '; transaction cleanup failed: ' . $cleanupFailure->getMessage();
            }
            try {
                $connection->restoreLastError($message);
            } catch (Throwable $restoreFailure) {
                $this->serializableState = self::SERIALIZABLE_STATE_CLEANUP_FAILED;
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                    $message . '; last_error restoration failed: ' . $restoreFailure->getMessage(),
                    $failure
                );
            }
            if ($cleanupFailure !== null) {
                throw $this->typedFailure(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    $message,
                    $failure
                );
            }
            throw $failure;
        }
        $this->runPostCommitOperations($postCommitOperations);
        return $value;
    }

    /**
     * Execute every committed-scope completion and preserve every failure.
     * A callback failure cannot roll back or authorize a database retry.
     *
     * @param array<int,callable():void> $operations
     * @return void
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    private function runPostCommitOperations(array $operations): void {
        $failures = array();
        $firstFailure = null;
        foreach ($operations as $index => $operation) {
            try {
                $operation();
            } catch (Throwable $failure) {
                if ($firstFailure === null) {
                    $firstFailure = $failure;
                }
                $failures[] = array(
                    'index' => $index,
                    'class' => get_class($failure),
                    'code' => $failure->getCode(),
                    'message' => $failure->getMessage(),
                    'cause' => $failure,
                );
            }
        }
        if ($failures === array()) {
            return;
        }
        throw new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::POST_COMMIT_FAILED,
            'Transaction COMMITTED; post-commit completion failed: ' . implode('; ', array_map(static function (array $failure): string {
                return '#' . $failure['index'] . ' ' . $failure['class'] . ' (code '
                    . $failure['code'] . '): ' . $failure['message'];
            }, $failures)),
            $firstFailure,
            array(
                'transaction_state' => self::SERIALIZABLE_STATE_COMMITTED,
                'completion_failures' => $failures,
            )
        );
    }

    /**
     * @param MutationRequest $request
     * @return array{rows:array<int,mixed>,last_error:string,last_result:array<int,mixed>,rows_affected:int,insert_id:int}
     */
    public function executeSerializableMutation(array $request): array {
        return $this->withConnectionScope(function () use ($request): array {
            return $this->executeSerializableMutationScoped($request);
        });
    }

    /**
     * @param MutationRequest $request
     * @return array{rows:array<int,mixed>,last_error:string,last_result:array<int,mixed>,rows_affected:int,insert_id:int}
     */
    private function executeSerializableMutationScoped(array $request): array {
        // The DAO prepares against the wpdb envelope paired with this native
        // session; the adapter removes core's placeholder escapes and executes
        // without arbitrary query filters, reconnect or replay.
        $wpdb = $this->requireWpdbHandle();
        $prepared = $this->prepareMutation($wpdb, $request['sql'], $request['params']);
        if ($this->serializableConnection !== null) {
            $value = $this->executeMutationOnConnection($this->serializableConnection, $prepared);
            return array(
                'rows' => array_values($value['rows']),
                'last_error' => $value['last_error'],
                'last_result' => array_values($value['last_result']),
                'rows_affected' => (int)$value['rows_affected'],
                'insert_id' => (int)$value['insert_id'],
            );
        }
        // Only a standalone mutation keeps the original three-attempt policy;
        // a nested callback is one atomic operation and is never retried.
        $attempts = 3;
        $attempt = 1;
        while (true) {
            try {
                $value = $this->runSerializableTransaction(function ($connection) use ($prepared) {
                    return $this->executeMutationOnConnection($connection, $prepared);
                });
                if (!is_array($value)) {
                    throw $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED, 'Serializable mutation returned an invalid result.', null);
                }
                return array(
                    'rows' => array_values($value['rows']),
                    'last_error' => $value['last_error'],
                    'last_result' => array_values($value['last_result']),
                    'rows_affected' => (int)$value['rows_affected'],
                    'insert_id' => (int)$value['insert_id'],
                );
            } catch (Throwable $failure) {
                $retryable = $this->serializableConnection === null
                    && $this->serializableState === self::SERIALIZABLE_STATE_ROLLED_BACK_STATEMENT
                    && $this->isRetryableSerializableFailure($failure);
                if (!$retryable || $attempt >= $attempts) {
                    $refused = $failure instanceof ABJ_404_Solution_DatabaseMutationFailedException
                        ? $failure
                        : $this->typedFailure(ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED, $failure->getMessage(), $failure);
                    if ($this->serializableConnection === null) {
                        $this->reportRefusedMutation($refused, $request['description']);
                    }
                    throw $refused;
                }
                $attempt++;
                usleep(100000 + random_int(0, 200000));
            }
        }
    }

    private function isRetryableSerializableFailure(Throwable $failure): bool {
        $nativeCode = $this->nativeErrorCode($failure);
        if ($nativeCode !== null) {
            return in_array($nativeCode, array(1205, 1213), true);
        }
        return $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($failure->getMessage());
    }

    private function nativeErrorCode(Throwable $failure): ?int {
        if ($failure instanceof PDOException && isset($failure->errorInfo[1])) {
            $driverCode = ABJ_404_Solution_ExactInteger::read($failure->errorInfo[1], 1);
            if ($driverCode !== null) {
                return $driverCode;
            }
        }
        $code = $failure->getCode();
        if (is_int($code) && $code > 0) {
            return $code;
        }
        $numericCode = ABJ_404_Solution_ExactInteger::read($code, 1);
        if ($numericCode !== null) {
            return $numericCode;
        }
        return $failure->getPrevious() instanceof Throwable ? $this->nativeErrorCode($failure->getPrevious()) : null;
    }

    /**
     * @param ABJ_404_Solution_DatabaseTransactionConnection $connection
     * @return NativeResult
     */
    private function executeMutationOnConnection(ABJ_404_Solution_DatabaseTransactionConnection $connection, string $statement): array {
        $result = $connection->execute($statement);
        if (!$result['query_succeeded'] || $result['last_error'] !== '') {
            throw $this->typedFailure(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                $result['last_error'] !== '' ? $result['last_error'] : 'Database mutation failed.',
                $result['cause'] instanceof Throwable ? $result['cause'] : null
            );
        }
        return $result;
    }

    /**
     * @param wpdb $wpdb
     * @param array<int,mixed> $params
     */
    private function prepareMutation(object $wpdb, string $sql, array $params): string {
        if (empty($params)) {
            return $sql;
        }
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), array_values($params)));
        if (!is_string($prepared) || $prepared === '') {
            throw new ABJ_404_Solution_StatementPreparationException('the serializable mutation');
        }
        return $prepared;
    }

    /**
     * The native constructor validates a WordPress-compatible envelope by
     * capability; PDO-backed adapters need not inherit the nominal wpdb class.
     *
     * @return wpdb WordPress-compatible envelope for the shared metadata guard.
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    private function requireWpdbHandle(): object {
        if ($this->wpdbHandle === null || !is_object($this->wpdbHandle)) {
            throw $this->typedFailure(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The pinned WordPress database envelope is unavailable.',
                null
            );
        }
        return $this->wpdbHandle;
    }

    private function reportRefusedMutation(ABJ_404_Solution_DatabaseMutationFailedException $refused, string $description): void {
        if ($refused->getFailureCode() === ABJ_404_Solution_DatabaseMutationFailedException::AMBIENT_TRANSACTION) {
            return;
        }
        // Setup/probe and cleanup failures do not prove ownership. Never run
        // an infrastructure repair that could implicitly commit a caller's
        // transaction while the native scope is unknown.
        if ($refused->getFailureCode() !== ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED
            || $this->serializableState !== self::SERIALIZABLE_STATE_ROLLED_BACK_STATEMENT) {
            $this->logger->warn('Database setup or cleanup refusal for ' . $description . ': ' . $refused->getMessage());
            return;
        }
        if (!$this->core->errorClassifier()->classifyAndHandleInfrastructureError($refused->getMessage())) {
            $this->logger->errorMessage('Error ' . $description . ': ' . $refused->getMessage());
        }
    }

    /**
     * @template T
     * @param callable(ABJ_404_Solution_DatabaseTransactionConnection):T $operation
     * @return T
     */
    private function withConnectionScope(callable $operation) {
        if ($this->connection !== null) {
            return call_user_func($operation, $this->connection);
        }
        global $wpdb;
        $this->wpdbHandle = $wpdb;
        $this->connection = new ABJ_404_Solution_DatabaseTransactionConnection($wpdb);
        try {
            return call_user_func($operation, $this->connection);
        } finally {
            if ($this->serializableConnection === null) {
                $this->connection = null;
                $this->wpdbHandle = null;
            }
        }
    }

    private function typedFailure(string $code, string $message, ?Throwable $cause): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException($code, $message, $cause);
    }
}
