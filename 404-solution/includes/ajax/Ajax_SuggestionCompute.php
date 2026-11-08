<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retired AJAX endpoint for hooks retained from before a plugin self-update.
 * A fresh boot registers only polling. Old authenticated or anonymous hooks
 * can still enter this original class/path; reject them before reading input
 * or modifying jobs so an old callback cannot restart the retired worker.
 */
// allow-no-test-found: both real old AJAX hooks and adversarial inputs are covered by PluginUpdateClassmapCompatibilityTest.
class ABJ_404_Solution_Ajax_SuggestionCompute {

    /** @return void Terminates the legacy request with HTTP 410. */
    public static function computeSuggestions(): void {
        wp_die('[ABJ404_SUGGESTION_COMPUTE_RETIRED] Suggestions are computed by the page polling endpoint.',
            '', array('response' => 410));
    }
}
