<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/CrashBeacon.php';
// The shutdown path must not depend on the plugin autoloader or service
// container. FileSystemService and HostFilesystemCall are pure static
// capability boundaries, so load them explicitly while the healthy boot path
// is still available.
require_once dirname(__DIR__) . '/core/FileSystemService.php';
require_once dirname(__DIR__) . '/core/HostFilesystemCall.php';

/**
 * Persists at most one pending ABJ_404_Solution_CrashBeacon to a small JSON file
 * in the plugin's uploads dir.
 *
 * Why a file (not an option or a transient): a ~200-byte file_put_contents
 * allocates far less than update_option (which can load the whole options table)
 * during an OOM, AND a file in wp-content/uploads survives a plugin update (the
 * plugin directory is replaced; uploads is not), which is what lets an
 * every-request-OOM site be reported after it recovers.
 *
 * The fatal-handler (write) path must use ONLY a precomputed primitive path; it
 * must not call wp_upload_dir()/options/the container during shutdown. Callers
 * in the write path therefore construct this with the path cached at healthy
 * boot in $GLOBALS['abj404_crash_beacon_path']. The healthy drain path may use
 * forCurrentSite(), which resolves the path through WP normally.
 */
class ABJ_404_Solution_CrashBeaconStore {

    /** Predictable filename; the file contents are PII-redacted at rest, so a
     *  predictable path in a possibly-public uploads dir discloses nothing
     *  sensitive (consistent with the sibling abj404_debug.zip). */
    const FILE_NAME = 'abj404_crash_beacon.json';

    /** Refuse to read a file larger than this. A predictable-path file could be
     *  bloated by a corrupted write or an unrelated process; a real beacon is a
     *  few hundred bytes. */
    const MAX_READ_BYTES = 8192;

    /** A short-write stream may make at most one progress call per bounded byte. */
    const MAX_WRITE_ATTEMPTS = self::MAX_READ_BYTES + 1;

    /** @var string absolute path to the beacon file. */
    private $filePath;

    /**
     * @param string $filePath absolute path to the beacon JSON file.
     */
    public function __construct(string $filePath) {
        $this->filePath = $filePath;
    }

    /**
     * Resolve the store for the current site on a HEALTHY request. Prefers the
     * path cached at boot; falls back to resolving through WP. Do NOT use this in
     * the fatal handler (it may call wp_upload_dir()).
     *
     * @return self
     */
    public static function forCurrentSite(): self {
        $cached = isset($GLOBALS['abj404_crash_beacon_path']) && is_string($GLOBALS['abj404_crash_beacon_path'])
            ? $GLOBALS['abj404_crash_beacon_path'] : '';
        if ($cached !== '') {
            return new self($cached);
        }
        $dir = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
        return new self(rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME);
    }

    /** @return string */
    public function filePath(): string {
        return $this->filePath;
    }

    /** @return bool */
    public function exists(): bool {
        $result = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return is_file($this->filePath); });
        self::reportFilesystemWarning('crash-beacon exists', $result['warning']);
        return (bool)$result['result'];
    }

    /**
     * Last-modified epoch seconds, or null if the file is absent/unstatable.
     * Used by the drain to age-gate the discard of a corrupt/partial file so a
     * file caught mid-write is not deleted before its writer finishes.
     *
     * @return int|null
     */
    public function modifiedAt(): ?int {
        $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return is_file($this->filePath); });
        self::reportFilesystemWarning('crash-beacon modifiedAt existence check', $exists['warning']);
        if (!$exists['result']) {
            return null;
        }
        $mtime = ABJ_404_Solution_HostFilesystemCall::filemtime($this->filePath);
        self::reportFilesystemWarning('crash-beacon modifiedAt stat', $mtime['warning']);
        $m = $mtime['result'];
        return $m === false ? null : (int)$m;
    }

    /**
     * Write the beacon ONLY if no beacon file already exists. Uses fopen('xb'),
     * an atomic create-only open, so two concurrent crashing requests cannot
     * both write (first crash wins, race-safe) and so we never read+allocate an
     * existing file during an OOM. All I/O is error-suppressed because this runs
     * in the fatal handler where a warning must not escalate.
     *
     * @param ABJ_404_Solution_CrashBeacon $beacon
     * @return bool true iff a new file was written.
     */
    public function recordIfAbsent(ABJ_404_Solution_CrashBeacon $beacon): bool {
        $json = json_encode($beacon->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $this->logWriteFailure('encode_failed');
            return false;
        }
        $length = strlen($json);
        if ($length < 1 || $length > self::MAX_READ_BYTES) {
            $this->logWriteFailure('size_out_of_bounds');
            return false;
        }
        $open = ABJ_404_Solution_HostFilesystemCall::fopen($this->filePath, 'xb');
        $handle = $open['result'];
        if ($handle === false) {
            // A create-only open is also the duplicate gate. Do not warn for
            // the ordinary first-crash-wins case; an absent path points to a
            // genuine storage failure worth exposing to the PHP fallback log.
            $duplicateCheck = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return is_file($this->filePath); });
            if (!$duplicateCheck['result']) {
                $this->logWriteFailure('open_failed', $open['warning'] ?? $duplicateCheck['warning']);
            }
            return false;
        }

        $write = $this->writeBeaconBody($handle, $json, $length);
        $writeComplete = $write['complete'];
        $writeWarning = $write['warning'];

        $flush = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fflush($handle); }
        );
        $flushComplete = $flush['result'];
        $close = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fclose($handle); }
        );
        $closeComplete = $close['result'];
        $writeWarning = $writeWarning ?? ($flush['warning'] ?? $close['warning']);
        if (!$writeComplete || $flushComplete !== true || $closeComplete !== true) {
            $this->logWriteFailure(
                !$writeComplete ? 'write_failed' : ($flushComplete !== true ? 'flush_failed' : 'close_failed'),
                $writeWarning
            );
            return false;
        }
        return true;
    }

    /**
     * @param resource $handle
     * @return array{complete:bool,warning:?string}
     */
    private function writeBeaconBody($handle, string $json, int $length): array {
        $offset = 0;
        $attempts = 0;
        $warning = null;
        while ($offset < $length && $attempts < self::MAX_WRITE_ATTEMPTS) {
            $remaining = substr($json, $offset);
            $write = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle, $remaining) { return fwrite($handle, $remaining); }
            );
            if ($write['warning'] !== null) {
                $warning = $write['warning'];
            }
            $attempts++;
            $written = $write['result'];
            if (!is_int($written) || $written <= 0 || $written > strlen($remaining)) {
                return array('complete' => false, 'warning' => $warning);
            }
            $offset += $written;
        }
        return array('complete' => $offset === $length, 'warning' => $warning);
    }

    /**
     * Last-resort breadcrumb for a storage failure. This path must never call
     * the plugin logger or any outbound transport while handling a fatal.
     *
     * @param string $reason
     * @return void
     */
    private function logWriteFailure(string $reason, ?string $warning = null): void {
        if (!function_exists('abj404_logPhpFallback')) {
            return;
        }
        try {
            $message = 'crash-beacon write_failed: ' . $reason;
            if ($warning !== null && $warning !== '') {
                $message .= ' (' . $warning . ')';
            }
            @abj404_logPhpFallback('fatal-handler-fallback', $message);
        } catch (\Throwable $e) {
            // The plugin fallback itself may be unavailable during shutdown.
            // Preserve its failure class and code through the independent PHP
            // error-log sink without recursively invoking the same helper.
            $loggingClass = get_class($e);
            $loggingCode = (string)$e->getCode();
            ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog('404 Solution: crash-beacon write_failed: ' . $reason
                . ' (logging_error_class=' . $loggingClass
                . ', logging_error_code=' . $loggingCode . ') [fatal-handler-fallback]');
        }
    }

    /**
     * Read the pending beacon. Runs only on a healthy request, so failures are
     * surfaced via status rather than silently suppressed.
     *
     * Status values:
     *   'absent'     no file.
     *   'oversized'  file exceeds MAX_READ_BYTES (refused).
     *   'unreadable' file present but could not be read or was empty (possibly mid-write).
     *   'future'     a newer beacon_schema_version: LEAVE IT (a compatible version drains it).
     *   'corrupt'    present, readable, but not a valid known-version beacon.
     *   'ok'         parsed.
     *
     * @return array{beacon: ABJ_404_Solution_CrashBeacon|null, status: string}
     */
    public function read(): array {
        $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return is_file($this->filePath); });
        self::reportFilesystemWarning('crash-beacon read existence check', $exists['warning']);
        if (!$exists['result']) {
            return array('beacon' => null, 'status' => 'absent');
        }
        $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($this->filePath);
        self::reportFilesystemWarning('crash-beacon read size', $sizeResult['warning']);
        $size = $sizeResult['result'];
        if ($size === false || $size > self::MAX_READ_BYTES) {
            return array('beacon' => null, 'status' => 'oversized');
        }
        $read = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return file_get_contents($this->filePath); });
        self::reportFilesystemWarning('crash-beacon read contents', $read['warning']);
        $raw = $read['result'];
        if (!is_string($raw) || $raw === '') {
            return array('beacon' => null, 'status' => 'unreadable');
        }
        $decoded = json_decode($raw, true);
        $beacon = ABJ_404_Solution_CrashBeacon::fromArray($decoded);
        if ($beacon instanceof ABJ_404_Solution_CrashBeacon) {
            return array('beacon' => $beacon, 'status' => 'ok');
        }
        if (is_array($decoded) && isset($decoded['beacon_schema_version'])
                && is_scalar($decoded['beacon_schema_version'])
                && (int)$decoded['beacon_schema_version'] > ABJ_404_Solution_CrashBeacon::SCHEMA_VERSION) {
            return array('beacon' => null, 'status' => 'future');
        }
        return array('beacon' => null, 'status' => 'corrupt');
    }

    /** Remove the pending beacon file (idempotent). @return void */
    public function clear(): void {
        $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return is_file($this->filePath); });
        self::reportFilesystemWarning('crash-beacon clear existence check', $exists['warning']);
        if ($exists['result']) {
            $unlink = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(function () { return unlink($this->filePath); });
            self::reportFilesystemWarning('crash-beacon clear', $unlink['warning']);
        }
    }

    private static function reportFilesystemWarning(string $operation, ?string $warning): void {
        if ($warning !== null && $warning !== '' && function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $warning);
        }
    }
}
