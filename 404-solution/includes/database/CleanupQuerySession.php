<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/PhpErrorLogFallback.php';

// allow-no-test-found: public bounded cleanup and original SQL-failure coverage in tests/MaintenanceContinuationIntegrationTest.php.
/**
 * Query bounds and first-failure state shared by maintenance cleanup repositories.
 */
class ABJ_404_Solution_CleanupQuerySession {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var int|null */
    private $timeout = null;

    /** @var string */
    private $failure = '';

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Start a cleanup invocation and validate its optional bounds before SQL.
     *
     * @param array<string, mixed> $limits
     * @return array{batch_size:int,max_batches:int,timeout:int|null}
     */
    public function begin(array $limits): array {
        $this->failure = '';
        $this->timeout = null;
        $normalized = array(
            'batch_size' => 1000,
            'max_batches' => 1000,
            'timeout' => null,
        );
        foreach ($limits as $key => $value) {
            if (!array_key_exists($key, $normalized)) {
                throw new InvalidArgumentException('Invalid cleanup limit key ' . var_export($key, true) .
                    ' with value ' . var_export($value, true) . '.');
            }
            $maximum = $key === 'timeout' ? 25 : 1000;
            if (!is_int($value) || $value < 1 || $value > $maximum) {
                throw new InvalidArgumentException('Invalid cleanup limit ' . $key . '=' . var_export($value, true) .
                    '; expected an integer from 1 to ' . $maximum . '.');
            }
            $normalized[$key] = $value;
        }
        $this->timeout = $normalized['timeout'];
        return $normalized;
    }

    /**
     * Execute one cleanup query with the active timeout and retain its first failure.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function query(string $sql, array $options = array(), string $context = ''): array {
        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
        }
        $result = $this->dbCore->queryAndGetResults($sql, $options);
        // A native mutation can return false while leaving wpdb->last_error
        // empty.  A valid affected-row count is therefore not an
        // acknowledgement that this statement ran.  Keep the first native
        // error when one exists, but refuse to let an absent, malformed, or
        // false query_succeeded flag reach the batch-deletion completion path.
        if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
            $nativeError = isset($result['last_error']) && is_scalar($result['last_error'])
                ? trim((string)$result['last_error']) : '';
            $this->noteFailure(($context !== '' ? $context . ': ' : '')
                . ($nativeError !== '' ? $nativeError : 'query did not confirm success'));
            return $result;
        }
        if (!empty($result['last_error'])) {
            $error = $result['last_error'];
            $this->noteFailure(($context !== '' ? $context . ': ' : '') .
                (is_scalar($error) ? (string)$error : var_export($error, true)));
            return $result;
        }
        if (!empty($result['timed_out'])) {
            $this->noteFailure(($context !== '' ? $context . ': ' : '') . 'query timed out');
            return $result;
        }

        $this->validateQueryShape($sql, $result, $context);
        return $result;
    }

    /** @param array<string,mixed> $result */
    private function validateQueryShape(string $sql, array $result, string $context): void {
        $statement = strtoupper(ltrim((string)preg_replace('#/\*.*?\*/#s', '', $sql)));
        if (strpos($statement, 'SELECT') === 0 && !is_array($result['rows'] ?? null)) {
            $this->noteFailure(($context !== '' ? $context . ': ' : '') . 'result omitted rows');
            return;
        }
        if ((strpos($statement, 'DELETE') === 0 || strpos($statement, 'UPDATE') === 0)
                && ABJ_404_Solution_ExactInteger::read($result['rows_affected'] ?? null, 0) === null) {
            $this->noteFailure(($context !== '' ? $context . ': ' : '') .
                'result omitted a valid rows_affected count');
        }
    }

    /**
     * Hold the redirect graph lock across one cleanup read/mutation sequence.
     * The callback receives the connection-owned predicate that must fence its
     * destructive statement.
     *
     * @param callable(string):void $operation
     * @return string Empty on success; otherwise the session's first failure.
     */
    public function withRedirectGraphOwnership(callable $operation): string {
        $lock = new ABJ_404_Solution_RedirectGraphLock(
            $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}')
        );
        $releaseFailure = '';
        try {
            $lockResult = $lock->withOwnership(function () use ($operation, $lock): string {
                $operation($lock->ownershipPredicate());
                return '';
            }, array(
                'onFailure' => function (string $error): string {
                    return $error;
                },
                'onReleaseFailure' => function (string $error) use (&$releaseFailure): void {
                    $releaseFailure = $error;
                },
            ));
        } catch (Throwable $primaryFailure) {
            // RedirectGraphLock releases in its own finally block, so a
            // callback exception can arrive here with a captured secondary
            // release failure. Keep the callback throwable identity intact.
            if ($releaseFailure !== '') {
                $this->warnSecondaryOwnershipFailure('ownership release after callback failure', $releaseFailure);
            }
            throw $primaryFailure;
        }
        // A native SQL failure is recorded before ownership verification or
        // release; do not replace that cause with a secondary lock message.
        // The secondary ownership diagnostics still matter, so retain them at
        // the existing WARN boundary rather than silently discarding them.
        if ($this->failure() !== '') {
            if ($lockResult !== '') {
                $this->warnSecondaryOwnershipFailure('ownership verification', $lockResult);
            }
            if ($releaseFailure !== '') {
                $this->warnSecondaryOwnershipFailure('ownership release', $releaseFailure);
            }
            return $this->failure();
        }
        if ($lockResult !== '') {
            if ($releaseFailure !== '') {
                $this->warnSecondaryOwnershipFailure('ownership release after ownership failure', $releaseFailure);
            }
            $this->noteFailure($lockResult);
            return $lockResult;
        }
        if ($releaseFailure !== '') {
            $this->noteFailure($releaseFailure);
            return $releaseFailure;
        }
        return '';
    }

    /** Keep secondary lock diagnostics visible without replacing the primary failure. */
    private function warnSecondaryOwnershipFailure(string $phase, string $error): void {
        try {
            abj_service('logging')->warn(
                'CleanupQuerySession: secondary redirect graph ' . $phase . ' failure: ' . $error
            );
        } catch (Throwable $loggingFailure) {
            // Logging must never turn a native SQL failure into a different
            // cleanup result. Preserve the diagnostic through the host error
            // sink if the plugin logging service itself is unavailable.
            abj404_logPhpFallback(
                'service-resolution-fallback',
                'CleanupQuerySession: could not record secondary redirect graph failure ('
                . get_class($loggingFailure) . ': ' . $loggingFailure->getMessage() . '); original: ' . $error
            );
        }
    }

    /**
     * Process LIMIT-bounded result rows within one cleanup query budget.
     *
     * @param callable(int,int|null):array<int,mixed> $runBatch
     * @param callable(array<mixed,mixed>):(bool|null) $handleRow Each row is a
     *        DB result row. Returning false stops after a failed row operation.
     * @param array{batch_size:int,max_batches:int,timeout:int|null} $limits
     */
    public function processRows(callable $runBatch, callable $handleRow, array $limits): int {
        $handled = 0;
        for ($batch = 0; $batch < $limits['max_batches']; $batch++) {
            $rows = $runBatch($limits['batch_size'], $limits['timeout']);
            $rows = is_array($rows) ? $rows : array();
            $rowCount = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                if ($handleRow($row) === false) {
                    return $handled;
                }
                $handled++;
            }
            if ($rowCount < $limits['batch_size']) {
                break;
            }
        }
        return $handled;
    }

    /** Preserve the first failure until the next cleanup invocation begins. */
    public function noteFailure(string $message): void {
        if ($this->failure === '') {
            $this->failure = $message !== '' ? $message : 'cleanup query returned an unreadable result';
        }
    }

    /** Return the first failure from the current cleanup invocation, if any. */
    public function failure(): string {
        return $this->failure;
    }

    /** @param array<string,mixed> $result Never count a write whose query boundary recorded a failure. */
    public function affectedRows(array $result): int {
        return $this->failure === '' ? ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0) : 0;
    }
}
