<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Invalidates the site-option cache entries affected by one option change.
 *
 * WordPress may serve an option from its individual cache key, the negative
 * cache, or the shared alloptions bundle. The bundle is only discarded when
 * it is unavailable, malformed, or actually contains the option being
 * invalidated; unrelated cached values retain their identity.
 */
final class ABJ_404_Solution_OptionCacheInvalidator {

    /**
     * Invalidate the cache entries used by get_option() for one site option.
     *
     * @param string $name Option name whose durable value changed or is suspect.
     * @return void
     */
    public static function site(string $name): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }

        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');

        $allOptions = function_exists('wp_cache_get')
            ? wp_cache_get('alloptions', 'options')
            : false;
        if (!is_array($allOptions) || array_key_exists($name, $allOptions)) {
            wp_cache_delete('alloptions', 'options');
        }
    }
}
