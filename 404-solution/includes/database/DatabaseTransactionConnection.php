<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseMutationFailedException.php';
require_once __DIR__ . '/../core/PhpRuntimeCapabilityAdapter.php';

/**
 * Owns one captured native database session for transaction work.
 *
 * This boundary intentionally never calls wpdb::query(): wpdb may reconnect
 * and replay a query. The physical PDO/mysqli object captured at construction
 * is the only transport used after that point.
 *
 * @phpstan-type NativeResult array{
 *     rows:array<int,mixed>,
 *     last_error:string,
 *     last_result:array<int,mixed>,
 *     rows_affected:int,
 *     insert_id:int,
 *     num_rows:int,
 *     query_succeeded:bool,
 *     cause:Throwable|null,
 *     native_code:int|null,
 *     sqlstate:string|null
 * }
 */
final class ABJ_404_Solution_DatabaseTransactionConnection {

    private const SOURCE_LABEL = 'DatabaseTransactionConnection::execute';

    /** @var wpdb WordPress-compatible database envelope. */
    private $wpdb;

    /** @var PDO|mysqli */
    private $connection;

    /** @var int */
    private static $nextSavepointId = 1;

    /**
     * @param object $wpdb WordPress-compatible envelope; runtime compatibility is validated by the native reflection checks.
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    public function __construct($wpdb) {
        if (!is_object($wpdb) || !$this->isWordPressEnvelope($wpdb)) {
            throw $this->failure('The active wpdb handle is not an object.', null);
        }
        $connection = $this->readConnection($wpdb);
        if ($connection instanceof PDO) {
            try {
                if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    throw $this->failure('The active PDO handle is not a MySQL connection.', null);
                }
            } catch (ABJ_404_Solution_DatabaseMutationFailedException $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                throw $this->failure('The native PDO driver could not be identified: ' . $exception->getMessage(), $exception);
            }
        } elseif (class_exists('mysqli', false) && $connection instanceof mysqli) {
            // Native mysqli is supported without any wpdb transport fallback.
        } else {
            throw $this->failure('The active wpdb handle does not expose a supported native MySQL session.', null);
        }
        $this->wpdb = $wpdb;
        $this->connection = $connection;
    }

    /**
     * Probe the captured session without touching wpdb's result envelope.
     * SAVEPOINT outside a transaction is a no-op; RELEASE then reports the
     * native ER_SP_DOES_NOT_EXIST code 1305. Every other native refusal is
     * setup failure, not evidence that this session is owned.
     */
    public function hasActiveTransaction(): bool {
        $savepoint = $this->newSavepointName();
        try {
            $this->nativeExecute('SAVEPOINT ' . $savepoint);
            try {
                $this->nativeExecute('RELEASE SAVEPOINT ' . $savepoint);
                return true;
            } catch (Throwable $releaseFailure) {
                if ($this->nativeErrorCode($releaseFailure) === 1305) {
                    return false;
                }
                throw $releaseFailure;
            }
        } catch (Throwable $exception) {
            throw $this->failure(
                'The native transaction ownership probe failed: ' . $exception->getMessage(),
                $exception,
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED
            );
        }
    }

    /**
     * Execute on the captured native session and mirror the result envelope
     * on the captured wpdb object. The return shape remains wpdb-like while
     * retaining the native cause for the lifecycle owner.
     *
     * @return NativeResult
     */
    public function execute(string $sql): array {
        $clock = abj_clock();
        $startedAt = $clock->nowFloat();
        try {
            // Core prepare() protects percent bytes until wpdb's query filter
            // removes its tokens. Native transport must perform that one step
            // on the captured envelope, without invoking arbitrary query filters.
            $remove = array($this->wpdb, 'remove_placeholder_escape');
            if (is_callable($remove)) {
                $decoded = call_user_func($remove, $sql);
                if (!is_string($decoded) || $decoded === '') {
                    throw $this->failure('The captured wpdb placeholder decoder returned invalid SQL.', null,
                        ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED);
                }
                $sql = $decoded;
            }
            $result = $this->nativeExecute($sql);
            $this->writeEnvelope($sql, $result);
            return $result;
        } catch (Throwable $e) {
            $result = array(
                'rows' => array(),
                'last_error' => $e->getMessage(),
                'last_result' => array(),
                'rows_affected' => 0,
                'insert_id' => 0,
                'num_rows' => 0,
                'query_succeeded' => false,
                'cause' => $e,
                'native_code' => $this->nativeErrorCode($e),
                'sqlstate' => $this->nativeSqlState($e),
            );
            $this->writeEnvelope($sql, $result);
            return $result;
        } finally {
            $elapsedMs = ($clock->nowFloat() - $startedAt) * 1000.0;
            if (function_exists('abj404_query_budget_record')) {
                abj404_query_budget_record(self::SOURCE_LABEL, $elapsedMs, 0);
            }
        }
    }

    /** Restore only the diagnostic field after lifecycle cleanup. */
    public function restoreLastError(string $error): void {
        try {
            $this->wpdb->last_error = $error;
            if ((string)$this->wpdb->last_error !== $error) {
                throw $this->failure('wpdb last_error did not retain the original error.', null);
            }
        } catch (Throwable $exception) {
            throw $this->failure('The original database error could not be restored: ' . $exception->getMessage(), $exception);
        }
    }

    /**
     * Run one session-control statement (START TRANSACTION, SAVEPOINT, SET
     * TRANSACTION ...) that must succeed before any work can begin.
     *
     * @param string $statement
     * @return void
     * @throws ABJ_404_Solution_DatabaseMutationFailedException SETUP_FAILED when the database refuses it.
     */
    public function runSetupStatement(string $statement): void {
        $result = $this->execute($statement);
        if ($result['query_succeeded'] && $result['last_error'] === '') {
            return;
        }
        throw $this->failure(
            $result['last_error'] !== '' ? $result['last_error'] : 'Database refused: ' . $statement,
            $result['cause'] instanceof Throwable ? $result['cause'] : null
        );
    }

    /**
     * Roll back the transaction this caller opened. A refusal is returned, not
     * thrown, so the caller can finish its own cleanup and report both errors.
     *
     * @return Throwable|null Null when the rollback succeeded.
     */
    public function rollbackOwned(): ?Throwable {
        $result = $this->execute('ROLLBACK');
        if ($result['query_succeeded'] && $result['last_error'] === '') {
            return null;
        }
        return $result['cause'] instanceof Throwable
            ? $result['cause']
            : $this->failure(
                $result['last_error'] !== '' ? $result['last_error'] : 'Database refused ROLLBACK.',
                null,
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED
            );
    }

    /**
     * @param object $wpdb WordPress-compatible envelope.
     * @return object
     */
    private function readConnection($wpdb): object {
        $readErrors = array();
        $firstReadError = null;
        foreach (array('dbh', 'pdo') as $field) {
            if (!property_exists($wpdb, $field)) {
                continue;
            }
            try {
                $property = new ReflectionProperty($wpdb, $field);
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $connection = $property->getValue($wpdb);
                if (is_object($connection)) {
                    return $connection;
                }
                if ($connection !== null) {
                    $readErrors[] = $field . ': native handle was not an object';
                }
            } catch (Throwable $e) {
                $firstReadError = $firstReadError ?: $e;
                $readErrors[] = $field . ': ' . $e->getMessage();
            }
        }
        if ($readErrors !== array()) {
            throw $this->failure('The native wpdb handle could not be read: ' . implode('; ', $readErrors), $firstReadError);
        }
        throw $this->failure('The active wpdb handle has no native dbh connection.', null);
    }

    /**
     * Validate the structural WordPress database envelope without requiring a
     * concrete wpdb class, because compatible drop-ins are supported.
     *
     * @phpstan-assert-if-true wpdb $wpdb
     */
    private function isWordPressEnvelope(object $wpdb): bool {
        return is_callable(array($wpdb, 'prepare'))
            && property_exists($wpdb, 'last_query')
            && property_exists($wpdb, 'last_error')
            && property_exists($wpdb, 'last_result')
            && property_exists($wpdb, 'rows_affected')
            && property_exists($wpdb, 'insert_id');
    }

    /**
     * @return NativeResult
     */
    private function nativeExecute(string $sql): array {
        if ($this->connection instanceof PDO) {
            $statement = $this->connection->query($sql);
            if ($statement === false) {
                $info = $this->connection->errorInfo();
                $message = is_array($info) && isset($info[2]) && is_string($info[2]) ? $info[2] : 'Native PDO query failed.';
                $parsedCode = is_array($info) && isset($info[1])
                    ? ABJ_404_Solution_ExactInteger::read($info[1], PHP_INT_MIN)
                    : null;
                $code = $parsedCode ?? 0;
                $nativeFailure = new PDOException($message, $code);
                $nativeFailure->errorInfo = is_array($info) ? $info : null;
                throw $this->failure(
                    $message,
                    $nativeFailure,
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED
                );
            }
            $rows = array();
            $numRows = 0;
            if ($statement instanceof PDOStatement && $statement->columnCount() > 0) {
                $fetched = $this->fetchPdoRows($statement);
                $rows = $fetched['rows'];
                $numRows = $fetched['num_rows'];
            }
            $rowsAffected = $statement instanceof PDOStatement ? (int)$statement->rowCount() : 0;
            return array(
                'rows' => $rows,
                'last_error' => '',
                'last_result' => $rows,
                'rows_affected' => $rowsAffected,
                'insert_id' => $rowsAffected > 0 && preg_match('/^\s*(?:INSERT|REPLACE)\s/i', $sql) === 1
                    ? (int)$this->connection->lastInsertId() : 0,
                'num_rows' => $numRows,
                'query_succeeded' => true,
                'cause' => null,
                'native_code' => null,
                'sqlstate' => null,
            );
        }

        /** @var mysqli $connection */
        $connection = $this->connection;
        $nativeResult = $connection->query($sql);
        if ($nativeResult === false) {
            $message = (string)$connection->error;
            $nativeFailure = new PDOException($message !== '' ? $message : 'Native mysqli query failed.', (int)$connection->errno);
            $nativeFailure->errorInfo = array((string)$connection->sqlstate, (int)$connection->errno, $message);
            throw $this->failure(
                $message !== '' ? $message : 'Native mysqli query failed.',
                $nativeFailure,
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED
            );
        }
        $rows = array();
        $numRows = 0;
        if ($nativeResult instanceof mysqli_result) {
            while ($row = $nativeResult->fetch_assoc()) {
                $rows[] = $row;
            }
            $numRows = count($rows);
            $nativeResult->free();
        }
        return array(
            'rows' => $rows,
            'last_error' => '',
            'last_result' => $rows,
            'rows_affected' => (int)$connection->affected_rows,
            'insert_id' => (int)$connection->insert_id,
            'num_rows' => $numRows,
            'query_succeeded' => true,
            'cause' => null,
            'native_code' => null,
            'sqlstate' => null,
        );
    }

    /**
     * @return array{rows:array<int,mixed>,num_rows:int}
     */
    private function fetchPdoRows(PDOStatement $statement): array {
        $fetchedRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($fetchedRows)) {
            $fetchFailure = new PDOException('Native PDO result fetch failed.');
            $fetchFailure->errorInfo = $statement->errorInfo();
            throw $this->failure(
                'Native PDO result fetch failed.',
                $fetchFailure,
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED
            );
        }
        return array('rows' => $fetchedRows, 'num_rows' => count($fetchedRows));
    }

    /**
     * @param NativeResult $result
     * @return void
     */
    private function writeEnvelope(string $sql, array $result): void {
        try {
            $this->wpdb->last_query = $sql;
            $this->wpdb->last_error = (string)$result['last_error'];
            $lastResult = array();
            foreach ($result['last_result'] as $row) {
                $lastResult[] = $row instanceof stdClass ? $row : (object)$row;
            }
            $this->wpdb->last_result = $lastResult;
            $this->wpdb->rows_affected = (int)$result['rows_affected'];
            $this->wpdb->insert_id = (int)$result['insert_id'];
            if (property_exists($this->wpdb, 'num_rows')) {
                $this->wpdb->num_rows = (int)$result['num_rows'];
            }
        } catch (Throwable $exception) {
            throw $this->failure('The wpdb result envelope could not be updated: ' . $exception->getMessage(), $exception);
        }
    }

    private function newSavepointName(): string {
        if (!class_exists('ABJ_404_Solution_PhpRuntimeCapabilityAdapter')) {
            throw $this->failure('The process capability adapter is unavailable for savepoint naming.', null);
        }
        $token = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processToken();
        $token = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$token);
        if (!is_string($token) || $token === '') {
            throw $this->failure('The process capability adapter returned an invalid savepoint token.', null);
        }
        $counter = self::$nextSavepointId++;
        return 'abj404_txn_' . substr($token, 0, 20) . '_' . $counter
            . '_' . substr(hash('sha256', (string)$token . ':' . $counter), 0, 10);
    }

    private function nativeErrorCode(Throwable $exception): ?int {
        if ($exception instanceof PDOException && isset($exception->errorInfo[1])) {
            $driverCode = ABJ_404_Solution_ExactInteger::read($exception->errorInfo[1], PHP_INT_MIN);
            if ($driverCode !== null && $driverCode !== 0) {
                return $driverCode;
            }
        }
        $code = $exception->getCode();
        if (is_int($code) && $code !== 0) {
            return $code;
        }
        $parsedCode = ABJ_404_Solution_ExactInteger::read($code, PHP_INT_MIN);
        if ($parsedCode !== null && $parsedCode !== 0) {
            return $parsedCode;
        }
        return $exception->getPrevious() instanceof Throwable ? $this->nativeErrorCode($exception->getPrevious()) : null;
    }

    private function nativeSqlState(Throwable $exception): ?string {
        if ($exception instanceof PDOException && isset($exception->errorInfo[0]) && is_string($exception->errorInfo[0])
            && $exception->errorInfo[0] !== '') {
            return $exception->errorInfo[0];
        }
        if (method_exists($exception, 'getSqlState')) {
            $state = $exception->getSqlState();
            if (is_string($state) && $state !== '') {
                return $state;
            }
        }
        return $exception->getPrevious() instanceof Throwable ? $this->nativeSqlState($exception->getPrevious()) : null;
    }

    private function failure(string $message, ?Throwable $cause, ?string $code = null): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException(
            $code ?: ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
            $message,
            $cause
        );
    }
}
