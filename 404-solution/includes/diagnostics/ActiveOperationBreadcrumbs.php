<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded append-only state for operations that run after verbose caps.
 *
 * One flushed JSONL record is appended for every transition. Readers fold the
 * stream by (request_id, boundary), keeping the latest 32 live pairs. The raw
 * journal is capped at 1 MiB; only the writer that would cross that bound pays
 * for an atomic compaction to the folded table.
 *
 * A worker death during append can leave a final unterminated fragment. Readers
 * discard only that final fragment and retain the complete prefix; the next
 * writer compacts it away before appending. A malformed terminated line still
 * fails closed. Compaction itself writes and flushes a complete snapshot before
 * renaming, so a death leaves either the append-only source or the complete
 * compacted file. No deliberately truncated target is exposed.
 *
 * This class owns the bounded persistence contract. Boundary field privacy is
 * owned by ABJ_404_Solution_ActiveOperationBoundaryManifest, and event semantics
 * remain with AjaxCheckpointLogger. Turning the journal file back into a folded
 * snapshot is ABJ_404_Solution_ActiveOperationBreadcrumbReader's job.
 *
 * @phpstan-import-type Snapshot from ABJ_404_Solution_ActiveOperationBreadcrumbReader
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbs {

    const FILE = 'abj404_ajax_active_operations.jsonl';
    const LOCK_FILE = 'abj404_ajax_active_operations.lock';
    const MAX_RECORDS = ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_RECORDS;
    const MAX_RECORD_BYTES = ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_RECORD_BYTES;
    const MAX_FILE_BYTES = ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_FILE_BYTES;
    const LOCK_WAIT_TIMEOUT_US = 50000;

    /**
     * Last validated fold per path. Same-inode growth is parsed from the old
     * size, so a sibling append joins the fold before this process compacts.
     *
     * @var array<string, Snapshot>
     */
    private static $rememberedTable = array();

    /**
     * Append the latest transition for one request/boundary pair.
     *
     * @param array<string, mixed> $record
     * @return array{status: string, reason: string}
     */
    public static function replace(string $directory, array $record): array {
        try {
            $validated = self::validateRecord($record);
            if ($validated['status'] !== 'complete') {
                return $validated;
            }
            $record = self::sanitizeRecord($record);
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return self::failure('directory_unavailable');
            }

            $path = $directory . self::FILE;
            $lockPath = $directory . self::LOCK_FILE;
            $acquired = ABJ_404_Solution_DiagnosticAppendStream::acquireExclusive(
                $lockPath,
                self::LOCK_WAIT_TIMEOUT_US
            );
            if ($acquired['status'] === 'failed') {
                self::reportFailure('active-operation lock could not be opened: ' . $lockPath
                    . ($acquired['warning'] !== '' ? ' (' . $acquired['warning'] . ')' : ''));
                return self::failure('lock_open_failed');
            }
            try {
                if ($acquired['status'] === 'lock_timeout') {
                    self::reportFailure('active-operation lock wait exceeded: ' . $lockPath);
                    return self::failure('lock_wait_exceeded');
                }
                $snapshot = self::readExistingOrRemembered($path);
                if ($snapshot === null) {
                    return self::failure('existing_file_unparseable');
                }
                // This prefix is already on disk and validated under the lock.
                // Keep it even if compaction or the following append fails;
                // the next stat still detects replacement, truncation or growth.
                self::rememberSnapshot($path, $snapshot);

                $record['breadcrumb_format_version'] = 2;
                $record['breadcrumb_seq'] = $snapshot['max_sequence'] >= PHP_INT_MAX
                    ? 1 : $snapshot['max_sequence'] + 1;
                $encoded = ABJ_404_Solution_ActiveOperationBreadcrumbState::encodeRecord($record);
                if ($encoded === null) {
                    self::reportFailure('active-operation file contains an over-budget record.');
                    return self::failure('record_too_large');
                }
                $payloadBytes = strlen($encoded['line']) + 1;
                if ($snapshot['torn_tail']
                        || $snapshot['size'] + $payloadBytes > self::MAX_FILE_BYTES) {
                    $snapshot = self::compactFile($path, $snapshot);
                    if ($snapshot === null) {
                        return self::failure('compaction_failed');
                    }
                }

                $append = ABJ_404_Solution_DiagnosticAppendStream::append(
                    $path,
                    $encoded['line'] . "\n"
                );
                if ($append['status'] !== 'complete') {
                    self::reportFailure('active-operation append failed: '
                        . ($append['reason'] ?? 'unknown')
                        . ($append['warning'] !== '' ? ' (' . $append['warning'] . ')' : ''));
                    return self::failure('append_failed');
                }
                $snapshot['records'] = ABJ_404_Solution_ActiveOperationBreadcrumbState::foldEncodedRecord(
                    $snapshot['records'],
                    $encoded
                );
                $snapshot['size'] += $payloadBytes;
                $snapshot['max_sequence'] = $record['breadcrumb_seq'];
                $snapshot['torn_tail'] = false;
                self::rememberSnapshot($path, $snapshot);
                return array('status' => 'complete', 'reason' => '');
            } finally {
                if (ABJ_404_Solution_DiagnosticAppendStream::release($lockPath)['status'] === 'failed') {
                    self::reportFailure('active-operation lock could not be released: ' . $lockPath);
                }
            }
        } catch (Throwable $e) {
            self::reportFailure('active-operation replacement failed: ' . $e->getMessage());
            return self::failure('unexpected_failure');
        }
    }

    /** The active-state file path whether or not the file currently exists. */
    public static function path(string $directory): string {
        return $directory . self::FILE;
    }

    /**
     * Return the latest active identities for one ledger request.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForRequest(string $directory, string $requestId): array {
        if (preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1) {
            return array();
        }
        $snapshot = ABJ_404_Solution_ActiveOperationBreadcrumbReader::read(self::path($directory));
        if ($snapshot === null) {
            return array();
        }
        return array_values(array_filter(
            array_column($snapshot['records'], 'record'),
            static function (array $record) use ($requestId): bool {
                return ($record['request_id'] ?? null) === $requestId
                    && ($record['state'] ?? null) === 'active';
            }
        ));
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function compactSupportLines(array $lines): array {
        return ABJ_404_Solution_ActiveOperationBreadcrumbState::compactSupportLines($lines);
    }

    /** Discard request-local cached state between PHPUnit request fixtures. */
    public static function resetForTests(): void {
        self::$rememberedTable = array();
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function sanitizeRecord(array $record): array {
        $boundary = is_string($record['boundary'] ?? null) ? $record['boundary'] : '';
        $core = array();
        foreach (array('request_id', 'event', 'boundary', 'state', 'checkpoint_id') as $field) {
            if (array_key_exists($field, $record)) {
                $core[$field] = $record[$field];
            }
        }
        return array_merge(
            $core,
            ABJ_404_Solution_ActiveOperationBoundaryManifest::selectFields($boundary, $record)
        );
    }

    /**
     * @param array<mixed, mixed> $record
     * @return array{status: string, reason: string}
     */
    private static function validateRecord(array $record): array {
        $requestId = $record['request_id'] ?? null;
        $boundary = $record['boundary'] ?? null;
        $state = $record['state'] ?? null;
        if (!is_string($requestId) || preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1) {
            self::reportFailure('active-operation record has an invalid request id.');
            return self::failure('invalid_request_id');
        }
        if (!is_string($boundary)
                || !ABJ_404_Solution_ActiveOperationBoundaryManifest::hasBoundary($boundary)) {
            self::reportFailure('active-operation record has an invalid boundary.');
            return self::failure('invalid_boundary');
        }
        if (!in_array($state, array('active', 'complete'), true)) {
            self::reportFailure('active-operation record has an invalid state.');
            return self::failure('invalid_state');
        }
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_RECORD_BYTES) {
            self::reportFailure('active-operation record exceeds its fixed record budget.');
            return self::failure('record_too_large');
        }
        return array('status' => 'complete', 'reason' => '');
    }

    /** @return Snapshot|null */
    private static function readExistingOrRemembered(string $path): ?array {
        clearstatcache(true, $path);
        $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return is_file($path); }
        );
        if ($exists['warning'] !== null) {
            self::reportFilesystemWarning('active-operation file existence could not be checked: ' . $path, $exists['warning']);
            return null;
        }
        if (!$exists['result']) {
            return ABJ_404_Solution_ActiveOperationBreadcrumbReader::emptySnapshot();
        }
        $currentResult = ABJ_404_Solution_HostFilesystemCall::stat($path);
        $current = $currentResult['result'];
        if (!is_array($current) || $currentResult['warning'] !== null) {
            self::reportFailure('active-operation file identity could not be read: ' . $path
                . ($currentResult['warning'] !== null ? ' (' . $currentResult['warning'] . ')' : ''));
            return null;
        }
        $size = is_int($current['size'] ?? null) ? $current['size'] : -1;
        if ($size < 0 || $size > self::MAX_FILE_BYTES) {
            self::reportFailure('active-operation file exceeds its fixed byte bound: ' . $path);
            return null;
        }
        $remembered = self::$rememberedTable[$path] ?? null;
        if (is_array($remembered)
                && $remembered['ino'] === ($current['ino'] ?? null)
                && $remembered['dev'] === ($current['dev'] ?? null)
                && $size >= $remembered['size']) {
            if ($size === $remembered['size']) {
                return $remembered;
            }
            return ABJ_404_Solution_ActiveOperationBreadcrumbReader::read($path, $remembered['size'], $remembered);
        }
        return ABJ_404_Solution_ActiveOperationBreadcrumbReader::read($path);
    }

    /**
     * @param Snapshot $snapshot Validated folded state.
     * @return Snapshot|null Compacted snapshot, or null on failure.
     */
    private static function compactFile(string $path, array $snapshot): ?array {
        $lines = array_column($snapshot['records'], 'line');
        $payload = implode("\n", $lines) . ($lines === array() ? '' : "\n");
        $temporary = $path . '.compact.tmp';
        $opened = ABJ_404_Solution_HostFilesystemCall::fopen($temporary, 'wb');
        $handle = $opened['result'];
        if ($handle === false) {
            self::reportFailure('active-operation compaction file could not be opened: ' . $temporary
                . ($opened['warning'] !== null ? ' (' . $opened['warning'] . ')' : ''));
            return null;
        }
        try {
            $writtenResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle, $payload) { return fwrite($handle, $payload); }
            );
            $written = $writtenResult['result'];
            $flushedResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fflush($handle); }
            );
            $flushed = $flushedResult['result'];
        } finally {
            $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fclose($handle); }
            );
        }
        if ($written !== strlen($payload) || !$flushed) {
            $warning = $writtenResult['warning'] ?? $flushedResult['warning'];
            self::reportFailure('active-operation compaction file could not be flushed: '
                . $temporary . ($warning !== null ? ' (' . $warning . ')' : ''));
            ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($temporary) { return unlink($temporary); }
            );
            return null;
        }
        $renamed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($temporary, $path) { return rename($temporary, $path); }
        );
        if (!$renamed['result']) {
            self::reportFailure('active-operation compacted file could not be atomically replaced: '
                . $path . ($renamed['warning'] !== null ? ' (' . $renamed['warning'] . ')' : ''));
            ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($temporary) { return unlink($temporary); }
            );
            return null;
        }
        ABJ_404_Solution_DiagnosticAppendStream::invalidate($path);
        clearstatcache(true, $path);
        $statResult = ABJ_404_Solution_HostFilesystemCall::stat($path);
        $stat = $statResult['result'];
        if (!is_array($stat)) {
            self::reportFailure('active-operation compacted file identity could not be read: ' . $path
                . ($statResult['warning'] !== null ? ' (' . $statResult['warning'] . ')' : ''));
            return null;
        }
        $snapshot['ino'] = $stat['ino'] ?? null;
        $snapshot['dev'] = $stat['dev'] ?? null;
        $snapshot['size'] = strlen($payload);
        $snapshot['torn_tail'] = false;
        return $snapshot;
    }

    /** @param Snapshot $snapshot Validated folded state. */
    private static function rememberSnapshot(string $path, array $snapshot): void {
        if ($snapshot['ino'] === null || $snapshot['dev'] === null) {
            $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($path) { return is_file($path); }
            );
            if (!$exists['result']) {
                unset(self::$rememberedTable[$path]);
                return;
            }
            clearstatcache(true, $path);
            $statResult = ABJ_404_Solution_HostFilesystemCall::stat($path);
            $stat = $statResult['result'];
            if (!is_array($stat)) {
                unset(self::$rememberedTable[$path]);
                return;
            }
            $snapshot['ino'] = $stat['ino'] ?? null;
            $snapshot['dev'] = $stat['dev'] ?? null;
        }
        self::$rememberedTable[$path] = $snapshot;
    }

    /** @return array{status: string, reason: string} */
    private static function failure(string $reason): array {
        return array('status' => 'failed', 'reason' => $reason);
    }

    private static function reportFilesystemWarning(string $message, ?string $warning): void {
        if ($warning !== null) {
            self::reportFailure($message . ' (' . $warning . ')');
        }
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('active-operation-breadcrumb', $message);
    }
}
