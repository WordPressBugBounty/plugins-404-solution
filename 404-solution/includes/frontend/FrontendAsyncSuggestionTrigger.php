<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retired trigger boundary for frontend callers retained during a self-update.
 * Keep the original path and methods for old pipelines and dispatchers. A
 * reader opens and computes suggestions through the current shortcode/polling
 * flow, so this boundary deliberately performs no HTTP or cache work.
 */
// allow-no-test-found: old and mixed frontend entries are covered by PluginUpdateClassmapCompatibilityTest.
class ABJ_404_Solution_FrontendAsyncSuggestionTrigger {

    // A constructor-free userland class still accepts the old dependency
    // argument, and the retired trigger has no state to initialize.

    /** @param string $requestedURL Preceding release's requested URL. @return void */
    public function triggerIfNeeded(string $requestedURL): void {
        // Decline obsolete precomputation, including calls from already-linked old pipelines.
    }
}
