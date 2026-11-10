<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where the plugin keeps fallback diagnostic files (abj404_debug_fallback.txt
 * and the legacy debug files that DebugLogFileStore migrates out of it).
 *
 * The directory follows the LOGICAL plugin path, plugins/<plugin-dir>/, as
 * WordPress's plugin_basename() reports it, and never the physical directory
 * the executing code sits in. When a host serves each release from its own
 * immutable code directory, the physical path changes with every update and
 * writable state would pile up in directories the next release deletes.
 *
 * Why this is a class and not a boot function: a function declared in a boot
 * file (404-solution.php, includes/root-boot/*.php) exists only in a request
 * whose boot is the release that declared it. A request that booted on release
 * N and then lazily loads a class file from release N+1 (WordPress replaced the
 * plugin directory mid-request) calls a function release N never declared, and
 * PHP has no function autoloader, so it fatals (production error report 762:
 * "Call to undefined function abj404_getWritablePluginFallbackDir()" at
 * DebugLogFileStore.php:233). A class is resolved by the autoloader, whose
 * classmap refreshes on a miss, so it always comes from the same release as its
 * caller. BootDefinedFunctionConsumersTest keeps class files from depending on
 * a boot function the previous release's boot lacks.
 *
 * resolve() makes the whole decision from its arguments; path() only gathers
 * those arguments from the plugin constants and WordPress.
 */
final class ABJ_404_Solution_FallbackStateDirectory {

    /**
     * The fallback state directory for this request.
     *
     * @return string Absolute directory path ending in a directory separator,
     *                or an empty string when ABJ404_PATH is not defined yet.
     */
    public static function path(): string {
        $physicalPath = defined('ABJ404_PATH') ? (string)ABJ404_PATH : '';
        if (!defined('ABJ404_FILE') || !defined('WP_PLUGIN_DIR') || !is_string(WP_PLUGIN_DIR)
                || !function_exists('plugin_basename')) {
            return $physicalPath;
        }

        return self::resolve($physicalPath, WP_PLUGIN_DIR, plugin_basename(ABJ404_FILE));
    }

    /**
     * Map a logical plugin basename onto the plugins directory.
     *
     * Only a clean relative "dir/file.php" basename is trusted: anything else
     * (empty, a bare file name, an absolute or drive-letter path, backslashes,
     * NUL bytes, or "." / ".." / empty segments) could steer a write outside
     * the plugins directory, so it yields the physical path instead.
     *
     * @param string $physicalPath Directory to use when no logical path can be trusted.
     * @param string $pluginsDirectory WordPress's plugins directory (WP_PLUGIN_DIR).
     * @param mixed $logicalBasename The value plugin_basename() returned for the main plugin file.
     * @return string Directory path ending in a directory separator, or $physicalPath.
     */
    public static function resolve(string $physicalPath, string $pluginsDirectory, $logicalBasename): string {
        if ($pluginsDirectory === '') {
            return $physicalPath;
        }
        if (!is_string($logicalBasename) || $logicalBasename === ''
                || strpos($logicalBasename, "\0") !== false
                || strpos($logicalBasename, '\\') !== false
                || $logicalBasename[0] === '/'
                || preg_match('/\A[A-Za-z]:\//', $logicalBasename) === 1) {
            return $physicalPath;
        }

        $segments = explode('/', $logicalBasename);
        if (count($segments) < 2) {
            return $physicalPath;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return $physicalPath;
            }
        }

        $separator = DIRECTORY_SEPARATOR;
        $directory = str_replace('/', $separator, dirname($logicalBasename));
        return rtrim($pluginsDirectory, '/\\') . $separator . $directory . $separator;
    }
}
