<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility policy for spell checkers already linked before a self-update.
 * Retain this class AND path: a preceding release's retained classmap requires
 * it while constructing SpellChecker (production reports 615 and 616).
 * Suggestion work now belongs to shortcode rendering and its reader's poll.
 */
// allow-no-test-found: real released-caller boot is covered by PluginUpdateClassmapCompatibilityTest.
class ABJ_404_Solution_SpellSuggestionShortcodeDetector {

    // No constructor is needed. PHP userland classes accept the preceding
    // caller's constructor arguments without retaining a retired dependency.

    /** @return bool False declines the preceding release's per-404 precompute. */
    public function does404PageHaveSuggestionsShortcode(): bool {
        return false;
    }
}
