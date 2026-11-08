<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable write-ahead storage and retention for AJAX trace records.
 *
 * Records are appended and flushed to a request-local pending spool as they
 * happen, then promoted whole into one bounded, rotated JSONL journal. The
 * spool is what survives a hard worker kill: a request that never reaches
 * PHP shutdown leaves its file behind, and a later request recovers it into
 * the journal rather than losing the last thing that request was doing.
 *
 * This class holds the retention policy and nothing else. It makes no
 * decision about WHAT is worth recording -- callers hand it fully formed
 * records -- which is deliberate: the beta.1 flight recorder failed because
 * a retention rule (delete fast-completing requests) silently erased the
 * evidence of the very requests under investigation. Retention now has one
 * home, one test surface, and exactly one bound: rotation.
 *
 * Reading the journal back for support collection is
 * ABJ_404_Solution_AjaxTraceJournalReader's job. The reader learns this class's
 * file names from the constants below; this class never calls the reader.
 */
final class ABJ_404_Solution_AjaxTraceJournal {

    const JOURNAL_FILE = 'abj404_ajax_stage_trace.jsonl';
    const ROTATED_FILE = 'abj404_ajax_stage_trace.old.jsonl';
    const LOCK_FILE = 'abj404_ajax_stage_trace.lock';
    const PENDING_GLOB = 'abj404_ajax_trace_*.pending.jsonl';
    const RECOVER_PENDING_AFTER_SECONDS = 300;
    const MAX_JOURNAL_BYTES = 524288;
    const MAX_PENDING_BYTES = 32768;
    const PROMOTION_LOCK_WAIT_TIMEOUT_US = 50000;

    /** @var string Trace directory, with a trailing separator. */
    private $directory;
    /** @var string */
    private $pendingPath;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var bool One failure report per request; a broken directory must not flood the debug log. */
    private $failureReported = false;

    public function __construct(string $directory, string $pendingPath, ABJ_404_Solution_Clock $clock) {
        $this->directory = $directory;
        $this->pendingPath = $pendingPath;
        $this->clock = $clock;
    }

    public function pendingPath(): string {
        return $this->pendingPath;
    }

    /**
     * Append one fully formed record to the pending spool and flush it, so
     * the record survives a kill that never reaches PHP shutdown.
     *
     * @param array<string, mixed> $record
     */
    public function append(array $record): void {
        $pendingExists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            function () { return is_file($this->pendingPath); }
        );
        if ($pendingExists['warning'] !== null) {
            $this->reportFailure('AJAX pending trace could not be inspected: '
                . $this->pendingPath, $pendingExists['warning']);
        }
        if ($pendingExists['result']) {
            $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($this->pendingPath);
            $size = $sizeResult['result'];
            if ($sizeResult['warning'] !== null) {
                $this->reportFailure('AJAX pending trace size could not be read: '
                    . $this->pendingPath, $sizeResult['warning']);
            }
            if (is_int($size) && $size >= self::MAX_PENDING_BYTES) {
                $this->reportFailure('AJAX pending trace reached its size limit: ' . $this->pendingPath);
                return;
            }
        }
        $this->appendJsonLine($this->pendingPath, $record);
    }

    /**
     * Move the whole pending spool into the durable journal, rotating first
     * when the append would cross the size bound. Every request is promoted:
     * there is no outcome-based retention.
     */
    public function promote(): void {
        $pendingExists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            function () { return is_file($this->pendingPath); }
        );
        if (!$pendingExists['result']) {
            return;
        }
        $lockResult = ABJ_404_Solution_HostFilesystemCall::fopen($this->directory . self::LOCK_FILE, 'cb');
        $lock = $lockResult['result'];
        if ($lock === false) {
            $this->reportFailure('AJAX trace journal lock could not be opened. Pending evidence remains at '
                . $this->pendingPath, $lockResult['warning']);
            return;
        }
        if (!$this->acquirePromotionLock($lock)) {
            $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($lock): bool { return fclose($lock); }
            );
            $this->reportFailure('AJAX trace journal lock wait exceeded. Pending evidence remains at '
                . $this->pendingPath, $closed['warning']);
            return;
        }
        try {
            $contentsResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                function () { return file_get_contents($this->pendingPath); }
            );
            $contents = $contentsResult['result'];
            if (!is_string($contents)) {
                $this->reportFailure('AJAX pending trace could not be read: '
                    . $this->pendingPath, $contentsResult['warning']);
                return;
            }
            $journal = $this->directory . self::JOURNAL_FILE;
            $journalExists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($journal): bool { return is_file($journal); }
            );
            $size = 0;
            if ($journalExists['result']) {
                $journalSize = ABJ_404_Solution_HostFilesystemCall::filesize($journal);
                $size = $journalSize['result'];
            }
            if (is_int($size) && ($size + strlen($contents)) > self::MAX_JOURNAL_BYTES
                    && !$this->rotateJournal($journal)) {
                return;
            }
            $writtenResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($journal, $contents) {
                    return file_put_contents($journal, $contents, FILE_APPEND | LOCK_EX);
                }
            );
            $written = $writtenResult['result'];
            if ($written === false) {
                $this->reportFailure('AJAX trace journal append failed: ' . $journal,
                    $writtenResult['warning']);
                return;
            }
            $this->removePending();
        } finally {
            $unlocked = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($lock): bool { return flock($lock, LOCK_UN); }
            );
            $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($lock): bool { return fclose($lock); }
            );
            if ($unlocked['warning'] !== null) {
                $this->reportFailure('AJAX trace journal lock could not be released: '
                    . $this->pendingPath, $unlocked['warning']);
            }
            if ($closed['warning'] !== null) {
                $this->reportFailure('AJAX trace journal lock could not be closed: '
                    . $this->pendingPath, $closed['warning']);
            }
        }
    }

    private function rotateJournal(string $journal): bool {
        $old = $this->directory . self::ROTATED_FILE;
        $removed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($old): bool {
                return !is_file($old) || unlink($old);
            }
        );
        if (!$removed['result']) {
            $this->reportFailure('AJAX rotated trace could not be removed: ' . $old,
                $removed['warning']);
            return false;
        }
        $renamed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($journal, $old): bool {
                return !is_file($journal) || rename($journal, $old);
            }
        );
        if (!$renamed['result']) {
            $this->reportFailure('AJAX trace journal rotation failed: ' . $journal,
                $renamed['warning']);
            return false;
        }
        return true;
    }

    /**
     * Promote spools left behind by workers that died without running PHP
     * shutdown. Each one is annotated with how it ended before promotion, so
     * a reader can tell "the request was killed here" apart from "the
     * request finished here".
     */
    public function recoverAbandoned(): void {
        $matches = self::pendingPaths($this->directory . self::PENDING_GLOB);
        $cutoff = $this->clock->now() - self::RECOVER_PENDING_AFTER_SECONDS;
        $ownPending = $this->pendingPath;
        foreach ($matches as $path) {
            $pathExists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($path): bool { return is_file($path); }
            );
            $modified = false;
            if ($pathExists['result']) {
                $modifiedResult = ABJ_404_Solution_HostFilesystemCall::filemtime($path);
                $modified = $modifiedResult['result'];
            }
            if ($modified === false || $modified > $cutoff) {
                continue;
            }
            $this->pendingPath = $path;
            $handleResult = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'rb');
            $handle = $handleResult['result'];
            $firstLine = false;
            if (is_resource($handle)) {
                $firstLineResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                    static function () use ($handle) { return fgets($handle); }
                );
                $firstLine = $firstLineResult['result'];
                $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                    static function () use ($handle): bool { return fclose($handle); }
                );
                if ($closed['warning'] !== null) {
                    $this->reportFailure('Abandoned AJAX trace handle could not be closed: ' . $path,
                        $closed['warning']);
                }
            }
            $originalContext = is_string($firstLine) ? json_decode($firstLine, true) : null;
            if (is_array($originalContext)) {
                unset($originalContext['event'], $originalContext['stage'], $originalContext['elapsed_ms']);
                $this->appendJsonLine($path, array_merge($originalContext, array(
                    'ts' => $this->clock->nowFloat(),
                    'event' => 'abandoned_recovered',
                    'status' => 'worker-ended-without-shutdown',
                )));
            } else {
                $this->reportFailure('Abandoned AJAX trace context could not be parsed: ' . $path);
            }
            $this->promote();
            $this->pendingPath = $ownPending;
        }
    }

    /** @return array<int, string> */
    private static function pendingPaths(string $pattern): array {
        $result = ABJ_404_Solution_HostFilesystemCall::glob($pattern);
        if ($result['warning'] !== null && $result['warning'] !== '') {
            self::reportStaticFailure('AJAX trace pending glob failed: ' . $pattern
                . ' (' . $result['warning'] . ')');
        }
        return is_array($result['result']) ? $result['result'] : array();
    }

    /** @param array<string, mixed> $record */
    private function appendJsonLine(string $path, array $record): bool {
        $json = ABJ_404_Solution_Utf8SafeRecord::encode($record, 'AJAX trace record', JSON_UNESCAPED_SLASHES);
        if ($json === '') {
            $this->reportFailure('AJAX trace JSON encoding failed.');
            return false;
        }
        // The descriptor is held for the request rather than re-opened per
        // record, and the lock is taken on that same held descriptor. See
        // ABJ_404_Solution_DiagnosticAppendStream: this sink is lower volume
        // than the checkpoint journal, but it is the same shape and it shares
        // the fix rather than keeping a second copy of the write path.
        $acquired = ABJ_404_Solution_DiagnosticAppendStream::acquireExclusive(
            $path,
            self::PROMOTION_LOCK_WAIT_TIMEOUT_US
        );
        if ($acquired['status'] === 'failed') {
            $this->reportFailure(
                'AJAX trace file could not be opened: ' . $path,
                $acquired['warning'] !== '' ? $acquired['warning'] : null
            );
            return false;
        }
        if ($acquired['status'] === 'lock_timeout') {
            $this->reportFailure('AJAX trace file lock failed: ' . $path);
            return false;
        }
        try {
            $written = ABJ_404_Solution_DiagnosticAppendStream::append($path, $json . "\n");
            $ok = $written['status'] === 'complete';
            if (!$ok) {
                $this->reportFailure(
                    'AJAX trace append/flush failed: ' . $path,
                    $written['warning'] !== '' ? $written['warning'] : null
                );
            }
        } finally {
            ABJ_404_Solution_DiagnosticAppendStream::release($path);
        }
        return $ok;
    }

    private function removePending(): void {
        // Drop the held descriptor BEFORE unlinking: a descriptor to a deleted
        // inode accepts writes that no reader can ever find.
        ABJ_404_Solution_DiagnosticAppendStream::invalidate($this->pendingPath);
        $isFile = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            function () { return is_file($this->pendingPath); }
        );
        if ($isFile['result']) {
            $removed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                function () { return unlink($this->pendingPath); }
            );
            if (!$removed['result']) {
                $this->reportFailure('AJAX pending trace could not be removed: '
                    . $this->pendingPath, $removed['warning']);
            }
        }
    }

    /** @param resource $lock */
    private function acquirePromotionLock($lock): bool {
        $started = $this->monotonicNanoseconds();
        do {
            $locked = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($lock): bool { return flock($lock, LOCK_EX | LOCK_NB); }
            );
            if ($locked['result']) {
                return true;
            }
            if ($this->elapsedMicroseconds($started) >= self::PROMOTION_LOCK_WAIT_TIMEOUT_US) {
                return false;
            }
            usleep(1000);
        } while (true);
    }

    private function monotonicNanoseconds(): int {
        return function_exists('hrtime')
            ? (int)hrtime(true)
            : (int)round($this->clock->nowFloat() * 1000000000);
    }

    private function elapsedMicroseconds(int $started): int {
        return max(0, (int)round(($this->monotonicNanoseconds() - $started) / 1000));
    }

    private function reportFailure(string $message, ?string $nativeWarning = null): void {
        if ($this->failureReported) {
            return;
        }
        $this->failureReported = true;
        $detail = $nativeWarning === null ? '' : ' (' . $nativeWarning . ')';
        self::reportStaticFailure($message . $detail);
    }

    private static function reportStaticFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
