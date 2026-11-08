<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Measures filesystem capacity and create/write/cleanup ability for host diagnostics.
 */
final class ABJ_404_Solution_HostFilesystemPressureProbe {

    /** @return array<string, mixed> */
    public static function capture(): array {
        $paths = self::defaultProbePaths();
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('abj404_host_pressure_probe_paths', $paths);
                $paths = is_array($filtered) ? $filtered : array('configuration' => null);
            } catch (Throwable $e) {
                self::reportFailure('filesystem probe-path filter failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
                return array(
                    'configuration' => array(
                        'status' => 'unavailable',
                        'reason' => 'path_filter_failed',
                        'path' => '',
                        'attempted_paths' => array(
                            'apply_filters(abj404_host_pressure_probe_paths)' =>
                                'exception:' . get_class($e),
                        ),
                    ),
                );
            }
        }

        static $requestCache = array();
        $cacheKey = hash('sha256', serialize($paths));
        if (isset($requestCache[$cacheKey])) {
            return $requestCache[$cacheKey];
        }

        $probes = array();
        foreach ($paths as $label => $path) {
            $probeLabel = is_string($label) && $label !== '' ? $label : 'path_' . count($probes);
            $probes[$probeLabel] = self::probe($path);
        }
        $requestCache[$cacheKey] = $probes;
        return $probes;
    }

    /** @return array<string, string> */
    private static function defaultProbePaths(): array {
        $paths = array();
        $contentDirectory = defined('WP_CONTENT_DIR')
            ? rtrim((string)WP_CONTENT_DIR, '/\\')
            : (defined('ABSPATH') ? rtrim((string)ABSPATH, '/\\') . '/wp-content' : '');
        if ($contentDirectory !== '') {
            $paths['wordpress_uploads'] = $contentDirectory . '/uploads';
            // allow-silent-error: wp-content/cache is an optional host-managed directory; absence or an open_basedir denial just leaves it out of the probe set.
            $cacheDirectory = ABJ_404_Solution_HostFilesystemCall::isDir($contentDirectory . '/cache');
            if ($cacheDirectory['result']) {
                $paths['wordpress_cache'] = $contentDirectory . '/cache';
            }
        }
        if (function_exists('abj404_getUploadsDir')) {
            try {
                $pluginUploads = abj404_getUploadsDir();
                if (is_string($pluginUploads) && $pluginUploads !== '') {
                    $paths['plugin_diagnostics'] = $pluginUploads;
                }
            } catch (Throwable $e) {
                self::reportFailure('plugin diagnostics path lookup failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
            }
        }
        return $paths;
    }

    /**
     * @param mixed $path
     * @return array<string, mixed>
     */
    private static function probe($path): array {
        if (!is_string($path) || $path === '') {
            return self::unavailable('invalid_path', array(
                'configured_path' => !is_string($path) ? 'not_string' : 'empty',
            ), '');
        }
        $path = rtrim($path, '/\\');
        // allow-silent-error: a probed host path can be outside open_basedir; the denial is reported below as the not_directory finding instead of as a PHP warning.
        $directoryCheck = ABJ_404_Solution_HostFilesystemCall::isDir($path);
        self::reportFilesystemWarning('filesystem probe directory check', $directoryCheck['warning']);
        if (!$directoryCheck['result']) {
            return self::unavailable('not_directory', array(
                'is_dir(' . $path . ')' => 'not_directory',
            ), $path);
        }
        $freeResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) {
                return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::diskFreeSpace($path);
            }
        );
        self::reportFilesystemWarning('filesystem probe free space', $freeResult['warning']);
        $freeBytes = $freeResult['result'];
        $totalResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) {
                return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::diskTotalSpace($path);
            }
        );
        self::reportFilesystemWarning('filesystem probe total space', $totalResult['warning']);
        $totalBytes = $totalResult['result'];

        return array(
            'status' => 'available',
            'path' => $path,
            'free_bytes' => ABJ_404_Solution_ExactInteger::read($freeBytes, 0),
            'total_bytes' => ABJ_404_Solution_ExactInteger::read($totalBytes, 0),
            'create_write_probe' => self::createWriteProbe($path),
        );
    }

    /** @return array<string, mixed> */
    private static function createWriteProbe(string $path): array {
        $attemptedPaths = array();
        $writableResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return is_writable($path); }
        );
        self::reportFilesystemWarning('filesystem probe writability check', $writableResult['warning']);
        if (!$writableResult['result']) {
            return self::unavailable('directory_not_writable', array(
                'is_writable(' . $path . ')' => 'false',
            ));
        }
        $attemptedPaths['is_writable(target_directory)'] = 'true';
        $tempResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return tempnam($path, 'abj404-pressure-'); }
        );
        self::reportFilesystemWarning('filesystem probe create', $tempResult['warning']);
        $sentinel = $tempResult['result'];
        if (!is_string($sentinel)) {
            $attemptedPaths['tempnam(target_directory)'] = 'failed';
            return self::unavailable('create_failed', $attemptedPaths);
        }
        $attemptedPaths['tempnam(target_directory)'] = 'created';
        $targetResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path) { return realpath($path); }
        );
        self::reportFilesystemWarning('filesystem probe target realpath', $targetResult['warning']);
        $targetDirectory = $targetResult['result'];
        $createdResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($sentinel) { return realpath(dirname($sentinel)); }
        );
        self::reportFilesystemWarning('filesystem probe created realpath', $createdResult['warning']);
        $createdDirectory = $createdResult['result'];
        if ($targetDirectory === false || $createdDirectory !== $targetDirectory) {
            $cleanup = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($sentinel) { return unlink($sentinel); }
            );
            self::reportFilesystemWarning('filesystem probe fallback cleanup', $cleanup['warning']);
            $attemptedPaths['realpath(created_directory)'] = 'outside_target';
            return self::unavailable('create_fell_back_outside_target', $attemptedPaths);
        }
        $attemptedPaths['realpath(created_directory)'] = 'target_match';
        $openResult = ABJ_404_Solution_HostFilesystemCall::fopen($sentinel, 'wb');
        self::reportFilesystemWarning('filesystem probe open', $openResult['warning']);
        $handle = $openResult['result'];
        if ($handle === false) {
            $cleanup = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                static function () use ($sentinel) { return unlink($sentinel); }
            );
            self::reportFilesystemWarning('filesystem probe open cleanup', $cleanup['warning']);
            $attemptedPaths['fopen(sentinel)'] = 'failed';
            return self::unavailable('open_failed', $attemptedPaths);
        }
        $attemptedPaths['fopen(sentinel)'] = 'opened';
        $writeResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fwrite($handle, 'x'); }
        );
        self::reportFilesystemWarning('filesystem probe write', $writeResult['warning']);
        $bytesWritten = $writeResult['result'];
        $closeResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle) { return fclose($handle); }
        );
        self::reportFilesystemWarning('filesystem probe close', $closeResult['warning']);
        $closed = $closeResult['result'];
        $removeResult = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($sentinel) { return unlink($sentinel); }
        );
        self::reportFilesystemWarning('filesystem probe cleanup', $removeResult['warning']);
        $removed = $removeResult['result'];
        $attemptedPaths['fwrite(sentinel)'] = 'bytes_written:' . (string)$bytesWritten;
        $attemptedPaths['fclose(sentinel)'] = $closed ? 'true' : 'false';
        $attemptedPaths['unlink(sentinel)'] = $removed ? 'true' : 'false';
        if ($bytesWritten !== 1) {
            return self::unavailable('write_failed', $attemptedPaths);
        }
        if (!$closed) {
            return self::unavailable('close_failed', $attemptedPaths);
        }
        if (!$removed) {
            return self::unavailable('cleanup_failed', $attemptedPaths);
        }
        return array('status' => 'available', 'bytes_written' => 1);
    }

    /**
     * @param array<string, string> $attemptedPaths
     * @return array<string, mixed>
     */
    private static function unavailable(
        string $reason,
        array $attemptedPaths,
        ?string $path = null
    ): array {
        $reading = array(
            'status' => 'unavailable',
            'reason' => $reason,
            'attempted_paths' => $attemptedPaths,
        );
        if ($path !== null) {
            $reading['path'] = $path;
        }
        return $reading;
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('host-pressure', $message);
        }
    }

    private static function reportFilesystemWarning(string $operation, ?string $warning): void {
        if ($warning !== null && $warning !== '' && function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('logger-internal', $operation . ': ' . $warning);
        }
    }
}
