<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which component owns a source file or a hook callback: a plugin, a
 * must-use plugin, the theme, WordPress core, a wp-content drop-in, or 404
 * Solution itself. Used only after a request has died of the PHP time limit,
 * to turn recorded callback times into per-component seconds; a healthy
 * request never reaches this class, so its Reflection and path work cost
 * nothing on normal traffic.
 *
 * Paths only: the owner is read from where the defining file lives. Keys are
 * directory slugs (or the file name for single-file plugins and
 * mu-plugins); display names are the presenter's concern.
 */
final class ABJ_404_Solution_CodeOwnerResolver {

    /** @var string */
    private $abspath;
    /** @var string */
    private $content;
    /** @var string */
    private $plugins;
    /** @var string */
    private $muPlugins;
    /** @var array<int, string> */
    private $themes;
    /** @var string */
    private $self;

    /**
     * @param array{abspath: string, content: string, plugins: string, mu_plugins: string,
     *   themes: array<int, string>, self: string} $roots absolute directories.
     */
    public function __construct(array $roots) {
        $this->abspath = self::dir($roots['abspath']);
        $this->content = self::dir($roots['content']);
        $this->plugins = self::dir($roots['plugins']);
        $this->muPlugins = self::dir($roots['mu_plugins']);
        $this->themes = array_map(array(self::class, 'dir'), $roots['themes']);
        $this->self = self::dir($roots['self']);
    }

    /**
     * The roots of the running WordPress install.
     *
     * @return self
     */
    public static function forCurrentSite(): self {
        $abspath = defined('ABSPATH') ? (string)ABSPATH : '';
        $content = defined('WP_CONTENT_DIR') ? (string)WP_CONTENT_DIR : $abspath . 'wp-content';
        $themes = array();
        if (function_exists('get_theme_root')) {
            $themes[] = (string)get_theme_root();
        }
        $themes[] = $content . '/themes';
        return new self(array(
            'abspath' => $abspath,
            'content' => $content,
            'plugins' => defined('WP_PLUGIN_DIR') ? (string)WP_PLUGIN_DIR : $content . '/plugins',
            'mu_plugins' => defined('WPMU_PLUGIN_DIR') ? (string)WPMU_PLUGIN_DIR : $content . '/mu-plugins',
            'themes' => array_values(array_unique($themes)),
            'self' => defined('ABJ404_PATH') ? (string)ABJ404_PATH : dirname(__DIR__, 2),
        ));
    }

    /**
     * @param string $file absolute path.
     * @return array{kind: string, key: string} kind is one of self, plugin,
     *   mu_plugin, theme, core, dropin, other.
     */
    public function ownerOfFile(string $file): array {
        $path = str_replace('\\', '/', $file);
        if ($path === '') {
            return array('kind' => 'other', 'key' => '');
        }
        if ($this->self !== '/' && strpos($path, $this->self) === 0) {
            return array('kind' => 'self', 'key' => '404-solution');
        }
        $slug = self::firstSegmentUnder($path, $this->plugins);
        if ($slug !== null) {
            return array('kind' => 'plugin', 'key' => $slug);
        }
        $slug = self::firstSegmentUnder($path, $this->muPlugins);
        if ($slug !== null) {
            return array('kind' => 'mu_plugin', 'key' => $slug);
        }
        foreach ($this->themes as $themeRoot) {
            $slug = self::firstSegmentUnder($path, $themeRoot);
            if ($slug !== null) {
                return array('kind' => 'theme', 'key' => $slug);
            }
        }
        if ($this->abspath !== '/' && (strpos($path, $this->abspath . 'wp-includes/') === 0
                || strpos($path, $this->abspath . 'wp-admin/') === 0)) {
            return array('kind' => 'core', 'key' => 'wordpress');
        }
        $slug = self::firstSegmentUnder($path, $this->content);
        if ($slug !== null && strpos($slug, '.php') !== false) {
            return array('kind' => 'dropin', 'key' => $slug);
        }
        return array('kind' => 'other', 'key' => '');
    }

    /**
     * @param mixed $callback a hook entry's callable as registered.
     * @return array{kind: string, key: string}
     */
    public function ownerOfCallable($callback): array {
        $reflection = self::reflect($callback);
        if ($reflection === null) {
            return array('kind' => 'other', 'key' => '');
        }
        $file = $reflection->getFileName();
        return $this->ownerOfFile(is_string($file) ? $file : '');
    }

    /**
     * A short readable name: Class::method, function, or "closure in <file>".
     *
     * @param mixed $callback
     * @return string
     */
    public function describeCallable($callback): string {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback) && isset($callback[0], $callback[1]) && is_string($callback[1])) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (is_string($callback[0]) ? $callback[0] : '?');
            return $class . '::' . $callback[1];
        }
        if ($callback instanceof Closure) {
            $reflection = self::reflect($callback);
            $file = $reflection !== null ? $reflection->getFileName() : false;
            return 'closure in ' . (is_string($file) ? basename($file) . ':' . (int)$reflection->getStartLine() : 'unknown file');
        }
        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }
        return 'callable';
    }

    /**
     * Reflection for any registered callable, or null when it cannot be
     * reflected (not callable, method gone). Never throws.
     *
     * @param mixed $callback
     * @return ReflectionFunctionAbstract|null
     */
    private static function reflect($callback): ?ReflectionFunctionAbstract {
        try {
            if (is_array($callback) && isset($callback[0], $callback[1]) && is_string($callback[1])
                    && (is_object($callback[0]) || is_string($callback[0]))) {
                return new ReflectionMethod($callback[0], $callback[1]);
            }
            if (is_string($callback) && strpos($callback, '::') !== false) {
                list($class, $method) = explode('::', $callback, 2);
                return new ReflectionMethod($class, $method);
            }
            if (is_string($callback) || $callback instanceof Closure) {
                return new ReflectionFunction($callback);
            }
            if (is_object($callback) && method_exists($callback, '__invoke')) {
                return new ReflectionMethod($callback, '__invoke');
            }
        } catch (ReflectionException $e) {
            // The owner stays "other" and the seconds still count, under the
            // unattributed row; the reason is recorded for support.
            abj404_logPhpFallback('fatal-handler-fallback',
                'time attribution could not reflect a callback: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * The first path segment of $path under $root, or null when $path is not
     * under $root.
     *
     * @param string $path forward-slash path.
     * @param string $root directory with a trailing slash.
     * @return string|null
     */
    private static function firstSegmentUnder(string $path, string $root): ?string {
        if ($root === '/' || strpos($path, $root) !== 0) {
            return null;
        }
        $rest = substr($path, strlen($root));
        $slash = strpos($rest, '/');
        $segment = $slash === false ? $rest : substr($rest, 0, $slash);
        return ($segment === '' || $segment === false) ? null : $segment;
    }

    /**
     * Normalize a directory to forward slashes with one trailing slash.
     *
     * @param string $dir
     * @return string
     */
    private static function dir(string $dir): string {
        return rtrim(str_replace('\\', '/', $dir), '/') . '/';
    }
}
