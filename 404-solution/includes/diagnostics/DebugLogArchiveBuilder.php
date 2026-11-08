<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php zip-failure mail fallback entry-point test.

/**
 * Builds the developer-feedback debug-log zip attachment.
 *
 * The builder only creates the archive from paths supplied by the caller. It
 * does not send mail and does not decide whether a missing/failed archive
 * should abort the report.
 */
class ABJ_404_Solution_DebugLogArchiveBuilder {

    /**
     * Name of the entry that states what the archive was ASKED to carry.
     *
     * A file that does not exist is skipped rather than failing the archive,
     * which leaves the receiver with the same ambiguity the support payload's
     * empty excerpt used to have: nothing separates "this site wrote no
     * journal" from "the builder was handed paths on the wrong node". The
     * manifest is how a zip that is missing a journal says so out loud. Same
     * property as ABJ_404_Solution_DiagnosticCollectionManifest, on the
     * out-of-band channel.
     */
    const MANIFEST_ENTRY = 'abj404-archive-manifest.txt';

    /**
     * Create a zip containing the current and rotated debug logs, plus any
     * additional diagnostic files the caller wants carried WHOLE.
     *
     * The additional-paths channel exists because the support payload's
     * diagnostic journals ride a byte budget and a ranking: whatever that
     * budget decides to drop is gone, and the failing session is one we only
     * get once. The archive has no such bound, so it is the out-of-band copy.
     *
     * @param string $zipPath
     * @param string $debugFilePath
     * @param string $oldDebugFilePath
     * @param array<int, string> $additionalPaths Absolute paths; missing ones are skipped but stated.
     * @return string The requested zip path, or an empty string when ZipArchive is unavailable.
     */
    public function build(string $zipPath, string $debugFilePath, string $oldDebugFilePath,
            array $additionalPaths = array()): string {
        $existingZip = ABJ_404_Solution_HostFilesystemCall::fileExists($zipPath);
        $this->reportFilesystemWarning('debug log zip existence check', $zipPath, $existingZip['warning']);
        if ($existingZip['warning'] === null && $existingZip['result']) {
            $unlink = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($zipPath) {
                    return unlink($zipPath);
                }
            );
            $this->reportFilesystemWarning('debug log zip removal', $zipPath, $unlink['warning']);
        }
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zipDirectory = dirname($zipPath);
        $directoryCheck = ABJ_404_Solution_HostFilesystemCall::isDir($zipDirectory);
        $this->reportFilesystemWarning('debug log zip directory check', $zipDirectory, $directoryCheck['warning']);
        if ($directoryCheck['warning'] !== null || !$directoryCheck['result']) {
            abj404_logPhpFallback('logger-internal', 'debug log zip directory does not exist: ' . $zipDirectory);
            return $zipPath;
        }
        $zip = new ZipArchive;
        $opened = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($zip, $zipPath) { return $zip->open($zipPath, ZipArchive::CREATE); }
        );
        $this->reportFilesystemWarning('debug log zip open', $zipPath, $opened['warning']);
        $openResult = $opened['result'];
        if ($openResult === true) {
            $archiveException = null;
            try {
                // A warning makes an otherwise-true native result untrusted;
                // still close the acquired archive in the finally block.
                if ($opened['warning'] === null) {
                    $this->addReadableArchiveInputs(
                        $zip, $debugFilePath, $oldDebugFilePath, $additionalPaths
                    );
                    $manifest = $this->manifest(array_merge(array($debugFilePath, $oldDebugFilePath), $additionalPaths));
                    $manifestAdded = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                        static function () use ($zip, $manifest) {
                            return $zip->addFromString(self::MANIFEST_ENTRY, $manifest);
                        }
                    );
                    $this->reportFilesystemWarning('debug log archive manifest add', $zipPath, $manifestAdded['warning']);
                }
            } catch (Throwable $e) {
                $archiveException = $e;
                throw $e;
            } finally {
                try {
                    $closed = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                        static function () use ($zip) { return $zip->close(); }
                    );
                    $this->reportFilesystemWarning('debug log zip close', $zipPath, $closed['warning']);
                    if (!$closed['result']) {
                        abj404_logPhpFallback('logger-internal', 'debug log zip close failed for ' . $zipPath);
                    }
                } catch (Throwable $closeException) {
                    if ($archiveException === null) {
                        throw $closeException;
                    }
                    abj404_logPhpFallback(
                        'logger-internal',
                        'debug log zip close failed after archive exception for ' . $zipPath . ': '
                            . $closeException->getMessage()
                    );
                }
            }
        } else {
            abj404_logPhpFallback(
                'logger-internal',
                'debug log zip open failed for ' . $zipPath . ' (status ' . (string)$openResult . ')'
            );
        }
        return $zipPath;
    }

    /**
     * Admit each readable requested log into an acquired archive. This stage
     * owns the path admission and ZipArchive add operations; build() owns
     * archive lifecycle and the manifest entry.
     *
     * @param ZipArchive $zip
     * @param array<int, string> $additionalPaths
     */
    private function addReadableArchiveInputs(
        ZipArchive $zip,
        string $debugFilePath,
        string $oldDebugFilePath,
        array $additionalPaths
    ): void {
        $inputs = array(
            array('path' => $debugFilePath, 'label' => 'current'),
            array('path' => $oldDebugFilePath, 'label' => 'rotated'),
        );
        foreach ($additionalPaths as $additionalPath) {
            if (is_string($additionalPath) && $additionalPath !== '') {
                $inputs[] = array('path' => $additionalPath, 'label' => 'additional');
            }
        }
        foreach ($inputs as $input) {
            $path = $input['path'];
            $label = $input['label'];
            $file = ABJ_404_Solution_HostFilesystemCall::fileExists($path);
            $this->reportFilesystemWarning('debug log ' . $label . ' file check', $path, $file['warning']);
            if ($file['warning'] !== null || !$file['result']) {
                continue;
            }
            $added = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($zip, $path) {
                    return $zip->addFile($path, basename($path));
                }
            );
            $this->reportFilesystemWarning('debug log ' . $label . ' file add', $path, $added['warning']);
        }
    }

    /**
     * One line per requested path: whether it was there, how big it was, and
     * when it last changed. Requested paths, not added ones, because the
     * question this answers is "what did the collector look for", and the
     * absent ones are the interesting half of the answer.
     *
     * @param array<int, string> $requestedPaths
     */
    private function manifest(array $requestedPaths): string {
        $hostname = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::hostname();
        $pid = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processId();
        $processIdentity = $pid !== null
            ? 'pid ' . $pid
            : 'synthetic process ' . ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processToken();
        $lines = array(
            'Files this archive was asked to carry, and whether they were found.',
            'Collected by ' . ($hostname !== null ? $hostname : 'unknown-host')
                . ' ' . $processIdentity . ' (' . PHP_SAPI . ').',
            '',
        );
        foreach ($requestedPaths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $exists = ABJ_404_Solution_HostFilesystemCall::fileExists($path);
            $this->reportFilesystemWarning('debug log manifest existence check', $path, $exists['warning']);
            if ($exists['warning'] !== null || !$exists['result']) {
                $lines[] = 'absent  ' . $path;
                continue;
            }
            $sizeResult = ABJ_404_Solution_HostFilesystemCall::filesize($path);
            $this->reportFilesystemWarning('debug log manifest size check', $path, $sizeResult['warning']);
            $size = $sizeResult['warning'] === null ? $sizeResult['result'] : false;
            $modifiedResult = ABJ_404_Solution_HostFilesystemCall::filemtime($path);
            $this->reportFilesystemWarning('debug log manifest modified-time check', $path, $modifiedResult['warning']);
            $modified = $modifiedResult['warning'] === null ? $modifiedResult['result'] : false;
            $lines[] = 'present ' . $path
                . ' (' . (is_int($size) ? $size . ' bytes' : 'size unreadable')
                . ', modified ' . (is_int($modified) ? gmdate('Y-m-d H:i:s', $modified) . ' UTC' : 'unknown')
                . ')';
        }
        return implode("\n", $lines) . "\n";
    }

    private function reportFilesystemWarning(string $operation, string $path, ?string $warning): void {
        if ($warning !== null && $warning !== '') {
            abj404_logPhpFallback('logger-internal', $operation . ' failed for ' . $path . ': ' . $warning);
        }
    }
}
