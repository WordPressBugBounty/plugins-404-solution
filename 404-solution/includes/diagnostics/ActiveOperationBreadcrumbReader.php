<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded read of the active-operation breadcrumb journal into a folded
 * snapshot.
 *
 * Split out of ABJ_404_Solution_ActiveOperationBreadcrumbs, which keeps the
 * write path: locking, compaction, the remembered-fold cache and the public
 * API. This class only turns a journal file into a Snapshot: it never writes,
 * never locks and keeps no state between calls. A worker death during an
 * append can leave a final unterminated fragment; that single fragment is
 * reported as `torn_tail` and discarded, the complete prefix is kept, and a
 * malformed terminated line still fails closed (null).
 *
 * The dependency is one-directional: the writer calls this reader, and this
 * reader learns the byte bounds from ABJ_404_Solution_ActiveOperationBreadcrumbState,
 * so neither calls back into the other.
 *
 * @phpstan-import-type EncodedRecord from ABJ_404_Solution_ActiveOperationBreadcrumbState
 * @phpstan-type Snapshot array{ino: mixed, dev: mixed, size: int,
 *   max_sequence: int, torn_tail: bool, records: array<int, EncodedRecord>}
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbReader {

    /**
     * Fold the journal at $path, optionally resuming after a prefix the caller
     * has already validated.
     *
     * @param int $offset Byte offset of the first unread record.
     * @param Snapshot|null $base Previously validated prefix.
     * @return Snapshot|null Null when the file cannot be read within its bounds.
     */
    public static function read(string $path, int $offset = 0, ?array $base = null): ?array {
        $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return is_file($path); }
        );
        if ($exists['warning'] !== null) {
            self::reportFilesystemWarning('active-operation file existence could not be checked: ' . $path, $exists['warning']);
            return null;
        }
        if (!$exists['result']) {
            return self::emptySnapshot();
        }
        $opened = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'rb');
        $handle = $opened['result'];
        if ($handle === false) {
            self::reportFailure('active-operation file could not be read: ' . $path
                . ($opened['warning'] !== null ? ' (' . $opened['warning'] . ')' : ''));
            return null;
        }
        try {
            if ($opened['warning'] !== null) {
                self::reportFilesystemWarning('active-operation file could not be read: ' . $path, $opened['warning']);
                return null;
            }
            $statResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fstat($handle); }
            );
            $stat = $statResult['result'];
            if (!is_array($stat) || $statResult['warning'] !== null) {
                self::reportFailure('active-operation file identity could not be read: ' . $path
                    . ($statResult['warning'] !== null ? ' (' . $statResult['warning'] . ')' : ''));
                return null;
            }
            $size = is_int($stat['size'] ?? null) ? $stat['size'] : -1;
            if ($size < 0 || $size > ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_FILE_BYTES || $offset > $size) {
                self::reportFailure('active-operation file exceeds its fixed byte bound: ' . $path);
                return null;
            }
            $seekResult = $offset > 0
                ? ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(static function () use ($handle, $offset) { return fseek($handle, $offset); })
                : array('result' => 0, 'warning' => null);
            if ($offset > 0 && $seekResult['result'] !== 0) {
                self::reportFailure('active-operation file suffix could not be read: ' . $path);
                return null;
            }
            $snapshot = $base ?? self::emptySnapshot();
            $snapshot['ino'] = $stat['ino'] ?? null;
            $snapshot['dev'] = $stat['dev'] ?? null;
            $snapshot['size'] = $size;
            $snapshot['torn_tail'] = false;
            return self::readRecords($handle, $path, $snapshot);
        } finally {
            $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fclose($handle); }
            );
            self::reportFilesystemWarning('active-operation file could not be closed: ' . $path, $closed['warning']);
        }
    }

    /**
     * @param resource $handle
     * @param Snapshot $snapshot
     * @return Snapshot|null
     */
    private static function readRecords($handle, string $path, array $snapshot): ?array {
        while (true) {
            $lineResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fgets($handle, ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_RECORD_BYTES + 3); }
            );
            $line = $lineResult['result'];
            if ($lineResult['warning'] !== null) {
                self::reportFilesystemWarning('active-operation file could not be read: ' . $path, $lineResult['warning']);
                return null;
            }
            $eofResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return feof($handle); }
            );
            if ($line === false) {
                if ($eofResult['result'] !== true || $eofResult['warning'] !== null) {
                    self::reportFailure('active-operation file read stopped before a confirmed EOF: ' . $path
                        . ($eofResult['warning'] !== null ? ' (' . $eofResult['warning'] . ')' : ''));
                    return null;
                }
                break;
            }
            if ($eofResult['warning'] !== null) {
                self::reportFilesystemWarning('active-operation file EOF could not be checked: ' . $path, $eofResult['warning']);
                return null;
            }
            $parsed = self::parseLine($line, $eofResult['result'], $path);
            if ($parsed['status'] === 'torn') { $snapshot['torn_tail'] = true; break; }
            if ($parsed['status'] !== 'complete') { return null; }
            $snapshot['records'] = ABJ_404_Solution_ActiveOperationBreadcrumbState::foldEncodedRecord($snapshot['records'], $parsed['encoded']);
            $snapshot['max_sequence'] = max($snapshot['max_sequence'], $parsed['sequence']);
        }
        return $snapshot;
    }

    /**
     * The snapshot of a journal that does not exist yet.
     *
     * @return Snapshot
     */
    public static function emptySnapshot(): array {
        return array(
            'ino' => null,
            'dev' => null,
            'size' => 0,
            'max_sequence' => 0,
            'torn_tail' => false,
            'records' => array(),
        );
    }

    /**
     * Parse one bounded journal read without letting a torn final append poison
     * the complete prefix.
     *
     * @return array{status: 'complete', encoded: EncodedRecord, sequence: int}
     *   |array{status: 'torn'|'failed'}
     */
    private static function parseLine(string $line, bool $atEof, string $path): array {
        if (substr($line, -1) !== "\n") {
            if ($atEof) {
                return array('status' => 'torn');
            }
            self::reportFailure('active-operation file contains an over-budget record: ' . $path);
            return array('status' => 'failed');
        }
        $line = rtrim($line, "\r\n");
        if (strlen($line) > ABJ_404_Solution_ActiveOperationBreadcrumbState::MAX_RECORD_BYTES) {
            self::reportFailure('active-operation file contains an over-budget record: ' . $path);
            return array('status' => 'failed');
        }
        $decoded = json_decode($line, true);
                if (!is_array($decoded) || ABJ_404_Solution_ActiveOperationBreadcrumbState::recordKey($decoded) === '') {
            self::reportFailure('active-operation file contains an unparseable record: ' . $path);
            return array('status' => 'failed');
        }
        $record = array();
        foreach ($decoded as $key => $value) {
            $record[(string)$key] = $value;
        }
        $sequence = is_int($record['breadcrumb_seq'] ?? null)
            ? $record['breadcrumb_seq'] : 0;
        return array(
            'status' => 'complete',
            'encoded' => array('record' => $record, 'line' => $line),
            'sequence' => $sequence,
        );
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
