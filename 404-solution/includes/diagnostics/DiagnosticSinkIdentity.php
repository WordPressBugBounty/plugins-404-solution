<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only proof that a held append descriptor still reaches the file its path
 * names.
 *
 * DiagnosticAppendStream keeps one descriptor open per diagnostic sink for the
 * life of the request. A descriptor follows the INODE, not the path, so a
 * sibling request that rotates or deletes the file leaves this process writing
 * somewhere no reader will look. This class answers the two questions that
 * decide whether the held descriptor is still safe to write through, and does
 * nothing else: it never opens, closes, evicts or writes. The stream table and
 * the decision about what to do with the answer stay with DiagnosticAppendStream.
 */
final class ABJ_404_Solution_DiagnosticSinkIdentity {

    /**
     * Has this descriptor's file been unlinked out from under it?
     *
     * This is the per-record half of the identity problem, and it is separate
     * from inspect() below because the two failures have different costs.
     * A rotation RENAME retains every record already written, so noticing it one
     * revalidation window late loses nothing and the bounded check is enough. An
     * UNLINK retains nothing: the descriptor keeps accepting writes, fwrite and
     * fflush both report success, and the bytes are unreachable to every reader.
     * Measured on APFS 2026-08-10: after the directory was removed, fwrite
     * returned the full length and fflush returned true, with nlink at 0. That
     * silently breaks this class's durability contract, which is per-record, so
     * this check has to be per-record too. It costs one fstat, 1.11 us against
     * the 24.8 us this class spends per record.
     *
     * nlink is the right signal precisely because it stays 1 through a rename,
     * so this cannot fire on the rotation case the bounded window exists for.
     *
     * @param resource $handle
     * @return bool True only when the descriptor reports zero directory entries.
     */
    public static function isUnlinked($handle): bool {
        $openResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fstat($handle); }
        );
        self::reportFilesystemWarning('diagnostic sink link check', $openResult['warning']);
        $open = $openResult['result'];
        if (!is_array($open) || !isset($open['nlink'])) {
            // A filesystem that will not report a link count gets the bounded
            // path-identity check and nothing stricter. Failing closed here
            // would re-open on every append and hand back the entire cost this
            // class exists to remove, on every host with an unusual stat().
            return false;
        }
        return (int)$open['nlink'] === 0;
    }

    /**
     * Does this descriptor still point at the file this path names, and how
     * big does the descriptor itself say the file is?
     *
     * Pure: the caller owns the stream table, so this only reports.
     *
     * @param resource $handle
     * @param string $path Absolute path the descriptor was opened from.
     * @return array{still_names_path: bool, bytes: int|null}
     */
    public static function inspect($handle, string $path): array {
        clearstatcache(true, $path);
        $onDiskCheck = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return is_file($path); }
        );
        self::reportFilesystemWarning('diagnostic sink path check', $onDiskCheck['warning']);
        if ($onDiskCheck['result']) {
            $onDiskResult = ABJ_404_Solution_HostFilesystemCall::stat($path);
            self::reportFilesystemWarning('diagnostic sink path stat', $onDiskResult['warning']);
            $onDisk = $onDiskResult['result'];
        } else {
            $onDisk = false;
        }
        $openResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fstat($handle); }
        );
        self::reportFilesystemWarning('diagnostic sink descriptor stat', $openResult['warning']);
        $open = $openResult['result'];
        if (!is_array($open)) {
            // The descriptor itself is unreadable, so there is nothing to
            // compare and re-opening on every window would give back the cost
            // this class exists to remove.
            return array('still_names_path' => true, 'bytes' => null);
        }
        $bytes = isset($open['size']) && is_int($open['size']) ? $open['size'] : null;
        if (!is_array($onDisk)) {
            // Nothing at the path while our descriptor is still valid: the file
            // was renamed or unlinked out from under us. This is the window
            // between a sibling's rotation rename and its first new record.
            return array('still_names_path' => false, 'bytes' => $bytes);
        }
        $sameFile = ($onDisk['ino'] ?? null) === ($open['ino'] ?? null)
            && ($onDisk['dev'] ?? null) === ($open['dev'] ?? null);
        return array('still_names_path' => $sameFile, 'bytes' => $bytes);
    }

    /**
     * Route a captured filesystem warning to the PHP-level fallback log.
     *
     * Logging cannot go through the diagnostic sinks themselves, because this
     * class exists to guard them.
     */
    private static function reportFilesystemWarning(string $operation, ?string $warning): void {
        if ($warning !== null && $warning !== '') {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $warning);
        }
    }
}
