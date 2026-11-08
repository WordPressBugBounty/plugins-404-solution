<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proof that the diagnostics directory is genuinely usable for THIS request.
 *
 * A resolved directory path is not evidence of anything. Beta.1 ended with
 * journals that "came back empty", and an empty journal has several
 * indistinguishable causes: a path that resolved but is not writable, a host
 * that writes to a per-worker filesystem, an open_basedir restriction, a quota,
 * a directory that exists for the writing worker and not for the reading one.
 * Only an actual round trip can separate those, and only if it is performed by
 * the request being diagnosed rather than by a health check at some other time.
 *
 * So this walks the whole file lifecycle -- create, append, flush, stat, glob,
 * read back, delete -- against a request-specific file in that directory, and
 * journals the outcome of every step. The step name in a failed record IS the
 * finding: "stat" says the bytes did not land, "glob" says the directory cannot
 * be enumerated (which is exactly what the support collector later has to do),
 * "delete" says the plugin is accumulating files it cannot clean up.
 *
 * The results go through ABJ_404_Solution_AjaxCheckpointLogger, the immediate
 * append-only channel, rather than through the staged trace journal this is
 * partly probing on behalf of: a defect in the component under investigation
 * must not be able to erase the evidence about it. That makes this class a
 * PRODUCER of checkpoint records, like every other instrumented boundary, and
 * the recorder knows nothing about it.
 */
final class ABJ_404_Solution_DiagnosticDirectoryProbe {

    /** Prefix for the probe file, also the glob the enumeration step tests. */
    const FILE_PREFIX = 'abj404_checkpoint_selftest_';

    /**
     * Run the whole round trip and journal each step's outcome. Never throws:
     * a probe that cannot complete is a finding about the host, and it must not
     * become the reason the request it was proving fails.
     */
    public static function run(string $requestId): void {
        try {
            $directory = ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectory();
            if ($directory === '') {
                self::recordStep($requestId, false, 'resolve_directory');
                return;
            }
            $path = $directory . self::FILE_PREFIX . $requestId . '_'
                . ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processToken() . '.tmp';
            $payload = 'abj404-selftest-' . $requestId;

            $open = ABJ_404_Solution_HostFilesystemCall::fopen($path, 'wb');
            self::reportFilesystemWarning('self-test create', $open['warning']);
            $handle = $open['result'];
            if ($handle === false) {
                self::recordStep($requestId, false, 'create');
                return;
            }
            $write = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle, $payload) { return fwrite($handle, $payload); }
            );
            self::reportFilesystemWarning('self-test append', $write['warning']);
            $written = $write['result'];
            $flush = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fflush($handle); }
            );
            self::reportFilesystemWarning('self-test flush', $flush['warning']);
            $flushed = $flush['result'];
            $close = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($handle) { return fclose($handle); }
            );
            self::reportFilesystemWarning('self-test close', $close['warning']);
            if ($written === false || !$flushed) {
                self::recordStep($requestId, false, 'append_flush');
                return;
            }

            $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($path);
            self::reportFilesystemWarning('self-test stat', $sizeResult['warning']);
            $size = $sizeResult['result'];
            if (!is_int($size) || $size !== strlen($payload)) {
                self::recordStep($requestId, false, 'stat', array('size' => $size));
                return;
            }

            $globResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($directory) { return glob($directory . self::FILE_PREFIX . '*'); }
            );
            self::reportFilesystemWarning('self-test glob', $globResult['warning']);
            $globMatches = $globResult['result'];
            $globCount = is_array($globMatches) ? count($globMatches) : 0;
            if ($globCount < 1) {
                self::recordStep($requestId, false, 'glob', array('glob_count' => $globCount));
                return;
            }

            $readResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($path) { return file_get_contents($path); }
            );
            self::reportFilesystemWarning('self-test read', $readResult['warning']);
            $readBack = $readResult['result'];
            if ($readBack !== $payload) {
                self::recordStep($requestId, false, 'read');
                return;
            }

            $delete = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($path) { return unlink($path); }
            );
            self::reportFilesystemWarning('self-test delete', $delete['warning']);
            if (!$delete['result']) {
                self::recordStep($requestId, false, 'delete');
                return;
            }

            self::recordStep($requestId, true, 'complete', array('glob_count' => $globCount));
        } catch (Throwable $e) {
            // Reported to the debug log as well as journaled: an exception here
            // is a host condition the plugin cannot act on, and the journal is
            // read only when an admin sends a support request.
            abj404_logPhpFallback('ajax-checkpoint',
                'AJAX checkpoint self-test failed: ' . $e->getMessage());
            self::recordStep($requestId, false, 'exception',
                array('message' => substr($e->getMessage(), 0, 200)));
        }
    }

    /**
     * One step outcome, under the event name the evidence catalogue and both
     * acceptance gates already know this probe by.
     *
     * @param array<string, mixed> $extra
     */
    private static function recordStep(string $requestId, bool $ok, string $step,
            array $extra = array()): void {
        if (!$ok && in_array($step, array('resolve_directory', 'create', 'append_flush', 'exception'), true)
                && class_exists('ABJ_404_Solution_RequestFailureFrame', false)) {
            ABJ_404_Solution_RequestFailureFrame::storageFailed();
        }
        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'selftest',
            array_merge(array('ok' => $ok, 'step' => $step), $extra));
    }

    private static function reportFilesystemWarning(string $operation, ?string $warning): void {
        if ($warning !== null && $warning !== '' && function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $warning);
        }
    }
}
