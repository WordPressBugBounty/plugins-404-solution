<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php and tests/LogExcerptAdminActionsTest.php through public Logging entry points.

/**
 * Reader/parser for debug-log files.
 *
 * Owns multiline ERROR/WARN parsing for cron error reports and support
 * excerpts. It does not know where the log file lives; callers provide a
 * concrete path from the debug-log store.
 *
 * Reads the file once and drives two collaborators with each chunk: the entry
 * grammar and byte bounds are ABJ_404_Solution_DebugLogEntryFormat's, and the
 * support-request window of recent entries is kept by
 * ABJ_404_Solution_DebugLogSupportExcerpt. The reader keeps the latest error,
 * its context and the recent tail.
 *
 * @phpstan-type DebugLogSnapshot array{status: string, num: int, line: string|null,
 *     total_error_count: int, file_size: int, tail: string, latest_error_offset: int,
 *     error_context_start: int, error_context: string,
 *     error_entries: array<int, array<int, string>>, recent_lines: array<int, string>}
 */
class ABJ_404_Solution_DebugLogReader {

    /** Existing wire budget shared by the recent tail and anchored evidence. */
    const REPORT_EVIDENCE_MAX_BYTES = ABJ_404_Solution_DebugLogEntryFormat::REPORT_EVIDENCE_MAX_BYTES;

    /** Maximum bytes reserved for an error that sits outside the recent tail. */
    const ERROR_EXCERPT_MAX_BYTES = ABJ_404_Solution_DebugLogEntryFormat::ERROR_EXCERPT_MAX_BYTES;

    /** Context retained immediately before the latest reportable error. */
    const ERROR_CONTEXT_PREFIX_BYTES = 8192;

    /** Maximum bytes admitted from one physical line before continuation parsing. */
    const MAX_PHYSICAL_LINE_BYTES = self::ERROR_EXCERPT_MAX_BYTES + 1;

    /** Marker retained when a reportable entry reaches its byte budget. */
    const TRUNCATION_MARKER = ABJ_404_Solution_DebugLogEntryFormat::TRUNCATION_MARKER;

    /** @var callable */
    private $errorLogger;

    /** @phpstan-var array<string, DebugLogSnapshot> request-scoped file snapshots */
    private $snapshots = array();

    /** @param callable $errorLogger Receives message and optional exception. */
    public function __construct(callable $errorLogger) {
        $this->errorLogger = $errorLogger;
    }

    /**
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>} Immutable request-scoped file snapshot.
     */
    public function getSnapshot(string $debugPath): array {
        return $this->snapshot($debugPath);
    }

    /** @return array{num: int, line: string|null, total_error_count: int} */
    public function getLatestErrorLine(string $debugPath): array {
        $evidence = $this->snapshot($debugPath);
        return array(
            'num' => $evidence['num'],
            'line' => $evidence['line'],
            'total_error_count' => $evidence['total_error_count'],
        );
    }

    /**
     * The one physical read used by error reports, support requests, previews,
     * and dedupe checks during this request.
     *
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>}
     */
    private function snapshot(string $debugFilePath): array {
        if (array_key_exists($debugFilePath, $this->snapshots)) {
            return $this->snapshots[$debugFilePath];
        }
        $openedSnapshot = $this->openSnapshotFile($debugFilePath);
        $snapshot = $this->emptySnapshot($openedSnapshot['status']);
        $snapshot['file_size'] = $openedSnapshot['file_size'];
        $handle = $openedSnapshot['handle'];
        if ($handle === null) {
            return $this->snapshots[$debugFilePath] = $snapshot;
        }

        $supportExcerpt = new ABJ_404_Solution_DebugLogSupportExcerpt();
        $collectingErrorLines = false;
        $latestErrorTruncated = false;
        $linesRead = 0;
        // Physical newline identity must remain compatible with the persisted sent-line watermark.
        $atPhysicalLineStart = true;
        $bytesRead = 0;
        $tailChunks = array();
        $tailHead = 0;
        $tailBytes = 0;
        $previousChunks = array();
        $previousHead = 0;
        $previousByteCount = 0;
        $contextActive = false;
        $readFailed = false;
        try {
            while (true) {
                $read = ABJ_404_Solution_HostFilesystemCall::fgets($handle, self::MAX_PHYSICAL_LINE_BYTES);
                if ($read['warning'] !== null) {
                    $this->reportFilesystemFailure('debug log read warning', $debugFilePath, $read['warning']);
                }
                if ($read['result'] === false) {
                    if ($read['warning'] !== null) {
                        $readFailed = true;
                    }
                    break;
                }
                $line = $read['result'];
                $line = (string)$line;
                $lineOffset = $bytesRead;
                $lineBytes = strlen($line);
                $bytesRead += $lineBytes;
                $isFirstChunkOfPhysicalLine = $atPhysicalLineStart;
                $linesRead += (int)$isFirstChunkOfPhysicalLine;
                $atPhysicalLineStart = substr($line, -1) === "\n";
                $this->appendBoundedChunk(
                    $tailChunks,
                    $tailHead,
                    $tailBytes,
                    $line,
                    self::REPORT_EVIDENCE_MAX_BYTES
                );
                $supportExcerpt->feed($line, $linesRead, $isFirstChunkOfPhysicalLine);

                $errorMarker = stripos($line, '(ERROR)');
                $isError = $errorMarker !== false
                    && $snapshot['num'] !== $linesRead
                    && stripos($line, 'SQL query error: DELETE command denied to user') === false;
                if ($isError) {
                    $snapshot['num'] = $linesRead;
                    $latestErrorTruncated = false;
                    $snapshot['line'] = ABJ_404_Solution_DebugLogEntryFormat::boundedLine($line, $latestErrorTruncated);
                    $snapshot['total_error_count']++;
                    $markerOffset = (int)$errorMarker;
                    $snapshot['latest_error_offset'] = $lineOffset + $markerOffset;
                    $lineContextStart = max(0, $markerOffset - self::ERROR_CONTEXT_PREFIX_BYTES);
                    $prefix = $lineContextStart === 0
                        ? implode('', $previousChunks) : '';
                    $snapshot['error_context_start'] = max(
                        0,
                        $lineOffset + $lineContextStart - strlen($prefix)
                    );
                    $snapshot['error_context'] = substr(
                        $prefix . substr($line, $lineContextStart),
                        0,
                        self::ERROR_EXCERPT_MAX_BYTES
                    );
                    $contextActive = strlen($snapshot['error_context']) < self::ERROR_EXCERPT_MAX_BYTES;
                    $collectingErrorLines = true;
                } else {
                    if ($collectingErrorLines
                        && $snapshot['line'] !== null
                        && ABJ_404_Solution_DebugLogEntryFormat::isContinuation($line, $isFirstChunkOfPhysicalLine)
                    ) {
                        ABJ_404_Solution_DebugLogEntryFormat::appendToText(
                            $snapshot['line'],
                            $line,
                            $latestErrorTruncated
                        );
                    } else {
                        $collectingErrorLines = false;
                    }
                    if ($contextActive) {
                        $remaining = self::ERROR_EXCERPT_MAX_BYTES - strlen($snapshot['error_context']);
                        $snapshot['error_context'] .= substr($line, 0, $remaining);
                        $contextActive = strlen($snapshot['error_context']) < self::ERROR_EXCERPT_MAX_BYTES;
                    }
                }
                $this->appendBoundedChunk(
                    $previousChunks,
                    $previousHead,
                    $previousByteCount,
                    $line,
                    self::ERROR_CONTEXT_PREFIX_BYTES
                );
            }

            $supportEvidence = $supportExcerpt->finish();
            if (!$readFailed) {
                $snapshot['status'] = 'ok';
                $snapshot['file_size'] = $bytesRead;
                $snapshot['tail'] = implode('', $tailChunks);
                $snapshot['error_entries'] = $supportEvidence['error_entries'];
                $snapshot['recent_lines'] = $supportEvidence['recent_lines'];
            } else {
                $snapshot = $this->emptySnapshot('unreadable');
            }
        } catch (\Throwable $e) {
            call_user_func(
                $this->errorLogger,
                'Error reading log file snapshot: ' . get_class($e) . ': ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null
            );
            $snapshot = $this->emptySnapshot('unreadable');
        } finally {
            $closed = ABJ_404_Solution_HostFilesystemCall::fclose($handle);
            if (!$closed['result'] || $closed['warning'] !== null) {
                $this->reportFilesystemFailure('debug log close failed', $debugFilePath, $closed['warning']);
                $snapshot = $this->emptySnapshot('unreadable');
            }
        }
        return $this->snapshots[$debugFilePath] = $snapshot;
    }

    /**
     * Admit one log file to the snapshot parser while retaining native causes.
     *
     * @return array{status: string, handle: resource|null, file_size: int}
     */
    private function openSnapshotFile(string $debugFilePath): array {
        if ($debugFilePath === '') {
            return array('status' => 'missing', 'handle' => null, 'file_size' => 0);
        }

        $exists = ABJ_404_Solution_HostFilesystemCall::fileExists($debugFilePath);
        if (!$exists['result']) {
            if ($exists['warning'] !== null) {
                $this->reportFilesystemFailure('debug log existence check failed', $debugFilePath, $exists['warning']);
            }
            return array(
                'status' => $exists['warning'] !== null ? 'unreadable' : 'missing',
                'handle' => null,
                'file_size' => 0,
            );
        }

        $fileSize = 0;
        $observedSize = ABJ_404_Solution_HostFilesystemCall::filesize($debugFilePath);
        if ($observedSize['warning'] !== null) {
            $this->reportFilesystemFailure('debug log size check failed', $debugFilePath, $observedSize['warning']);
        }
        if (is_int($observedSize['result'])) {
            $fileSize = $observedSize['result'];
        }

        $opened = ABJ_404_Solution_HostFilesystemCall::fopen($debugFilePath, 'rb');
        $handle = $opened['result'];
        if ($opened['warning'] !== null) {
            $this->reportFilesystemFailure('debug log open warning', $debugFilePath, $opened['warning']);
        }
        if (!is_resource($handle)) {
            if ($opened['warning'] === null) {
                $this->reportFilesystemFailure('debug log open failed', $debugFilePath, null);
            }
            return array('status' => 'unreadable', 'handle' => null, 'file_size' => $fileSize);
        }

        return array('status' => 'open', 'handle' => $handle, 'file_size' => $fileSize);
    }

    /**
     * Preserve a host filesystem warning without routing it through the normal
     * error logger, which would treat an expected hosting refusal as a plugin
     * error and could re-enter this reader while it is already handling I/O.
     *
     * @param string $operation
     * @param string $path
     * @param string|null $warning
     * @return void
     */
    private function reportFilesystemFailure(string $operation, string $path, ?string $warning): void {
        $detail = $warning !== null ? ' (' . $warning . ')' : ' (unknown filesystem failure)';
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $path . $detail);
            return;
        }

        call_user_func($this->errorLogger, $operation . ': ' . $path . $detail);
    }

    /**
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>}
     */
    private function emptySnapshot(string $status): array {
        return array(
            'status' => $status,
            'num' => -1,
            'line' => null,
            'total_error_count' => 0,
            'file_size' => 0,
            'tail' => '',
            'latest_error_offset' => -1,
            'error_context_start' => 0,
            'error_context' => '',
            'error_entries' => array(),
            'recent_lines' => array(),
        );
    }

    /**
     * Append without repeatedly copying the whole retained window. Numeric
     * keys may be sparse after eviction; implode intentionally ignores keys.
     *
     * @param array<int, string> $chunks
     */
    private function appendBoundedChunk(
        array &$chunks,
        int &$head,
        int &$byteCount,
        string $addition,
        int $maxBytes
    ): void {
        $chunks[] = $addition;
        $byteCount += strlen($addition);
        while ($byteCount > $maxBytes && isset($chunks[$head])) {
            $overflow = $byteCount - $maxBytes;
            $headBytes = strlen($chunks[$head]);
            if ($headBytes <= $overflow) {
                unset($chunks[$head]);
                $head++;
                $byteCount -= $headBytes;
                continue;
            }
            $trimmed = substr($chunks[$head], $overflow);
            if ($trimmed === false) {
                throw new \RuntimeException('Unable to trim bounded debug-log evidence chunk.');
            }
            $chunks[$head] = $trimmed;
            $byteCount -= $overflow;
        }
    }

}
