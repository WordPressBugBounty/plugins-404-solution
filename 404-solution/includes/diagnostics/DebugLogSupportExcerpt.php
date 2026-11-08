<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects the support-request view of a debug log while the reader streams it.
 *
 * Fed one physical chunk at a time by ABJ_404_Solution_DebugLogReader, it keeps
 * two bounded windows: the most recent reportable (ERROR) and (WARN) entries,
 * each with its continuation lines, and the last few raw lines. It owns only
 * that state machine; where an entry ends and how large it may grow is
 * ABJ_404_Solution_DebugLogEntryFormat's, and opening or reading the file is
 * the reader's. The dependency is one-directional: the reader drives this
 * collector and this collector never calls back into it.
 */
final class ABJ_404_Solution_DebugLogSupportExcerpt {

    /** @var array<int, array<int, string>> */
    private $errorEntries = array();

    /** @var array<int, string> */
    private $recentLines = array();

    /** @var int */
    private $recentBytes = 0;

    /** @var array<int, string> */
    private $currentEntry = array();

    /** @var int */
    private $currentEntryBytes = 0;

    /** @var bool */
    private $currentEntryTruncated = false;

    /** @var bool */
    private $collectingEntry = false;

    /** @var int */
    private $lastReportablePhysicalLine = -1;

    /**
     * Take one chunk of the log, in file order.
     *
     * @param int $physicalLine 1-based number of the physical line this chunk is part of.
     */
    public function feed(string $line, int $physicalLine, bool $isFirstChunkOfPhysicalLine): void {
        $this->rememberRecentLine($line);

        if ($this->isReportableWarningOrError($line)
            && $this->lastReportablePhysicalLine !== $physicalLine
        ) {
            if ($this->collectingEntry) {
                $this->storeCurrentEntry();
            }
            $this->currentEntryTruncated = false;
            $this->currentEntry = array(
                ABJ_404_Solution_DebugLogEntryFormat::boundedLine($line, $this->currentEntryTruncated)
            );
            $this->currentEntryBytes = strlen($this->currentEntry[0]);
            $this->collectingEntry = true;
            $this->lastReportablePhysicalLine = $physicalLine;
            return;
        }

        if ($this->collectingEntry
            && ABJ_404_Solution_DebugLogEntryFormat::isContinuation($line, $isFirstChunkOfPhysicalLine)
        ) {
            ABJ_404_Solution_DebugLogEntryFormat::appendToLines(
                $this->currentEntry,
                $this->currentEntryBytes,
                $this->currentEntryTruncated,
                $line
            );
            return;
        }

        if ($this->collectingEntry) {
            $this->storeCurrentEntry();
        }
        $this->collectingEntry = false;
        $this->currentEntry = array();
        $this->currentEntryBytes = 0;
        $this->currentEntryTruncated = false;
    }

    /**
     * Close the entry still being collected and hand over both windows.
     *
     * @return array{error_entries: array<int, array<int, string>>, recent_lines: array<int, string>}
     */
    public function finish(): array {
        $this->storeCurrentEntry();
        return array(
            'error_entries' => $this->errorEntries,
            'recent_lines' => $this->recentLines,
        );
    }

    private function rememberRecentLine(string $line): void {
        $truncated = false;
        $bounded = ABJ_404_Solution_DebugLogEntryFormat::boundedLine($line, $truncated);
        $this->recentLines[] = $bounded;
        $this->recentBytes += strlen($bounded);
        while ($this->recentBytes > ABJ_404_Solution_DebugLogEntryFormat::REPORT_EVIDENCE_MAX_BYTES
            || count($this->recentLines) > 20
        ) {
            $removed = array_shift($this->recentLines);
            if (is_string($removed)) {
                $this->recentBytes -= strlen($removed);
            }
        }
    }

    private function isReportableWarningOrError(string $line): bool {
        $hasError = stripos($line, '(ERROR)') !== false;
        $hasWarn = stripos($line, '(WARN)') !== false;
        $isDeleteError = stripos($line, 'SQL query error: DELETE command denied to user') !== false;
        return ($hasError || $hasWarn) && !$isDeleteError;
    }

    private function storeCurrentEntry(): void {
        if (empty($this->currentEntry)) {
            return;
        }
        $this->errorEntries[] = $this->currentEntry;
        if (count($this->errorEntries) > 15) {
            array_shift($this->errorEntries);
        }
        $this->currentEntry = array();
        $this->currentEntryBytes = 0;
        $this->currentEntryTruncated = false;
    }
}
