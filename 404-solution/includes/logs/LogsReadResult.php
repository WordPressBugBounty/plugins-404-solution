<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../view-build/ViewQueryFailureException.php';
require_once __DIR__ . '/../core/ExactInteger.php';

/**
 * Validates one DatabaseCore read result before a logs consumer interprets it.
 *
 * @throws ABJ_404_Solution_ViewQueryFailureException when the query status or
 *         row shape does not prove that the read completed successfully.
 */
final class ABJ_404_Solution_LogsReadResult {

    /** @var array<string, mixed> */
    private $result;

    /** @var string */
    private $operation;

    /**
     * @param array<string, mixed> $result Result from DatabaseCore.
     * @param string $operation Stable read operation name for diagnostics.
     */
    public function __construct(array $result, string $operation) {
        $this->result = $result;
        $this->operation = $operation;
    }

    /**
     * Returns the typed failure when this result cannot prove successful query status.
     *
     * @return ABJ_404_Solution_ViewQueryFailureException|null
     */
    private function failure(): ?ABJ_404_Solution_ViewQueryFailureException {
        $cause = $this->failureCause();
        if ($cause === null) {
            return null;
        }

        $lastError = $this->result['last_error'] ?? null;
        $lastErrorText = is_string($lastError) ? $lastError : '';
        $timedOut = !empty($this->result['timed_out']);
        $querySucceeded = array_key_exists('query_succeeded', $this->result)
            ? $this->result['query_succeeded'] : null;
        $message = $this->operation . ' read failed (' . $cause . ')';
        if ($lastErrorText !== '') {
            $message .= ': ' . $lastErrorText;
        }

        return new ABJ_404_Solution_ViewQueryFailureException($message, array(
            'operation' => $this->operation,
            'cause' => $cause,
            'last_error' => $lastError,
            'timed_out' => $timedOut,
            'query_succeeded' => $querySucceeded,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array {
        $failure = $this->failure();
        if ($failure !== null) {
            throw $failure;
        }
        $rows = $this->result['rows'] ?? null;
        if (!is_array($rows)) {
            throw $this->invalidResult('query rows were missing or malformed');
        }
        $validatedRows = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw $this->invalidResult('query returned a malformed row');
            }
            foreach ($row as $column => $_value) {
                if (!is_string($column)) {
                    throw $this->invalidResult('query returned malformed row keys');
                }
            }
            $validatedRows[] = $row;
        }
        return $validatedRows;
    }

    /**
     * Read the first aggregate cell as a nonnegative exact integer.
     *
     * @param bool $nullMeansEmpty Whether SQL NULL represents a valid empty MIN/MAX aggregate.
     * @return int
     */
    public function integerAggregate(bool $nullMeansEmpty = false): int {
        $rows = $this->rows();
        if (!isset($rows[0]) || $rows[0] === array()) {
            throw $this->invalidResult('the query returned no aggregate row');
        }
        $value = reset($rows[0]);
        if ($value === null && $nullMeansEmpty) {
            return 0;
        }
        $integer = ABJ_404_Solution_ExactInteger::read($value, 0);
        if ($integer === null) {
            throw $this->invalidResult('the aggregate value is missing or is not a nonnegative integer');
        }
        return $integer;
    }

    /** @return string|null */
    private function failureCause(): ?string {
        $timedOut = $this->result['timed_out'] ?? false;
        if (!is_bool($timedOut) && $timedOut !== 0 && $timedOut !== 1 && $timedOut !== '0' && $timedOut !== '1') {
            return 'invalid timeout status';
        }
        if (!empty($timedOut)) {
            return 'query timed out';
        }

        if (array_key_exists('last_error', $this->result)) {
            if (!is_string($this->result['last_error'])) {
                return 'invalid database error status';
            }
            if ((string)$this->result['last_error'] !== '') {
                return 'database query error';
            }
        }
        if (array_key_exists('query_succeeded', $this->result)
            && $this->result['query_succeeded'] !== true) {
            return 'query_succeeded was not true';
        }
        if (!array_key_exists('last_error', $this->result)
            && !array_key_exists('query_succeeded', $this->result)) {
            return 'query completion status was missing';
        }

        return null;
    }

    private function invalidResult(string $cause): ABJ_404_Solution_ViewQueryFailureException {
        return new ABJ_404_Solution_ViewQueryFailureException(
            $this->operation . ' read failed (' . $cause . ')',
            array(
                'operation' => $this->operation,
                'cause' => $cause,
                'last_error' => $this->result['last_error'] ?? null,
                'timed_out' => !empty($this->result['timed_out']),
                'query_succeeded' => $this->result['query_succeeded'] ?? null,
            )
        );
    }
}
