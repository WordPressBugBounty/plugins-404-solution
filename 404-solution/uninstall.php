<?php
/**
 * Uninstall handler for 404 Solution plugin
 *
 * This file is triggered when the plugin is deleted via WordPress admin.
 * It handles cleanup of database tables, options, and cron jobs based on
 * user preferences saved from the uninstall modal.
 *
 * @package 404Solution
 * @since 2.36.11
 */

// Security: Ensure this file is called by WordPress during uninstall
// WordPress core enforces the 'delete_plugins' capability in wp-admin/plugins.php
// before this file is ever reached, so no additional permission checks are needed.
// The WP_UNINSTALL_PLUGIN constant check prevents direct file access and is the
// WordPress-recommended security pattern per the Plugin Handbook.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Additional security: Verify user has permission to delete plugins
// Allow WP-CLI and other automated contexts where current_user_can() returns false
if (!defined('WP_CLI') && !current_user_can('activate_plugins')) {
    exit;
}

// Read version from main plugin file header (single source of truth)
if (!defined('ABJ404_VERSION')) {
    $plugin_data = get_file_data(__DIR__ . '/404-solution.php', array('Version' => 'Version'));
    define('ABJ404_VERSION', $plugin_data['Version']);
}

// Load the standalone teardown and its minimal, explicitly loaded collaborators.
require_once __DIR__ . '/includes/uninstall/Uninstaller.php';

// Removes the plugin from every site it may have run on, each with the
// choices saved from the uninstall modal (see uninstallPlugin()).
ABJ_404_Solution_Uninstaller::uninstallPlugin();

// All done! WordPress will now delete the plugin files.
