<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure record-domain operations for the active-operation breadcrumb journal.
 *
 * File access, locking, caching, and recovery remain owned by
 * ABJ_404_Solution_ActiveOperationBreadcrumbs. This class only validates the
 * bounded record representation and folds already-read lines.
 *
 * @phpstan-type EncodedRecord array{record: array<string, mixed>, line: string}
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbState {

    public const MAX_RECORDS = 32;
    public const MAX_RECORD_BYTES = 2048;
    /** Raw journal bound; the writer compacts to the folded table before crossing it. */
    public const MAX_FILE_BYTES = 1048576;

    /**
     * @param array<string, mixed> $record
     * @return array{record: array<string, mixed>, line: string}|null
     */
    public static function encodeRecord(array $record): ?array {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || strlen($line) > self::MAX_RECORD_BYTES) {
            return null;
        }
        return array('record' => $record, 'line' => $line);
    }

    /**
     * @param array<int, array{record: array<string, mixed>, line: string}> $records
     * @param array{record: array<string, mixed>, line: string} $replacement
     * @return array<int, array{record: array<string, mixed>, line: string}>
     */
    public static function foldEncodedRecord(array $records, array $replacement): array {
        $key = self::recordKey($replacement['record']);
        $kept = array_values(array_filter(
            $records,
            static function (array $encoded) use ($key): bool {
                return self::recordKey($encoded['record']) !== $key;
            }
        ));
        $kept[] = $replacement;
        return count($kept) > self::MAX_RECORDS
            ? array_slice($kept, -self::MAX_RECORDS)
            : $kept;
    }

    /** @param array<mixed, mixed> $record */
    public static function recordKey(array $record): string {
        $requestId = $record['request_id'] ?? null;
        $boundary = $record['boundary'] ?? null;
        $state = $record['state'] ?? null;
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb'
                || !is_string($requestId)
                || preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1
                || !is_string($boundary)
                || !ABJ_404_Solution_ActiveOperationBoundaryManifest::hasBoundary($boundary)
                || !in_array($state, array('active', 'complete'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /**
     * Fold active-operation lines while preserving unrelated or malformed
     * lines for their owning policy to inspect.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function compactSupportLines(array $lines): array {
        $latest = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            $key = is_array($record) ? self::recordKey($record) : '';
            if ($key === '') {
                continue;
            }
            unset($latest[$key]);
            $latest[$key] = $index;
            if (count($latest) > self::MAX_RECORDS) {
                array_shift($latest);
            }
        }
        if ($latest === array()) {
            return $lines;
        }
        $keep = array_fill_keys(array_values($latest), true);
        return array_values(array_filter(
            $lines,
            static function (string $line, int $index) use ($keep): bool {
                $record = json_decode($line, true);
                return !is_array($record)
                    || self::recordKey($record) === ''
                    || isset($keep[$index]);
            },
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
