<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One open append handle per diagnostic sink, for the life of the request.
 *
 * WHY THIS EXISTS. Every diagnostic journal in this plugin was an append-only
 * JSONL file written the same way: open the file, write one line, flush it,
 * close the file, once per record. That is correct and it is durable, and on a
 * plugin-heavy site it is also the single most expensive thing debug_mode does.
 * Measured on the owner's localhost on 2026-08-10 (PHP 8.5, APFS,
 * deliverables/t_260809_224020_311/write-path-microbench.log), replaying one
 * instrumented table request's 2,200 records through both sinks:
 *
 *   open/close per record      986.7 ms wall, 484.0 ms SYSTEM cpu, 448.5 us/record
 *   request-scoped handle       54.5 ms wall,  34.6 ms SYSTEM cpu,  24.8 us/record
 *
 * 18x, and the difference is almost entirely system cpu, which is what a
 * syscall costs. The record content, the record COUNT, the per-record flush and
 * the advisory locking are identical in both arms: the only thing removed is
 * re-opening a file this process already had open. Nothing about the evidence
 * changes, which is why this is the fix rather than recording less.
 *
 * DURABILITY IS UNCHANGED, and that is the whole design constraint. These
 * journals exist to explain a request that DIED, so nothing here may buffer a
 * record in memory: append() still issues one write and one flush per record,
 * so a SIGKILL milliseconds later still leaves every record that was handed to
 * this class visible to a separate process. Holding the descriptor open is not
 * buffering; it removes the open, not the write.
 *
 * WHAT A HELD DESCRIPTOR HAS TO DEFEND AGAINST. A per-record fopen() resolves
 * the PATH every time, so it silently did the right thing when a sibling
 * request rotated the file out from under it. A held descriptor follows the
 * INODE instead, and would keep appending to a file that has been renamed away
 * or deleted outright. Those two are NOT the same failure and do not get the
 * same guard, because a rename retains the records and a delete destroys them:
 *
 *   1. Every append, one fstat asks whether the file still has a directory
 *      entry at all (DiagnosticSinkIdentity::isUnlinked). It does not after a
 *      delete, and writes through the descriptor then succeed into an inode no
 *      reader can reach, which breaks the per-record durability contract
 *      above. So this one cannot be amortized: a bounded window here would
 *      lose whole windows of evidence silently. nlink survives a rename, so it
 *      never fires on (2).
 *   2. Every REVALIDATE_AFTER_APPENDS appends, one fstat/stat pair checks that
 *      the descriptor still names the path. That bounds mis-targeted records to
 *      one revalidation window, and they land in the rotated file rather than
 *      being lost, since rotation renames rather than deletes.
 *   3. A caller that rotates the file itself calls invalidate() and gets a
 *      fresh descriptor on the next append.
 *
 * The size the caller enforces its cap against is tracked here rather than
 * stat()ed per record, seeded from one fstat() at open and re-seeded at each
 * revalidation, so concurrent writers cannot make the cap drift by more than
 * one window either.
 *
 * The open-path cache is bounded (MAX_OPEN_PATHS) and closes least-recently-
 * used first, because a php-fpm worker outlives the request that opened a
 * handle, and a diagnostic must never be the reason a worker runs out of
 * descriptors.
 */
final class ABJ_404_Solution_DiagnosticAppendStream {

    /**
     * How many sinks may be held open at once. Four exist today (checkpoint
     * journal, intent store, stage trace, and their lock files); the headroom
     * is for the next channel, not for unbounded growth.
     */
    const MAX_OPEN_PATHS = 12;

    /** Appends between descriptor/path identity revalidations. */
    const REVALIDATE_AFTER_APPENDS = 256;

    /**
     * @var array<string, array{handle: resource, bytes: int, appends: int, locked: int}>
     *   Open sinks, most-recently-used last (PHP arrays keep insertion order,
     *   and touch() reinserts).
     */
    private static $streams = array();

    /**
     * @var array<string, int> How many times each path has been opened this
     *   request. Retained after a close: a rising count is real evidence of
     *   rotation churn or descriptor pressure, so the writers report it.
     */
    private static $opens = array();

    /**
     * Append one line, and flush it, through a descriptor held for the request.
     *
     * @return array{status: string, reason: string, bytes: int, warning: string}
     *   bytes is this sink's tracked size AFTER the append, which is what a
     *   caller enforces its rotation cap against. Zero when the append did not
     *   happen. warning is the native cause of a failed open, write or flush
     *   (several joined by "; "), captured here because the capture below keeps
     *   it from any handler the caller installed: a caller that reports the
     *   failure appends it so the report names its actual cause. Empty on
     *   success.
     */
    public static function append(string $path, string $line): array {
        $openFailure = '';
        $stream = self::stream($path, $openFailure);
        if ($stream === null) {
            return array('status' => 'failed', 'reason' => 'open_failed', 'bytes' => 0, 'warning' => $openFailure);
        }
        $length = strlen($line);
        $write = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($stream, $line) { return fwrite($stream['handle'], $line); }
        );
        self::reportFilesystemWarning('diagnostic append write', $write['warning']);
        $written = $write['result'];
        $flush = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($stream) { return fflush($stream['handle']); }
        );
        self::reportFilesystemWarning('diagnostic append flush', $flush['warning']);
        $flushed = $flush['result'];
        if ($written !== $length || !$flushed) {
            // A short write leaves a truncated line in a file another process
            // parses, so the descriptor is not trustworthy any more: drop it
            // and let the next append start from a fresh one.
            self::invalidate($path);
            $causes = array_filter(array($write['warning'], $flush['warning']), static function ($warning): bool {
                return is_string($warning) && $warning !== '';
            });
            return array('status' => 'failed', 'reason' => 'append_flush_failed', 'bytes' => 0,
                'warning' => implode('; ', array_unique($causes)));
        }
        self::$streams[$path]['bytes'] += $length;
        return array(
            'status' => 'complete',
            'reason' => '',
            'bytes' => self::$streams[$path]['bytes'],
            'warning' => '',
        );
    }

    /**
     * This sink's size, without a stat syscall once it is open.
     *
     * Opens the sink if it is not open yet, because the callers that ask are
     * about to append to it and the alternative is a per-record metadata read
     * this class exists to remove. Zero when the sink cannot be opened at all,
     * which is the same zero-size answer the callers use when opening fails.
     *
     * Accurate to within one revalidation window when other processes are
     * appending to the same file (see the class comment).
     */
    public static function sizeOf(string $path): int {
        $stream = self::stream($path);
        return $stream === null ? 0 : $stream['bytes'];
    }

    /**
     * Revalidate that the held descriptor still names the live path and return
     * that file's current size.
     *
     * Rotation callers use this only when the cached size says a destructive
     * rename is imminent. A sibling may already have rotated the descriptor's
     * inode aside; acting on that stale inode's near-cap size would delete the
     * retained generation and rotate a small live file in its place. The
     * ordinary append path keeps its bounded revalidation window, while the
     * destructive boundary always pays one identity check.
     */
    public static function revalidatedSizeOf(string $path): int {
        $stream = self::stream($path);
        if ($stream === null) {
            return 0;
        }
        $identity = ABJ_404_Solution_DiagnosticSinkIdentity::inspect($stream['handle'], $path);
        if (!$identity['still_names_path']) {
            self::invalidate($path);
            $stream = self::stream($path);
            return $stream === null ? 0 : $stream['bytes'];
        }
        $bytes = is_int($identity['bytes']) ? $identity['bytes'] : $stream['bytes'];
        $stream['bytes'] = $bytes;
        $stream['appends'] = 1;
        self::$streams[$path] = $stream;
        return $bytes;
    }

    /**
     * Take the advisory lock that serializes one sink, reentrantly.
     *
     * Reentrancy is the reason this lives here rather than in each writer. With
     * a per-record fopen(), a nested write took a SECOND descriptor to the same
     * lock file, could not take the lock its own call stack was holding, and
     * degraded to the caller's lock-timeout path. One held descriptor makes the
     * nested acquisition succeed trivially, which would let the inner release
     * unlock the outer's critical section: the depth count is what stops that.
     *
     * @param int $timeoutUs Give up after this long rather than blocking. A
     *   held lock must never manufacture the stall this diagnostic measures.
     * @return array{status: string, reason: string, held: bool, warning: string}
     *   held is true only when THIS call took the lock, so only that caller
     *   releases it. warning is the native cause when the lock file could not
     *   be opened (see append()), empty otherwise.
     */
    public static function acquireExclusive(string $path, int $timeoutUs): array {
        $openFailure = '';
        $stream = self::stream($path, $openFailure);
        if ($stream === null) {
            return array('status' => 'failed', 'reason' => 'lock_open_failed', 'held' => false,
                'warning' => $openFailure);
        }
        if (self::$streams[$path]['locked'] > 0) {
            self::$streams[$path]['locked']++;
            return array('status' => 'complete', 'reason' => 'already_held', 'held' => false, 'warning' => '');
        }
        // A host with no monotonic clock cannot measure the deadline, and a
        // deadline that always reads as zero elapsed is not a bounded wait, it
        // is an unbounded one inside the very recorder being used to diagnose
        // stalls. Such a host gets ONE non-blocking attempt: failing the wait
        // closed loses a record, waiting forever loses the request.
        $measurable = function_exists('hrtime');
        $startedNs = self::monotonicNanoseconds();
        do {
            $lock = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($stream) { return flock($stream['handle'], LOCK_EX | LOCK_NB); }
            );
            self::reportFilesystemWarning('diagnostic lock acquire', $lock['warning']);
            if ($lock['result']) {
                self::$streams[$path]['locked'] = 1;
                return array('status' => 'complete', 'reason' => '', 'held' => true, 'warning' => '');
            }
            if (!$measurable) {
                return array('status' => 'lock_timeout', 'reason' => 'lock_wait_unmeasurable',
                    'held' => false, 'warning' => '');
            }
            if (self::elapsedMicroseconds($startedNs) >= $timeoutUs) {
                return array('status' => 'lock_timeout', 'reason' => 'lock_wait_exceeded',
                    'held' => false, 'warning' => '');
            }
            usleep(1000);
        } while (true);
    }

    /**
     * Release a lock taken by acquireExclusive(). Only the outermost holder
     * actually unlocks.
     *
     * @return array{status: string, reason: string}
     */
    public static function release(string $path): array {
        if (!isset(self::$streams[$path]) || self::$streams[$path]['locked'] <= 0) {
            return array('status' => 'complete', 'reason' => 'not_held');
        }
        self::$streams[$path]['locked']--;
        if (self::$streams[$path]['locked'] > 0) {
            return array('status' => 'complete', 'reason' => 'still_nested');
        }
        $unlock = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return flock(self::$streams[$path]['handle'], LOCK_UN); }
        );
        self::reportFilesystemWarning('diagnostic lock release', $unlock['warning']);
        if (!$unlock['result']) {
            return array('status' => 'failed', 'reason' => 'unlock_failed');
        }
        return array('status' => 'complete', 'reason' => '');
    }

    /**
     * Drop this sink's descriptor. Callers that rename, rotate, or delete the
     * file call this so the next append re-resolves the path.
     */
    public static function invalidate(string $path): void {
        if (!isset(self::$streams[$path])) {
            return;
        }
        $handle = self::$streams[$path]['handle'];
        if (self::$streams[$path]['locked'] > 0) {
            $unlock = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return flock($handle, LOCK_UN); }
            );
            self::reportFilesystemWarning('diagnostic lock invalidate', $unlock['warning']);
        }
        unset(self::$streams[$path]);
        $close = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fclose($handle); }
        );
        self::reportFilesystemWarning('diagnostic stream close', $close['warning']);
    }

    /** How many descriptors this request has opened for one sink. */
    public static function opens(string $path): int {
        return self::$opens[$path] ?? 0;
    }

    /** Close every held descriptor. */
    public static function closeAll(): void {
        foreach (array_keys(self::$streams) as $path) {
            self::invalidate($path);
        }
    }

    /**
     * The request-scoped reset seam, called by name from
     * ABJ404_RequestScopedStateReset. A test that deletes its journal
     * directory between cases must not leave this class writing into the
     * unlinked inode that used to be there.
     */
    public static function resetForTests(): void {
        self::closeAll();
        self::$opens = array();
    }

    /**
     * The open descriptor for one sink, opening it if needed.
     *
     * @param string $openFailure Receives the native cause when the sink could
     *   not be opened (empty when it was, or when PHP raised no warning).
     * @return array{handle: resource, bytes: int, appends: int, locked: int}|null
     */
    private static function stream(string $path, string &$openFailure = '') {
        if (isset(self::$streams[$path])) {
            $stream = self::$streams[$path];
            if (ABJ_404_Solution_DiagnosticSinkIdentity::isUnlinked($stream['handle'])) {
                // Nothing links to this inode any more, so every byte written
                // through this descriptor is reachable by nobody. Re-resolve
                // the path: if the directory survived, the record lands in a
                // readable file, and if it did not, the open below fails and
                // the caller reports it. Either beats a silent write into a
                // file that no reader can ever find.
                self::invalidate($path);
            } else {
                $appends = $stream['appends'] + 1;
                if ($appends < self::REVALIDATE_AFTER_APPENDS) {
                    self::$streams[$path] = array(
                        'handle' => $stream['handle'],
                        'bytes' => $stream['bytes'],
                        'appends' => $appends,
                        'locked' => $stream['locked'],
                    );
                    return self::$streams[$path];
                }
                $identity = ABJ_404_Solution_DiagnosticSinkIdentity::inspect($stream['handle'], $path);
                if ($identity['still_names_path']) {
                    // The window is spent either way: reset it, and take the size
                    // the descriptor itself reports so a sibling's appends do not
                    // drift our rotation cap.
                    self::$streams[$path] = array(
                        'handle' => $stream['handle'],
                        'bytes' => $identity['bytes'] ?? $stream['bytes'],
                        'appends' => 1,
                        'locked' => $stream['locked'],
                    );
                    return self::$streams[$path];
                }
                // Another process rotated this file away. Anything already written
                // is in the rotated file, which is retained; start a fresh one.
                self::invalidate($path);
            }
        }
        if ($path === '') {
            return null;
        }
        // 'ab' creates without truncating and positions at the end on every
        // write, which is what both a journal and a lock file need. 'cb' is the
        // fallback for a filesystem that refuses the append mode outright.
        $appendOpen = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'ab');
        $handle = $appendOpen['result'];
        $openWarnings = $appendOpen['warning'] !== null ? array($appendOpen['warning']) : array();
        if ($handle === false) {
            $createOpen = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'cb');
            $handle = $createOpen['result'];
            if ($createOpen['warning'] !== null) {
                $openWarnings[] = $createOpen['warning'];
            }
        }
        if ($handle === false) {
            $message = 'sink could not be opened: ' . $path;
            $openWarning = implode('; ', array_unique($openWarnings));
            $openFailure = $openWarning;
            if ($openWarning !== '') {
                $message .= ' (' . $openWarning . ')';
            }
            abj404_logPhpFallback('logger-internal', $message);
            return null;
        }
        self::evictOldestWhenFull();
        clearstatcache(true, $path);
        $metadataResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fstat($handle); }
        );
        self::reportFilesystemWarning('diagnostic sink stat', $metadataResult['warning']);
        $metadata = $metadataResult['result'];
        $size = is_array($metadata) && is_int($metadata['size'] ?? null)
            ? $metadata['size'] : 0;
        self::$streams[$path] = array(
            'handle' => $handle,
            'bytes' => $size,
            'appends' => 1,
            'locked' => 0,
        );
        self::$opens[$path] = (self::$opens[$path] ?? 0) + 1;
        return self::$streams[$path];
    }

    /** Keep the number of held descriptors bounded, oldest sink first. */
    private static function evictOldestWhenFull(): void {
        while (count(self::$streams) >= self::MAX_OPEN_PATHS) {
            $oldest = array_key_first(self::$streams);
            if ($oldest === null) {
                return;
            }
            self::invalidate($oldest);
        }
    }

    private static function monotonicNanoseconds(): int {
        return function_exists('hrtime') ? (int)hrtime(true) : 0;
    }

    private static function elapsedMicroseconds(int $startedNs): int {
        return max(0, (int)round((self::monotonicNanoseconds() - $startedNs) / 1000));
    }

    private static function reportFilesystemWarning(string $operation, ?string $warning): void {
        if ($warning !== null && $warning !== '') {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $warning);
        }
    }
}
