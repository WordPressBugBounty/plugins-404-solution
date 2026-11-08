<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads a plugin option whose stored shape is known, and recovers when the
 * object cache answers with a value of another shape.
 *
 * WordPress answers get_option() and get_site_option() from the object cache
 * whenever the cache holds the key, without reading the options table. A
 * persistent object cache (Redis, Memcached, LiteSpeed) can hold a value the
 * plugin never wrote: a serializer mismatch between PHP builds, a key
 * collision with another site, or a faulty drop-in hands back an array where
 * the plugin stores a scalar, or a string where it stores an array. A reader
 * that treats "not the shape I write" as "unset" then acts on a value that is
 * not in the database: it reschedules an n-gram rebuild the site finished
 * long ago, restarts a cursor at zero, or rebuilds the settings row from an
 * empty array and erases every setting.
 *
 * The options table still holds the truth. When the value read has the wrong
 * shape, this reader drops the option's cache entries and reads once more, so
 * WordPress reads the row and re-caches it. A value that still has the wrong
 * shape after that re-read is what the database holds, and is returned as is:
 * the caller's own handling of a damaged stored value applies to it.
 *
 * A value identical to the caller's default is the absent case and is never
 * re-read. Neither is the empty string: it is what WordPress persists for an
 * update_option() of null or false, so it is a legitimate stored value of
 * either shape.
 *
 * Extension point: the abj404_option_cache_shape_mismatch action fires after
 * every recovery with the option name, scope, the type the cache returned and
 * the type the re-read returned, for sites that want to report or count a
 * misbehaving object cache.
 */
final class ABJ_404_Solution_OptionShapeRead {

    /** Booleans, integers, floats and strings: what update_option() stores for a flag, cursor or version. */
    const SHAPE_SCALAR = 'scalar';

    /** An array: what update_option() stores for a settings record or a state record. */
    const SHAPE_ARRAY = 'array';

    /** The site's own option (get_option()). */
    const SCOPE_SITE = 'site';

    /** The network's option (get_site_option()); the site option on a single-site install. */
    const SCOPE_NETWORK = 'network';

    /**
     * Read one option and recover from a wrong-shaped cached value.
     *
     * @param array{name:string, shape:string, default?:mixed, scope?:string, networkId?:int} $read
     *        name: the option name. shape: SHAPE_SCALAR or SHAPE_ARRAY.
     *        default: returned when the option is absent (false when omitted).
     *        scope: SCOPE_SITE (the default) or SCOPE_NETWORK.
     *        networkId: with SCOPE_NETWORK, read that network's option
     *        (get_network_option()) instead of the current network's.
     * @return mixed The stored value, or the default when the option is absent.
     */
    public static function read(array $read) {
        $name = (string)$read['name'];
        $shape = (string)$read['shape'];
        $default = array_key_exists('default', $read) ? $read['default'] : false;
        $network = ($read['scope'] ?? self::SCOPE_SITE) === self::SCOPE_NETWORK;
        $networkId = $network && isset($read['networkId']) ? (int)$read['networkId'] : null;

        $rawMismatch = self::invalidateRawArrayCacheIfNeeded(array(
            'name' => $name,
            'shape' => $shape,
            'network' => $network,
        ));
        $value = self::fetch($name, $default, $network, $networkId);
        if ($rawMismatch !== null && function_exists('do_action')) {
            do_action('abj404_option_cache_shape_mismatch', array(
                'option' => $name,
                'scope' => $network ? self::SCOPE_NETWORK : self::SCOPE_SITE,
                'expected' => $shape,
                'cached' => $rawMismatch,
                'reread' => gettype($value),
                'boundary' => 'serialized_site_option_cache',
                'raw_expected' => 'scalar_or_serialized',
            ));
        }
        if ($value === $default || $value === '' || self::hasShape($value, $shape)) {
            return $value;
        }

        self::invalidate($name, $network, $networkId);
        $reread = self::fetch($name, $default, $network, $networkId);
        if (function_exists('do_action')) {
            do_action('abj404_option_cache_shape_mismatch', array(
                'option' => $name,
                'scope' => $network ? self::SCOPE_NETWORK : self::SCOPE_SITE,
                'expected' => $shape,
                'cached' => gettype($value),
                'reread' => gettype($reread),
            ));
        }
        return $reread;
    }

    /**
     * Reject an array/object returned as the raw site-option cache value.
     * WordPress stores array/object option values serialized; decoded arrays
     * are valid only after get_option() has performed the normal read.
     *
     * @param array{name:string,shape:string,network:bool} $options
     * @return string|null The invalid raw type, or null when no raw mismatch exists.
     */
    private static function invalidateRawArrayCacheIfNeeded(array $options): ?string {
        $name = $options['name'];
        $shape = $options['shape'];
        $network = $options['network'];
        if ($shape !== self::SHAPE_ARRAY || !function_exists('wp_cache_get')
            || !function_exists('wp_cache_delete')) {
            return null;
        }
        if ($network && (!function_exists('is_multisite') || is_multisite())) {
            return null;
        }

        $found = false;
        $allOptions = wp_cache_get('alloptions', 'options', false, $found);
        if (is_array($allOptions) && array_key_exists($name, $allOptions)) {
            $raw = $allOptions[$name];
        } else {
            $raw = wp_cache_get($name, 'options', false, $found);
            if ($raw === false) {
                return null;
            }
        }
        if (!is_array($raw) && !is_object($raw)) {
            return null;
        }
        self::invalidate($name, false, null);
        return gettype($raw);
    }

    /**
     * Read a site option the plugin stores as a scalar (flag, cursor, version).
     *
     * @param string $name
     * @param mixed $default Returned when the option is absent.
     * @return mixed
     */
    public static function scalar(string $name, $default = false) {
        return self::read(array('name' => $name, 'shape' => self::SHAPE_SCALAR, 'default' => $default));
    }

    /**
     * Read a site option the plugin stores as an array (settings, state record, list).
     *
     * @param string $name
     * @param mixed $default Returned when the option is absent.
     * @return mixed
     */
    public static function arrayValue(string $name, $default = false) {
        return self::read(array('name' => $name, 'shape' => self::SHAPE_ARRAY, 'default' => $default));
    }

    /**
     * @param mixed $value
     * @param string $shape
     * @return bool
     */
    private static function hasShape($value, string $shape): bool {
        return $shape === self::SHAPE_ARRAY ? is_array($value) : is_scalar($value);
    }

    /**
     * Read the network's option as an array (preferences or a work list).
     *
     * @param string $name
     * @param mixed $default Returned when the option is absent.
     * @param int|null $networkId A specific network; null is the current one.
     * @return mixed
     */
    public static function networkArrayValue(string $name, $default = false, ?int $networkId = null) {
        $read = array('name' => $name, 'shape' => self::SHAPE_ARRAY, 'default' => $default, 'scope' => self::SCOPE_NETWORK);
        if ($networkId !== null) {
            $read['networkId'] = $networkId;
        }
        return self::read($read);
    }

    /**
     * Read the network's option as a scalar (flag, deadline, count).
     *
     * @param string $name
     * @param mixed $default Returned when the option is absent.
     * @return mixed
     */
    public static function networkScalar(string $name, $default = false) {
        return self::read(array('name' => $name, 'shape' => self::SHAPE_SCALAR, 'default' => $default,
            'scope' => self::SCOPE_NETWORK));
    }

    /**
     * @param string $name
     * @param mixed $default
     * @param bool $network
     * @param int|null $networkId
     * @return mixed
     */
    private static function fetch(string $name, $default, bool $network, ?int $networkId) {
        if ($networkId !== null) {
            return get_network_option($networkId, $name, $default);
        }
        return $network ? get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * Drop this option's cache key and negative entry, plus the autoload
     * bundle when it contains this option or is malformed/unavailable,
     * in the scope the read used. get_site_option() on a single-site
     * install is get_option(), so it uses the site keys there.
     *
     * @param string $name
     * @param bool $network
     * @param int|null $networkId
     * @return void
     */
    private static function invalidate(string $name, bool $network, ?int $networkId): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        if ($network && function_exists('is_multisite') && is_multisite()) {
            if ($networkId === null) {
                $networkId = function_exists('get_current_network_id') ? (int)get_current_network_id() : 1;
            }
            wp_cache_delete($networkId . ':' . $name, 'site-options');
            wp_cache_delete($networkId . ':notoptions', 'site-options');
            return;
        }
        ABJ_404_Solution_OptionCacheInvalidator::site($name);
    }
}
