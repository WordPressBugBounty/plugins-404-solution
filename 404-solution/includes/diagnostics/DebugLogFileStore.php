<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/root-boot/EarlySettingsAccess.php';

// allow-no-test-found: covered by tests/LoggingTest.php public Logging entry-point tests for debug writes and log rotation.

/**
 * File-store for the plugin debug log.
 *
 * Owns debug-log path derivation, sanitized line writes, rotation/deletion,
 * size accounting, and the dedupe-pointer reset that must happen whenever
 * line numbers become invalid. It intentionally does not decide when to log
 * or send feedback reports; ABJ_404_Solution_Logging remains that facade.
 * Concurrent requests may overshoot the bound by their appends between size checks.
 * Simultaneous rotations may lose the older _old.txt archive.
 */
class ABJ_404_Solution_DebugLogFileStore {

    /** Hard bound for the active file; cron rotation remains as a repair path. */
    const MAX_DEBUG_FILE_BYTES = 10 * 1024 * 1000;

    /** @var callable */
    private $sanitizeLogLine;

    /** @var ABJ_404_Solution_LoggingStateStore The single recursion-safe chokepoint for logging-owned scalars. */
    private $stateStore;

    /**
     * Per-blog memo of the resolved debug filename. Logging writes one file
     * for the lifetime of a single blog context, so the key is read/generated
     * once per blog and reused; this also keeps the filename stable when the
     * underlying option write does not round-trip within the request (e.g. a
     * deferred object cache), which would otherwise make getDebugFilename()
     * regenerate the key and have deleteDebugFile() wipe a file just written.
     * Cleared by deleteDebugFile(). Scoped to the blog id active at cache time
     * (not just the request) because the underlying debug_file_key lives in
     * abj404_settings, a per-blog option: a multisite background batch that
     * switch_to_blog()s mid-request would otherwise permanently pin the
     * memoized filename to whichever blog happened to trigger the first
     * resolution, silently splitting the debug log across two files once the
     * blog context restores.
     *
     * @var string|null
     */
    private $cachedDebugFilename = null;

    /** @var int|null Blog id $cachedDebugFilename was resolved for. */
    private $cachedDebugFilenameBlogId = null;

    /** @var string|null Path whose size is memoized for this request. */
    private $knownFilePath = null;

    /** @var int|null Size of $knownFilePath including this request's appends. */
    private $knownFileBytes = null;

    /**
     * @param callable $sanitizeLogLine Receives a raw line and returns the sanitized line to write.
     * @param ABJ_404_Solution_LoggingStateStore $stateStore Recursion-safe accessor for the
     *        debug-file suffix and last-sent-line scalars (raw read/write only).
     */
    public function __construct(callable $sanitizeLogLine, ABJ_404_Solution_LoggingStateStore $stateStore) {
        $this->sanitizeLogLine = $sanitizeLogLine;
        $this->stateStore = $stateStore;
    }

    /**
     * Write one sanitized line to the active debug file.
     *
     * @param string $line
     * @param string $debugFilePath
     * @return bool True on success, false on disk/permission failure.
     */
    public function writeLine(string $line, string $debugFilePath): bool {
        $sanitizedLine = (string)call_user_func($this->sanitizeLogLine, $line);
        $serializedLine = $sanitizedLine . "\n";
        $serializedBytes = strlen($serializedLine);
        if ($serializedBytes > self::MAX_DEBUG_FILE_BYTES) {
            $serializedLine = substr($serializedLine, 0, self::MAX_DEBUG_FILE_BYTES);
            $serializedBytes = strlen($serializedLine);
        }
        if ($this->knownFilePath !== $debugFilePath || $this->knownFileBytes === null) {
            $this->knownFilePath = $debugFilePath;
            $this->knownFileBytes = $this->readFileSize($debugFilePath);
        }
        $flags = FILE_APPEND;
        $sentFilePath = null;
        if ($this->knownFileBytes + $serializedBytes > self::MAX_DEBUG_FILE_BYTES) {
            // Another request may already have rotated the file since our first write.
            $this->knownFileBytes = $this->readFileSize($debugFilePath);
            if ($this->knownFileBytes + $serializedBytes > self::MAX_DEBUG_FILE_BYTES) {
                $sentFilePath = $this->getDebugFilePathSentFile();
                $rotated = $this->rotateFiles(
                    $sentFilePath,
                    $debugFilePath . '_old.txt',
                    $debugFilePath
                );
                if (!$rotated) {
                    clearstatcache(true, $debugFilePath);
                    if (file_exists($debugFilePath)) {
                        // Keep the newest line when the archive cannot be replaced.
                        $flags = 0;
                    }
                }
                $this->knownFileBytes = 0;
            }
        }
        $write = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($debugFilePath, $serializedLine, $flags) {
                return file_put_contents($debugFilePath, $serializedLine, $flags);
            }
        );
        if ($flags === 0 && $sentFilePath !== null && is_int($write['result'])) {
            $this->resetSentLineCursors($sentFilePath);
        }
        if (!is_int($write['result']) || $write['result'] !== $serializedBytes) {
            $this->knownFileBytes = null;
            $this->reportWriteFailure($debugFilePath, $write['warning']);
            return false;
        }
        $this->knownFileBytes += $write['result'];
        return true;
    }

    /** Read once per path, then only when a write would exceed the bound. */
    private function readFileSize(string $debugFilePath): int {
        clearstatcache(true, $debugFilePath);
        $exists = ABJ_404_Solution_HostFilesystemCall::fileExists($debugFilePath);
        if ($exists['warning'] !== null) {
            abj404_logPhpFallback('logger-internal', 'Unable to inspect debug log: '
                . $debugFilePath . ' (' . $exists['warning'] . ')');
        }
        if ($exists['result'] !== true) {
            return 0;
        }
        $size = ABJ_404_Solution_HostFilesystemCall::filesize($debugFilePath);
        if ($size['warning'] !== null) {
            abj404_logPhpFallback('logger-internal', 'Unable to read debug log size: '
                . $debugFilePath . ' (' . $size['warning'] . ')');
        }
        return is_int($size['result']) ? max(0, $size['result']) : 0;
    }

    private function reportWriteFailure(string $debugFilePath, ?string $warning): void {
        abj404_logPhpFallback(
            'logger-internal',
            'Unable to write to debug log (possibly disk full): ' . $debugFilePath
            . ($warning !== null ? ' (' . $warning . ')' : '')
        );
    }

    /** @return string */
    public function getDebugFilePath(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), $this->getDebugFilename());
    }

    /** @return string */
    public function getDebugFilename(): string {
        $currentBlogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
        if ($this->cachedDebugFilename !== null && $this->cachedDebugFilenameBlogId === $currentBlogId) {
            return $this->cachedDebugFilename;
        }
        // A blog switch mid-request (multisite background batch) re-derives the
        // filename for the newly-active blog without wiping files: the glob
        // delete below is orphan cleanup for a genuinely fresh boot, not a
        // routine step of every blog switch. On a shared-uploads-dir multisite
        // config (the UPLOADS constant), the glob is not blog-scoped the way
        // abj404_getUploadsDir() usually is, so running it on every keyless
        // blog visited mid-request could delete another blog's in-use file.
        $isFirstResolutionThisRequest = ($this->cachedDebugFilename === null);
        try {
            // Logging MUST read its metadata via the raw, side-effect-free
            // state store, never getOptions(). getOptions() runs the normalize
            // pipeline, which logs a warning on any schema-validation failure;
            // that warning re-enters this method and recurses without bound
            // until memory is exhausted (the 4.3.0 "broken sites after the
            // latest update" OOM at PluginLogicOptionsResolver line ~250). The
            // store reaches storage with the raw accessor only.
            $debugFileKey = $this->stateStore->getDebugFileKey();

            if ($debugFileKey === null || trim($debugFileKey) === '') {
                if ($isFirstResolutionThisRequest) {
                    $this->deleteDebugFile();
                }

                $syncUtils = abj_service('sync_utils');
                if (!is_object($syncUtils) || !method_exists($syncUtils, 'uniqidReal')) {
                    return 'abj404_debug.txt';
                }
                $debugFileKey = $syncUtils->uniqidReal();
                $this->stateStore->setDebugFileKey($debugFileKey);
            }

            $this->cachedDebugFilename = 'abj404_debug_' . $debugFileKey . '.txt';
            $this->cachedDebugFilenameBlogId = $currentBlogId;
            return $this->cachedDebugFilename;
        } catch (\Throwable $e) { // allow-silent-catch: debug filename derivation; fallback name keeps logging available during degraded boot
            return 'abj404_debug.txt';
        }
    }

    /** @return string */
    public function getDebugFilePathOld(): string {
        return $this->getDebugFilePath() . "_old.txt";
    }

    /** @return string */
    public function getDebugFilePathSentFile(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug_sent_line.txt');
    }

    /** @return string */
    public function getZipFilePath(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug.zip');
    }

    /**
     * Create the storage directory and migrate a legacy root-level file if present.
     *
     * @param string $directory
     * @param string $filename
     * @return string
     */
    public function getFilePathAndMoveOldFile($directory, $filename): string {
        if (!ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
            return ABJ_404_Solution_FallbackStateDirectory::path() . $filename;
        }

        $legacyPath = ABJ_404_Solution_FallbackStateDirectory::path() . $filename;
        $destinationPath = $directory . $filename;
        $legacyExists = ABJ_404_Solution_HostFilesystemCall::fileExists($legacyPath);
        if (!$legacyExists['result']) {
            if ($legacyExists['warning'] !== null) {
                abj404_logPhpFallback(
                    'logger-internal',
                    'Unable to inspect legacy debug log: ' . $legacyPath . ' (' . $legacyExists['warning'] . ')'
                );
            }
            return $destinationPath;
        }

        $moved = ABJ_404_Solution_HostFilesystemCall::rename($legacyPath, $destinationPath);
        if (!$moved['result']) {
            abj404_logPhpFallback(
                'logger-internal',
                'Unable to move legacy debug log: ' . $legacyPath
                . ($moved['warning'] !== null ? ' (' . $moved['warning'] . ')' : ' (unknown rename failure)')
            );
            $remaining = ABJ_404_Solution_HostFilesystemCall::fileExists($legacyPath);
            if ($remaining['warning'] !== null) {
                abj404_logPhpFallback(
                    'logger-internal',
                    'Unable to verify legacy debug log after move failure: ' . $legacyPath
                    . ' (' . $remaining['warning'] . ')'
                );
            }
            // A denied re-probe cannot prove that the source disappeared. Keep
            // returning the legacy path so callers do not switch to an
            // unusable destination after a failed migration.
            return $remaining['result'] || $remaining['warning'] !== null
                ? $legacyPath
                : $destinationPath;
        }

        return $destinationPath;
    }

    /** @return void */
    public function limitDebugFileSize(string $sentFilePath, string $oldDebugFilePath, string $debugFilePath): void {
        $this->rotateFiles($sentFilePath, $oldDebugFilePath, $debugFilePath);
    }

    private function rotateFiles(string $sentFilePath, string $oldDebugFilePath, string $debugFilePath): bool {
        $moved = ABJ_404_Solution_HostFilesystemCall::rename($debugFilePath, $oldDebugFilePath);
        if (!$moved['result']) {
            abj404_logPhpFallback(
                'logger-internal',
                'Unable to rotate debug log: ' . $debugFilePath
                . ($moved['warning'] !== null ? ' (' . $moved['warning'] . ')' : ' (unknown rename failure)')
            );
            return false;
        }

        $this->resetSentLineCursors($sentFilePath);
        return true;
    }

    private function resetSentLineCursors(string $sentFilePath): void {
        $sentExists = ABJ_404_Solution_HostFilesystemCall::fileExists($sentFilePath);
        if ($sentExists['result']) {
            ABJ_404_Solution_FileSystemService::safeUnlink($sentFilePath);
        } elseif ($sentExists['warning'] !== null) {
            abj404_logPhpFallback(
                'logger-internal',
                'Unable to inspect sent debug-log cursor: ' . $sentFilePath . ' (' . $sentExists['warning'] . ')'
            );
        }
        $this->removeLastSentErrorLineFromDatabase();
    }

    /** @return void */
    public function removeLastSentErrorLineFromDatabase(): void {
        // Raw write only via the recursion-safe state store -- logging metadata
        // must not pass through the getOptions()/updateOptions() normalize-and-
        // log pipeline (recursion).
        $this->stateStore->setLastSentLine(0);
    }

    /** @return bool true if every matching debug file was deleted. */
    public function deleteDebugFile(): bool {
        $allIsWell = true;

        $sentFilePath = $this->getDebugFilePathSentFile();
        $this->resetSentLineCursors($sentFilePath);

        $uploadDir = abj404_getUploadsDir();
        $uploadDirectory = ABJ_404_Solution_HostFilesystemCall::isDir($uploadDir);
        if ($uploadDirectory['warning'] !== null) {
            abj404_logPhpFallback(
                'logger-internal',
                'Unable to inspect debug-log directory: ' . $uploadDir . ' (' . $uploadDirectory['warning'] . ')'
            );
            $allIsWell = false;
        }
        if ($uploadDirectory['result']) {
            $globbed = ABJ_404_Solution_HostFilesystemCall::glob($uploadDir . '/abj404_debug_*.txt');
            if ($globbed['warning'] !== null) {
                abj404_logPhpFallback(
                    'logger-internal',
                    'Unable to enumerate debug logs: ' . $uploadDir . ' (' . $globbed['warning'] . ')'
                );
                $allIsWell = false;
            }
            $files = $globbed['result'];
            if (!is_array($files)) {
                $files = array();
            }
            foreach ($files as $file) {
                $isFile = ABJ_404_Solution_HostFilesystemCall::isFile($file);
                if ($isFile['warning'] !== null) {
                    abj404_logPhpFallback(
                        'logger-internal',
                        'Unable to inspect debug log candidate: ' . $file . ' (' . $isFile['warning'] . ')'
                    );
                    $allIsWell = false;
                }
                if ($isFile['result'] && !ABJ_404_Solution_FileSystemService::safeUnlink($file)) {
                    $allIsWell = false;
                }
            }
        }

        // Raw write only via the recursion-safe state store -- clearing the
        // debug-file key must not pass through getOptions()/updateOptions(),
        // whose normalize step logs on validation failure and would re-enter
        // logging from this delete-during-logging path (the 4.3.0 recursion).
        // The in-request filename memo is dropped too so the next
        // getDebugFilename() re-derives the key.
        $this->stateStore->setDebugFileKey(null);
        $this->cachedDebugFilename = null;
        $this->cachedDebugFilenameBlogId = null;

        return $allIsWell;
    }

    /** @return int file size in bytes */
    public function getDebugFileSize(string $debugFilePath, string $oldDebugFilePath): int {
        $file1Size = 0;
        $file2Size = 0;
        // allow-silent-error: the debug file can be rotated or deleted between file_exists and filesize; a missing file counts as 0 bytes.
        $debugExists = ABJ_404_Solution_HostFilesystemCall::fileExists($debugFilePath);
        if ($debugExists['warning'] !== null) {
            abj404_logPhpFallback('logger-internal', 'Unable to inspect debug log: '
                . $debugFilePath . ' (' . $debugExists['warning'] . ')');
        }
        if ($debugExists['result']) {
            $size = ABJ_404_Solution_HostFilesystemCall::filesize($debugFilePath);
            $file1Size = is_int($size['result']) ? $size['result'] : 0;
            if ($size['warning'] !== null) {
                abj404_logPhpFallback('logger-internal', 'Unable to read debug log size: '
                    . $debugFilePath . ' (' . $size['warning'] . ')');
            }
        }
        $oldExists = ABJ_404_Solution_HostFilesystemCall::fileExists($oldDebugFilePath);
        if ($oldExists['warning'] !== null) {
            abj404_logPhpFallback('logger-internal', 'Unable to inspect rotated debug log: '
                . $oldDebugFilePath . ' (' . $oldExists['warning'] . ')');
        }
        if ($oldExists['result']) {
            $size = ABJ_404_Solution_HostFilesystemCall::filesize($oldDebugFilePath);
            $file2Size = is_int($size['result']) ? $size['result'] : 0;
            if ($size['warning'] !== null) {
                abj404_logPhpFallback('logger-internal', 'Unable to read debug log size: '
                    . $oldDebugFilePath . ' (' . $size['warning'] . ')');
            }
        }

        return $file1Size + $file2Size;
    }
}
