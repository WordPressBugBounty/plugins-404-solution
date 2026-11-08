<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists pre-operation checkpoint intent without touching WordPress paths.
 *
 * The sink is install-scoped and lives directly in the existing system temp
 * directory. Appends use O_APPEND and never wait for a lock; the best-effort
 * lock is used only to keep the bounded current/rotated pair orderly.
 */
final class ABJ_404_Solution_CheckpointIntentStore {

    /**
     * Retain at least the ordinary checkpoint journal's measured session.
     *
     * This sink is deliberately independent of the ordinary writer, so the
     * value stays literal instead of loading that class before the first
     * fixed-temp append. CheckpointIntentStoreTest pins the two retention
     * windows together. A smaller window lets sibling AJAX traffic rotate an
     * unmatched pre-directory intent away while the same session remains in
     * the ordinary journal and before a support request can rank it.
     */
    const MAX_BYTES = 4194304;

    /**
     * Append one intent record. Never throws.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function append(array $record): array {
        $startedNs = self::monotonicNanoseconds();
        $requestId = self::recordString($record, 'request_id', 'unknown00');
        $event = self::recordString($record, 'intended_event', 'unknown');
        try {
            $paths = self::pathMap();
            if ($paths === null) {
                self::reportFailure('[warning] AJAX checkpoint intent system temp directory is unavailable.');
                return self::result('failed', 'temp_directory_unavailable',
                    $requestId, $event, $startedNs);
            }
            $json = ABJ_404_Solution_Utf8SafeRecord::encode($record, 'AJAX checkpoint intent record', JSON_UNESCAPED_SLASHES);
            if ($json === '') {
                self::reportFailure('AJAX checkpoint intent JSON encoding failed.');
                return self::result('failed', 'json_encode_failed',
                    $requestId, $event, $startedNs);
            }
            $outcome = self::appendLine($paths, $json . "\n");
            return self::result($outcome['status'], $outcome['reason'],
                $requestId, $event, $startedNs);
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint intent append failed: ' . $e->getMessage());
            return self::result('failed', 'unexpected_failure',
                $requestId, $event, $startedNs);
        }
    }

    /**
     * Existing fallback files, oldest first, for support collection.
     *
     * @return array<int, string>
     */
    public static function paths(): array {
        return array_values(array_filter(self::candidatePaths(), static function (string $path): bool {
            clearstatcache(true, $path);
            $isFile = ABJ_404_Solution_HostFilesystemCall::isFile($path);
            if ($isFile['warning'] !== null && $isFile['warning'] !== '') {
                self::reportFailure('AJAX checkpoint intent path check failed: ' . $path . ' (' . $isFile['warning'] . ')');
            }
            return $isFile['warning'] === null && (bool)$isFile['result'];
        }));
    }

    /**
     * Deterministic fallback candidates for cleanup and failure verification.
     *
     * @return array<int, string>
     */
    public static function candidatePaths(): array {
        $paths = self::pathMap();
        return $paths === null ? array() : array($paths['rotated'], $paths['current']);
    }

    /**
     * @param array{current: string, rotated: string, lock: string} $paths
     * @return array{status: string, reason: string}
     */
    private static function appendLine(array $paths, string $line): array {
        // Tracked by the request-scoped descriptor rather than stat()ed per
        // record. See ABJ_404_Solution_DiagnosticAppendStream for why: this
        // sink takes one write per checkpoint, and a checkpoint-heavy request
        // writes thousands.
        $warningMessages = array_fill(0, 0, '');
        $warningHandler = static function (int $severity, string $message) use (&$warningMessages): bool {
            if (($severity & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE)) !== 0) {
                $warningMessages[] = $message;
                return true;
            }
            return false;
        };
        set_error_handler($warningHandler, E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE);
        try {
            // This handler owns only the synchronous sink operations below.
            // Its local message keeps repeated identical warnings attributable
            // without changing the caller's last-error snapshot.
            $size = ABJ_404_Solution_DiagnosticAppendStream::sizeOf($paths['current']);
            if (($size + strlen($line)) > self::MAX_BYTES) {
                self::rotate($paths, strlen($line), $warningMessages);
            }
            $outcome = self::writeLine($paths['current'], $line, $warningMessages);
            if ($outcome['status'] === 'complete' && $warningMessages !== array()) {
                self::reportFailure('AJAX checkpoint intent completed with filesystem warnings: '
                    . self::warningSuffix($warningMessages));
            }
            return $outcome;
        } finally {
            // Restore the request's previous handler and its error mask before
            // returning to the caller, including every failure path above.
            restore_error_handler();
        }
    }

    /**
     * @param array{current: string, rotated: string, lock: string} $paths
     * @param list<string> $warningMessages
     */
    private static function rotate(array $paths, int $incomingBytes, array &$warningMessages): void {
        $opened = ABJ_404_Solution_HostFilesystemCall::fopen($paths['lock'], 'cb');
        if ($opened['warning'] !== null && $opened['warning'] !== '') {
            $warningMessages[] = $opened['warning'];
        }
        $lock = $opened['result'];
        if ($lock === false) {
            self::reportFailure('AJAX checkpoint intent rotation lock could not be opened: '
                . $paths['lock'] . self::warningSuffix($warningMessages));
            return;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            clearstatcache(true, $paths['current']);
            $isCurrent = is_file($paths['current']);
            $size = false;
            if ($isCurrent) {
                $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($paths['current']);
                if ($sizeResult['warning'] !== null && $sizeResult['warning'] !== '') {
                    $warningMessages[] = $sizeResult['warning'];
                }
                $size = $sizeResult['warning'] === null ? $sizeResult['result'] : false;
            }
            if (!is_int($size) || ($size + $incomingBytes) <= self::MAX_BYTES) {
                return;
            }
            if (is_file($paths['rotated']) && !unlink($paths['rotated'])) {
                self::reportFailure('AJAX checkpoint intent rotated file could not be deleted: '
                    . $paths['rotated'] . self::warningSuffix($warningMessages));
                return;
            }
            if (is_file($paths['current']) && !rename($paths['current'], $paths['rotated'])) {
                self::reportFailure('AJAX checkpoint intent file could not be rotated: '
                    . $paths['current'] . self::warningSuffix($warningMessages));
            }
        } finally {
            // Whatever happened above, the held descriptor may now name the
            // rotated file. Drop it so the next intent re-resolves the path.
            ABJ_404_Solution_DiagnosticAppendStream::invalidate($paths['current']);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    /**
     * @param list<string> $warningMessages
     * @return array{status: string, reason: string}
     */
    private static function writeLine(string $path, string $line, array &$warningMessages): array {
        $result = ABJ_404_Solution_DiagnosticAppendStream::append($path, $line);
        if ($result['status'] === 'complete') {
            return array('status' => 'complete', 'reason' => '');
        }
        // The append stream captures its own native warnings, so the handler
        // that fills $warningMessages never sees them: carry the cause over.
        if ($result['warning'] !== '') {
            $warningMessages[] = $result['warning'];
        }
        if ($result['reason'] === 'open_failed') {
            self::reportFailure('AJAX checkpoint intent file could not be opened: '
                . $path . self::warningSuffix($warningMessages));
            return array('status' => 'failed', 'reason' => 'intent_open_failed');
        }
        self::reportFailure('AJAX checkpoint intent append/flush failed: '
            . $path . self::warningSuffix($warningMessages));
        return array('status' => 'failed', 'reason' => 'intent_append_failed');
    }

    /** @return array{current: string, rotated: string, lock: string}|null */
    private static function pathMap(): ?array {
        // An update may replace this caller after the old adapter was loaded.
        $temporary = is_callable(array(ABJ_404_Solution_PhpRuntimeCapabilityAdapter::class, 'temporaryDirectory'))
            ? ABJ_404_Solution_PhpRuntimeCapabilityAdapter::temporaryDirectory() : null;
        $directory = rtrim($temporary ?? '', '/\\');
        // A captured denial means the system temp directory is unavailable;
        // this method returns null without allowing the warning to escape.
        if ($directory === '') {
            return null;
        }
        $directoryCheck = ABJ_404_Solution_HostFilesystemCall::isDir($directory);
        if ($directoryCheck['warning'] !== null && $directoryCheck['warning'] !== '') {
            self::reportFailure('AJAX checkpoint intent temp directory check failed: '
                . $directory . ' (' . $directoryCheck['warning'] . ')');
        }
        if ($directoryCheck['warning'] !== null || !$directoryCheck['result']) {
            return null;
        }
        $scope = defined('ABSPATH') ? (string)ABSPATH : __DIR__;
        $digest = strtr(hash('sha256', $scope), '0123456789', 'abcdefghij');
        $stem = $directory . DIRECTORY_SEPARATOR . 'abj-checkpoint-intent-' . $digest;
        return array(
            'current' => $stem . '.jsonl',
            'rotated' => $stem . '.old.jsonl',
            'lock' => $stem . '.lock',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(
        string $status,
        string $reason,
        string $requestId,
        string $event,
        int $startedNs
    ): array {
        $result = array('status' => $status, 'request_id' => $requestId,
            'event' => $event, 'elapsed_us' => self::elapsedMicroseconds($startedNs));
        if ($reason !== '') {
            $result['reason'] = $reason;
        }
        return $result;
    }

    /** @param array<string, mixed> $record */
    private static function recordString(array $record, string $key, string $fallback): string {
        return isset($record[$key]) && is_string($record[$key]) ? $record[$key] : $fallback;
    }

    /** @param list<string> $warningMessages */
    private static function warningSuffix(array $warningMessages): string {
        if ($warningMessages === array()) {
            return '';
        }
        return ' (' . implode('; ', $warningMessages) . ')';
    }

    private static function monotonicNanoseconds(): int {
        return function_exists('hrtime') ? (int)hrtime(true) : 0;
    }

    private static function elapsedMicroseconds(int $startedNs): int {
        return max(0, (int)round((self::monotonicNanoseconds() - $startedNs) / 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
