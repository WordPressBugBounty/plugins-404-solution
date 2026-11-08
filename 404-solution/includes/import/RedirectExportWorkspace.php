<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem boundary for confidential, request-owned export scratch files.
 *
 * Uploads and diagnostics are public storage, never a temporary fallback.
 * Canonical paths also reject a host's temp setting that points into a public
 * tree through a symlink. An interrupted process can leave only a private
 * directory outside those trees, not a downloadable export. Normal returns,
 * exceptions and PHP shutdown remove the workspace.
 */
final class ABJ_404_Solution_RedirectExportWorkspace {

    /** @var string Empty after close(), making explicit/shutdown cleanup idempotent. */
    private $directory;

    private function __construct(string $directory) {
        $this->directory = $directory;
    }

    /** @return self|WP_Error A private workspace, or the actual storage failure. */
    public static function create() {
        // An in-flight update can retain the released capability adapter.
        if (!is_callable(array(ABJ_404_Solution_PhpRuntimeCapabilityAdapter::class, 'temporaryDirectory'))) {
            return new WP_Error('temp_directory_unavailable',
                'PHP retained a runtime adapter from before the plugin update. Retry the export in a new PHP process.');
        }
        $base = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::temporaryDirectory();
        if ($base === null) {
            return new WP_Error('temp_directory_unavailable',
                'sys_get_temp_dir is unavailable. Enable it and configure sys_temp_dir outside public directories before exporting.');
        }
        try {
            $base = realpath($base);
            if ($base === false || !is_dir($base) || !is_writable($base)) {
                return new WP_Error('temp_directory_unavailable', 'PHP supplied no usable private temporary directory.');
            }
            $publicRoot = self::containingPublicRoot($base);
            if ($publicRoot !== '') {
                return new WP_Error('temp_directory_public', 'Temporary directory ' . $base
                    . ' is inside public storage ' . $publicRoot . '. Configure sys_temp_dir outside public directories before exporting.');
            }
            $directory = $base . DIRECTORY_SEPARATOR . 'abj404-export-' . bin2hex(random_bytes(16));
            if (!@mkdir($directory, 0700)) {
                $error = error_get_last();
                return new WP_Error('temp_directory_unavailable', 'Could not create private export directory in '
                    . $base . ': ' . (string)($error['message'] ?? 'mkdir failed'));
            }
            $workspace = new self($directory);
            $permissions = fileperms($directory);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                $workspace->close();
                return new WP_Error('temp_directory_unavailable', 'Export directory permissions are not private: ' . $directory);
            }
            self::removeLegacyCsvFiles();
            return $workspace;
        } catch (\Throwable $error) {
            return new WP_Error('temp_directory_unavailable', 'Private export storage failed ('
                . (string)$error->getCode() . '): ' . $error->getMessage());
        }
    }

    /** Fixed, workspace-owned filenames; no caller-supplied path can escape it. */
    public function csvPath(string $format = 'native'): string {
        return $this->directory . ($format === 'redirection' ? '/export-redirection.csv' : '/export.csv');
    }

    /** Remove only this workspace's two known CSV files and its empty directory. */
    public function close(): void {
        if ($this->directory === '') { return; }
        foreach (array('native', 'redirection') as $format) {
            ABJ_404_Solution_FileSystemService::safeUnlink($this->csvPath($format));
        }
        if (!@rmdir($this->directory)) {
            $error = error_get_last();
            ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog('404 Solution: private export cleanup failed: '
                . $this->directory . ' (' . (string)($error['message'] ?? 'rmdir failed') . ')');
        }
        $this->directory = '';
    }

    /** PHP exit/fatal shutdown still cleans up when control cannot reach finally. */
    public function __destruct() {
        $this->close();
    }

    /** Return the canonical public root containing $directory, or an empty string. */
    private static function containingPublicRoot(string $directory): string {
        $uploads = wp_upload_dir(null, false);
        $roots = array(ABSPATH, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            $_SERVER['DOCUMENT_ROOT'] ?? '', is_array($uploads) ? ($uploads['basedir'] ?? '') : '');
        $ancestors = array();
        // realpath() preserves case aliases on case-insensitive filesystems.
        // Device/inode identity also handles those aliases and bind mounts.
        do {
            $ancestors[self::directoryIdentity($directory)] = true;
            $parent = dirname($directory);
            if ($parent === $directory) { break; }
            $directory = $parent;
        } while (true);
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '') { continue; }
            $canonical = realpath($root);
            if ($canonical !== false && isset($ancestors[self::directoryIdentity($canonical)])) {
                return $canonical;
            }
        }
        return '';
    }

    /** Physical directory identity; an unreadable/unsupported identity fails closed. */
    private static function directoryIdentity(string $path): string {
        $metadata = @stat($path);
        if ($metadata === false) {
            $error = error_get_last();
            throw new \RuntimeException('Cannot inspect export directory ' . $path
                . ': ' . (string)($error['message'] ?? 'stat failed'));
        }
        if ($metadata['ino'] === 0) {
            throw new \RuntimeException('The filesystem cannot identify directory inodes for ' . $path);
        }
        return $metadata['dev'] . ':' . $metadata['ino'];
    }

    /** Remove only recognizable browser/CLI CSVs left by older public staging. */
    private static function removeLegacyCsvFiles(): void {
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !is_string($uploads['basedir'] ?? null)
                || trim($uploads['basedir']) === '' || !empty($uploads['error'])) { return; }
        $directory = realpath($uploads['basedir'] . '/temp_' . ABJ404_PP);
        if ($directory === false || !is_dir($directory)) { return; }
        foreach (new \DirectoryIterator($directory) as $file) {
            $name = $file->getFilename();
            if ($file->isFile() && preg_match('/\A(?:export(?:-redirection)?|abj404_export_(?:native_)?[0-9]+)\.csv\z/', $name) === 1) {
                ABJ_404_Solution_FileSystemService::safeUnlink($file->getPathname());
            }
        }
    }
}
