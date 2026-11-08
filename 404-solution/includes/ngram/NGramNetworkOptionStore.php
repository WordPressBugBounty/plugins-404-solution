<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Network-aware option storage helper for the n-gram cache rebuild
 * subsystem.
 *
 * In a multisite network-activated installation, n-gram rebuild
 * progress (offset, current site, total sites, initialization flag)
 * must be shared across all sites in the network so each scheduled
 * batch sees the same state. In a per-site install, the same options
 * live in `wp_options`.
 *
 * A null value is this adapter's absence contract. WordPress persists a null
 * update as an empty string, so nullable reads normalize that legacy marker
 * and nullable writes use the matching delete API.
 *
 * This collaborator picks the right WordPress option API (site option
 * vs option) based on `is_plugin_active_for_network(ABJ404_FILE)` and
 * surfaces a stable get/set contract to callers (scheduler, async
 * batch runner, orchestrator).
 */
class ABJ_404_Solution_NGramNetworkOptionStore {

    /**
     * Returns true when the plugin is network-activated in a multisite
     * install — i.e. one shared activation across all sites.
     *
     * Falls through to false on single-site (no `is_multisite()`).
     *
     * @return bool
     */
    public function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Read a scalar progress option (flag, cursor, counter) from the
     * network-wide store when network-activated, otherwise from the
     * site-local store.
     *
     * The object cache answers before the options table does, and a
     * misbehaving one can answer with an array. Read as "unset", that lie
     * reschedules a rebuild the site finished or restarts a walk at zero, so
     * the read recovers the stored value instead
     * ({@see ABJ_404_Solution_OptionShapeRead}).
     *
     * @param string $option_name
     * @param mixed $default
     * @return mixed Null means absent when the default is null.
     */
    public function getOption($option_name, $default = false) {
        return $this->read($option_name, $default, ABJ_404_Solution_OptionShapeRead::SHAPE_SCALAR);
    }

    /**
     * Read an array option (a state record or site list) with the same
     * scoping and cache recovery as {@see getOption()}.
     *
     * @param string $option_name
     * @param mixed $default
     * @return mixed Null means absent when the default is null.
     */
    public function getArrayOption($option_name, $default = null) {
        return $this->read($option_name, $default, ABJ_404_Solution_OptionShapeRead::SHAPE_ARRAY);
    }

    /**
     * @param string $option_name
     * @param mixed $default
     * @param string $shape
     * @return mixed
     */
    private function read($option_name, $default, string $shape) {
        $value = ABJ_404_Solution_OptionShapeRead::read(array(
            'name' => (string)$option_name,
            'shape' => $shape,
            'default' => $default,
            'scope' => $this->isNetworkActivated()
                ? ABJ_404_Solution_OptionShapeRead::SCOPE_NETWORK
                : ABJ_404_Solution_OptionShapeRead::SCOPE_SITE,
        ));

        // WordPress serializes update_option($name, null) as an empty string.
        // Within this adapter, a nullable read represents absence, including
        // the empty-string clear marker written by older releases.
        return $default === null && $value === '' ? null : $value;
    }

    /**
     * Write an option to the network-wide store when network-activated,
     * otherwise to the site-local store.
     *
     * @param string $option_name
     * @param mixed $value
     * @return bool Null values delete the correctly scoped option.
     */
    public function updateOption($option_name, $value) {
        if ($this->isNetworkActivated()) {
            if ($value === null) {
                return delete_site_option($option_name);
            }
            return update_site_option($option_name, $value);
        }
        if ($value === null) {
            return delete_option($option_name);
        }
        return update_option($option_name, $value);
    }
}
