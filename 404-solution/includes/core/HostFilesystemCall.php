<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The host-probe code's only caller of the native filesystem calls that
 * raise a warning before they fail.
 *
 * file_exists(), is_dir(), is_readable(), file_get_contents(), scandir(),
 * fopen(), fgets(), fclose(), filemtime(), filesize(), glob(), is_file(),
 * rename() and stat() emit an E_WARNING
 * and then return false when a path is outside open_basedir, missing, or
 * unreadable. The diagnostics and feedback modules probe host paths they do
 * not control (uploads, wp-content/cache, an operator-chosen log directory,
 * their own journals on a host that may have moved them), so for them that
 * warning is an expected answer, not a fault. Left to reach the site's error
 * handler it becomes a plugin error report, or a fatal where warnings are
 * escalated.
 *
 * Each method runs its native call inside
 * ABJ_404_Solution_FileSystemService::captureFilesystemWarnings() and returns
 * that shape unchanged: the native result, and the warning text (or null)
 * for the caller to record. Calling through here makes the capture part of
 * the call instead of a closure each caller has to remember to write, and it
 * is what Pattern6PhpDefensiveGuardAuditTest recognises as safe.
 */
class ABJ_404_Solution_HostFilesystemCall {

    /**
     * file_exists() with its warning captured.
     *
     * @param string $path
     * @return array{result: bool, warning: string|null}
     */
    public static function fileExists(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => file_exists($path)
        );
    }

    /**
     * is_dir() with its warning captured.
     *
     * @param string $path
     * @return array{result: bool, warning: string|null}
     */
    public static function isDir(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => is_dir($path)
        );
    }

    /**
     * is_readable() with its warning captured.
     *
     * @param string $path
     * @return array{result: bool, warning: string|null}
     */
    public static function isReadable(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => is_readable($path)
        );
    }

    /**
     * file_get_contents() with its warning captured and an optional native length.
     *
     * @param string $path
     * @param int<0, max>|null $maxLength Native length domain; PHP raises its
     *   version-specific ValueError for a negative value.
     * @return array{result: string|false, warning: string|null}
     */
    public static function fileGetContents(string $path, ?int $maxLength = null): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($path, $maxLength) {
                return $maxLength === null
                    ? file_get_contents($path)
                    : file_get_contents($path, false, null, 0, $maxLength);
            }
        );
    }

    /**
     * scandir() with its warning captured.
     *
     * @param string $path
     * @return array{result: array<int, string>|false, warning: string|null}
     */
    public static function scandir(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => scandir($path)
        );
    }

    /**
     * fopen() with its warning captured.
     *
     * @param string $path
     * @param string $mode
     * @return array{result: resource|false, warning: string|null}
     */
    public static function fopen(string $path, string $mode): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => fopen($path, $mode)
        );
    }

    /**
     * fgets() with its warning captured.
     *
     * @param resource $handle
     * @param int<0, max>|null $length Optional native line-length domain; negative values raise ValueError.
     * @return array{result: string|false, warning: string|null}
     */
    public static function fgets($handle, ?int $length = null): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static function () use ($handle, $length) {
                return $length === null ? fgets($handle) : fgets($handle, $length);
            }
        );
    }

    /**
     * fclose() with its warning captured.
     *
     * @param resource $handle
     * @return array{result: bool, warning: string|null}
     */
    public static function fclose($handle): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => fclose($handle)
        );
    }

    /**
     * filemtime() with its warning captured.
     *
     * @param string $path
     * @return array{result: int|false, warning: string|null}
     */
    public static function filemtime(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => filemtime($path)
        );
    }

    /**
     * filesize() with its warning captured.
     *
     * @param string $path
     * @return array{result: int|false, warning: string|null}
     */
    public static function filesize(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => filesize($path)
        );
    }

    /**
     * stat() with its warning captured.
     *
     * @param string $path
     * @return array{result: array<int|string, int>|false, warning: string|null}
     */
    public static function stat(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => stat($path)
        );
    }

    /**
     * glob() with its warning captured.
     *
     * @param string $pattern
     * @return array{result: array<int, string>|false, warning: string|null}
     */
    public static function glob(string $pattern): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => glob($pattern)
        );
    }

    /**
     * is_file() with its warning captured.
     *
     * @param string $path
     * @return array{result: bool, warning: string|null}
     */
    public static function isFile(string $path): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => is_file($path)
        );
    }

    /**
     * rename() with its warning captured.
     *
     * @param string $from
     * @param string $to
     * @return array{result: bool, warning: string|null}
     */
    public static function rename(string $from, string $to): array {
        return ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
            static fn() => rename($from, $to)
        );
    }
}
