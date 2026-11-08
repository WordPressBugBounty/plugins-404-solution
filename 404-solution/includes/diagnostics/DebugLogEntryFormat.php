<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The shape of one debug-log entry: where it ends and how large it may grow.
 *
 * A reportable entry is a timestamped (ERROR) or (WARN) line plus the
 * continuation lines that follow it (stack traces, SQL, wrapped messages). Two
 * consumers read entries out of the log and must agree on that shape exactly:
 * ABJ_404_Solution_DebugLogReader keeps the latest error as one text block for
 * cron error reports, and ABJ_404_Solution_DebugLogSupportExcerpt keeps the
 * last few entries as line lists for support requests. This class is the single
 * owner of the continuation grammar, the byte bounds and the truncation marker,
 * so the two cannot drift apart. It is stateless and knows nothing about files
 * or about which entries a caller retains.
 *
 * Both consumers depend on this class and it depends on neither, which is why
 * the reader's published constants (ERROR_EXCERPT_MAX_BYTES and the others)
 * are aliases of the values defined here.
 */
final class ABJ_404_Solution_DebugLogEntryFormat {

    /** Existing wire budget shared by the recent tail and anchored evidence. */
    const REPORT_EVIDENCE_MAX_BYTES = 262144;

    /** Maximum bytes reserved for an error that sits outside the recent tail. */
    const ERROR_EXCERPT_MAX_BYTES = 65536;

    /** Marker retained when a reportable entry reaches its byte budget. */
    const TRUNCATION_MARKER = "\n[404 Solution] debug-log entry truncated.";

    /**
     * Cap one line to the per-entry byte budget.
     *
     * @param bool $truncated Set to true when the line had to be cut.
     */
    public static function boundedLine(string $line, bool &$truncated): string {
        if (strlen($line) <= self::ERROR_EXCERPT_MAX_BYTES) {
            return $line;
        }
        $truncated = true;
        $keep = max(0, self::ERROR_EXCERPT_MAX_BYTES - strlen(self::TRUNCATION_MARKER));
        return substr($line, 0, $keep) . self::TRUNCATION_MARKER;
    }

    /**
     * Does this chunk continue the entry before it, rather than start a new one?
     * Any chunk that is not the start of a physical line does; a first chunk
     * does unless it opens with the log's timestamp-and-level prefix.
     */
    public static function isContinuation(string $line, bool $isFirstChunkOfPhysicalLine): bool {
        if (!$isFirstChunkOfPhysicalLine) {
            return true;
        }
        $f = abj_service('functions');
        return !$f->regexMatch("^\d{4}[-]\d{2}[-]\d{2} .*\(\w+\):\s.*$", $line);
    }

    /**
     * Append one continuation chunk to an entry held as a single text block.
     *
     * @param bool $truncated Set to true once the entry reaches its byte budget.
     */
    public static function appendToText(string &$entry, string $line, bool &$truncated): void {
        if ($truncated) {
            return;
        }
        // Physical log lines already end in their own newline, so a
        // continuation is appended verbatim. Injecting a separator here
        // corrupted the later chunks of one over-long physical line, and
        // every consumer (dedupe signature, evidence budget, plain-text
        // support excerpt) reads the entry as text, not HTML.
        $addition = $line;
        $available = self::ERROR_EXCERPT_MAX_BYTES - strlen($entry);
        if (strlen($addition) <= $available) {
            $entry .= $addition;
            return;
        }
        $truncated = true;
        $markerBytes = strlen(self::TRUNCATION_MARKER);
        $keepExisting = max(0, self::ERROR_EXCERPT_MAX_BYTES - $markerBytes);
        $entry = substr($entry, 0, $keepExisting) . self::TRUNCATION_MARKER;
    }

    /**
     * Append one continuation chunk to an entry held as a list of lines.
     *
     * @param array<int, string> $entry
     * @param int $entryBytes Running byte count of $entry, kept in step.
     * @param bool $truncated Set to true once the entry reaches its byte budget.
     */
    public static function appendToLines(
        array &$entry,
        int &$entryBytes,
        bool &$truncated,
        string $line
    ): void {
        if ($truncated) {
            return;
        }
        if ($entryBytes >= self::ERROR_EXCERPT_MAX_BYTES) {
            self::markLinesTruncated($entry, $entryBytes, $truncated);
            return;
        }
        $available = self::ERROR_EXCERPT_MAX_BYTES - $entryBytes;
        if (strlen($line) <= $available) {
            $entry[] = $line;
            $entryBytes += strlen($line);
            return;
        }
        self::markLinesTruncated($entry, $entryBytes, $truncated);
    }

    /** @param array<int, string> $entry */
    private static function markLinesTruncated(array &$entry, int &$entryBytes, bool &$truncated): void {
        if ($truncated) {
            return;
        }
        $targetBytes = max(0, self::ERROR_EXCERPT_MAX_BYTES - strlen(self::TRUNCATION_MARKER));
        $excessBytes = max(0, $entryBytes - $targetBytes);
        while ($excessBytes > 0 && $entry !== array()) {
            $lastKey = array_key_last($entry);
            $last = (string)$entry[$lastKey];
            $lastBytes = strlen($last);
            if ($lastBytes <= $excessBytes) {
                array_pop($entry);
                $entryBytes -= $lastBytes;
                $excessBytes -= $lastBytes;
                continue;
            }
            $retainedBytes = $lastBytes - $excessBytes;
            $entry[$lastKey] = (string)substr($last, 0, $retainedBytes);
            $entryBytes -= $excessBytes;
            $excessBytes = 0;
        }
        if ($entry === array()) {
            $entry[] = self::TRUNCATION_MARKER;
        } else {
            $lastKey = array_key_last($entry);
            $entry[$lastKey] .= self::TRUNCATION_MARKER;
        }
        $entryBytes += strlen(self::TRUNCATION_MARKER);
        $truncated = true;
    }
}
