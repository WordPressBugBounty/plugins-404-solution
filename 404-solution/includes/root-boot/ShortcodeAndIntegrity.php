<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page-suggestions shortcode handler and runtime file-integrity verification.
 *
 * abj404_shortCodeListener() renders the [abj404_solution_page_suggestions]
 * shortcode (loading the plugin lazily). abj404_verify_runtime_integrity()
 * reports which required plugin files are missing, using the canonical
 * required-files list that abj404_get_required_runtime_files() builds from the
 * external data file includes/data/required-runtime-files.php.
 *
 * The add_shortcode() registration stays in 404-solution.php; this file only
 * defines the callback and the integrity-check helpers.
 */
// allow-no-test-found: boot-time global functions (shortcode handler + runtime integrity helpers) wired via add_shortcode in 404-solution.php; no same-named unit file. abj404_get_required_runtime_files and abj404_verify_runtime_integrity are exercised in SqlFileIntegrityListCompletenessTest.

if (!function_exists('abj404_get_required_runtime_files')) {
	/**
	 * Files required for a healthy runtime/plugin package.
	 * Covers boot-critical PHP files and essential SQL templates.
	 *
	 * The static lists are DATA, loaded from
	 * includes/data/required-runtime-files.php; the dynamic classmap file list
	 * is merged in here at runtime.
	 *
	 * @return array<int, string>
	 */
	function abj404_get_required_runtime_files() {
		$inc = ABJ404_PATH . 'includes/';
		$classmapFile = $inc . 'classmap.php';
		if (!file_exists($classmapFile)) {
			$classmapFile = dirname(__DIR__) . '/classmap.php';
		}
		$classmapFiles = array();
		if (file_exists($classmapFile)) {
			$classmap = require $classmapFile;
			if (is_array($classmap)) {
				foreach ($classmap as $classmapPath) {
					if (is_string($classmapPath)) {
						$classmapFiles[] = $classmapPath;
					}
				}
			}
		}

		$dataFile = dirname(__DIR__) . '/data/required-runtime-files.php';
		$lists = file_exists($dataFile) ? require $dataFile : array();
		if (!is_array($lists)) {
			$lists = array();
		}
		$bootRelative = isset($lists['boot']) && is_array($lists['boot']) ? $lists['boot'] : array();
		$sqlRelative = isset($lists['sql']) && is_array($lists['sql']) ? $lists['sql'] : array();
		$rootRelative = isset($lists['root']) && is_array($lists['root']) ? $lists['root'] : array();

		$bootFiles = array();
		foreach ($bootRelative as $relative) {
			if (is_string($relative)) {
				$bootFiles[] = $inc . $relative;
			}
		}
		$sqlFiles = array();
		foreach ($sqlRelative as $relative) {
			if (is_string($relative)) {
				$sqlFiles[] = $inc . $relative;
			}
		}
		$rootFiles = array();
		foreach ($rootRelative as $relative) {
			if (is_string($relative)) {
				$rootFiles[] = ABJ404_PATH . $relative;
			}
		}

		return array_values(array_unique(array_merge(
			$bootFiles,
			$rootFiles,
			$classmapFiles,
			$sqlFiles
		)));
	}
}

if (!function_exists('abj404_shortCodeListener')) {
	/**
	 * @param array<string, mixed>|string $atts
	 * @return string
	 */
	function abj404_shortCodeListener($atts) {
		if (!$GLOBALS['abj404_boot_ok']) {
			return '';
		}
		abj404_load_textdomain_if_needed();
	    require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
	    /** @var array<string, mixed> $safeAtts */
	    $safeAtts = is_array($atts) ? $atts : array();
	    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($safeAtts);
	}
}

if (!function_exists('abj404_register_page_suggestions_block')) {
	/**
	 * Register the dynamic page-suggestions block on the WordPress 5.0 floor.
	 *
	 * The block deliberately reuses the shortcode callback so its frontend
	 * output, request handling, and cache behavior stay on one rendering path.
	 * Block metadata registration is unavailable until WordPress 5.5, so the
	 * registration uses the PHP args form supported by the plugin's floor.
	 *
	 * @return void
	 */
	function abj404_register_page_suggestions_block() {
		if (!function_exists('register_block_type') || !function_exists('wp_register_script')) {
			return;
		}
		if (!defined('ABJ404_URL') || !defined('ABJ404_VERSION')) {
			return;
		}

		wp_register_script(
			'abj404-page-suggestions-block',
			ABJ404_URL . 'includes/js/pageSuggestionsBlock.js',
			array('wp-blocks', 'wp-element', 'wp-components', 'wp-i18n'),
			ABJ404_VERSION,
			true
		);

		if (class_exists('ABJ_404_Solution_WPUtils')) {
			ABJ_404_Solution_WPUtils::registerScriptTranslations();
		}

		register_block_type('abj404/page-suggestions', array(
			'title' => __('404 Suggestions', '404-solution'),
			'description' => __('Shows visitors a list of pages matching the URL they were looking for', '404-solution'),
			'category' => 'widgets',
			'editor_script' => 'abj404-page-suggestions-block',
			'render_callback' => 'abj404_shortCodeListener',
		));
	}
}

if (!function_exists('abj404_verify_runtime_integrity')) {
	/**
	 * Validate that required plugin files are present.
	 *
	 * @return array<int, string> Missing file paths.
	 */
	function abj404_verify_runtime_integrity() {
		$missing = array();
		foreach (abj404_get_required_runtime_files() as $path) {
			if (!file_exists($path)) {
				$missing[] = $path;
			}
		}
		return $missing;
	}
}
