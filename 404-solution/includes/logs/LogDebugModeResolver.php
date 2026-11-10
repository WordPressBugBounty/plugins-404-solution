<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether debug logging is currently enabled.
 *
 * The answer is the stored `debug_mode` setting, read through the logging
 * state store. Nothing else (no in-memory PluginLogic state) can override it.
 *
 * Pure policy: reads configuration, makes one boolean decision, writes nothing.
 */
class ABJ_404_Solution_LogDebugModeResolver {

    /** @return bool true if debug mode is on. false otherwise. */
    public function isDebug(): bool {
        // Raw read only via the recursion-safe logging state store. isDebug()
        // is on the logging write path; reading via getOptions() runs the
        // normalize pipeline, which logs on validation failure and re-enters
        // logging -- the 4.3.0 logging<->options OOM. The store reads debug_mode
        // with the raw, non-normalizing, non-logging accessor.
        $store = abj_service_optional('logging_state_store');
        if (is_object($store) && method_exists($store, 'isDebugMode')) {
            return $store->isDebugMode();
        }

        return false;
    }
}
