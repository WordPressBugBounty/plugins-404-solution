<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A source-plugin database read did not establish a usable row set.
 *
 * The distinct type lets import and preview boundaries preserve partial writes
 * and report the cause without swallowing unrelated programming exceptions.
 */
final class ABJ_404_Solution_ImportSourceReadException extends RuntimeException {
    public const FAILURE_CODE = 'ABJ404-IMPORT-SOURCE-READ-FAILED';

    public function __construct(string $message) {
        parent::__construct('[' . self::FAILURE_CODE . '] ' . $message);
    }
}
