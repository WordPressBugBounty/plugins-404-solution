<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a queryAndGetResults() read envelope proves a completed read.
 *
 * ABJ_404_Solution_DatabaseQueryInterface documents the envelope and
 * DatabaseWpdbResultHarvester writes its `query_succeeded` flag. This class is
 * the single reader-side statement of what that envelope must say before a
 * caller may treat "no rows" as "the table has no matching row": no database
 * error, no timeout, an explicit successful status, and rows that are arrays.
 * Anything else (a null driver return, a dropped flag, a malformed field) is a
 * refused read, never an empty result.
 *
 * Delivery of a refusal stays with each caller. The validator only reports it,
 * in the same `{rows, last_error}` shape the executor uses, so a repository can
 * hand the message back in its own result while a view query throws its own
 * typed exception from it.
 */
final class ABJ_404_Solution_DatabaseReadResultValidator {

    /**
     * Confirm one read envelope.
     *
     * @param mixed $result Whatever queryAndGetResults() returned.
     * @param string $operation Opens every refusal message that names a malformed
     *   envelope, so callers choose their own capitalization. A database error
     *   text is returned verbatim, without this label.
     * @return array{rows: array<int, mixed>, last_error: string} The rows with an
     *   empty `last_error` when the read is confirmed; otherwise no rows and the
     *   reason the read was refused.
     */
    public static function confirm($result, string $operation): array {
        if (!is_array($result)) {
            return self::refused($operation . ' returned an invalid result envelope.');
        }

        if (array_key_exists('last_error', $result) && !is_string($result['last_error'])) {
            return self::refused($operation . ' returned malformed error metadata.');
        }
        $lastError = array_key_exists('last_error', $result) ? $result['last_error'] : '';
        if ($lastError !== '') {
            return self::refused($lastError);
        }

        if (array_key_exists('timed_out', $result) && !is_bool($result['timed_out'])) {
            return self::refused($operation . ' returned malformed timeout metadata.');
        }
        if (($result['timed_out'] ?? false) === true) {
            return self::refused($operation . ' timed out.');
        }

        if (!array_key_exists('query_succeeded', $result) || !is_bool($result['query_succeeded'])) {
            return self::refused($operation . ' returned unknown query status.');
        }
        if ($result['query_succeeded'] !== true) {
            return self::refused($operation . ' failed without a database error.');
        }

        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            return self::refused($operation . ' returned malformed rows.');
        }
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) {
                return self::refused($operation . ' returned malformed row data.');
            }
        }

        return array('rows' => $result['rows'], 'last_error' => '');
    }

    /**
     * @param string $reason
     * @return array{rows: array<int, mixed>, last_error: string}
     */
    private static function refused(string $reason): array {
        return array('rows' => array(), 'last_error' => $reason);
    }
}
