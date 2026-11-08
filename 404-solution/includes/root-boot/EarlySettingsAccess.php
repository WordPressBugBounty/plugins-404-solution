<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Early settings and path helpers used during plugin boot.
 *
 * abj404_getUploadsDir() resolves the plugin's temp upload directory and is
 * needed before any Loader.php initialization that might touch
 * Logging/SynchronizationUtils. abj404_get_settings_options() is the
 * centralized, container-aware read of the abj404_settings option and is the
 * plugin-root fallback boundary for raw settings reads.
 *
 * Both are required early (before Loader.php) because boot-time code paths call
 * them before the service container is fully available.
 */
// allow-no-test-found: boot-time global settings/path helpers required before Loader.php and the service container; no same-named unit file. abj404_get_settings_options (the raw-settings boundary) is exercised in OptionsRepositoryMigrationContractTest::testBootstrapRawSettingsReadsStayIsolatedToHelper.

// Used by multiple classes during early admin initialization (e.g. upgrade/migration paths).
// This must be defined before any Loader.php initialization that might touch Logging/SynchronizationUtils.
if (!function_exists('abj404_getUploadsDir')) {
	/** @return string */
	function abj404_getUploadsDir() {
		$uploadsDirArray = wp_upload_dir(null, false);
		$uploadsDir = $uploadsDirArray['basedir'];
		$uploadsDir .= DIRECTORY_SEPARATOR . 'temp_' . ABJ404_PP . DIRECTORY_SEPARATOR;
		return $uploadsDir;
	}
}

if (!function_exists('abj404_getWritablePluginFallbackDir')) {
	/**
	 * Resolve the canonical plugin directory for fallback diagnostic state.
	 *
	 * Fallback state must not live in the versioned physical code root: a host
	 * may execute an immutable generation while that canonical directory is
	 * replaced during an update. When WordPress can map ABJ404_FILE to a safe
	 * directory basename, derive the physical path from WP_PLUGIN_DIR; otherwise
	 * retain the historical ABJ404_PATH fallback for isolated/degraded boots.
	 *
	 * @return string
	 */
	function abj404_getWritablePluginFallbackDir() {
		$fallback = defined('ABJ404_PATH') ? (string)ABJ404_PATH : '';
		if (!defined('ABJ404_FILE') || !defined('WP_PLUGIN_DIR')
			|| !is_string(WP_PLUGIN_DIR) || WP_PLUGIN_DIR === ''
			|| !function_exists('plugin_basename')) {
			return $fallback;
		}

		$logicalBasename = plugin_basename(ABJ404_FILE);
		if (!is_string($logicalBasename) || $logicalBasename === ''
			|| strpos($logicalBasename, "\0") !== false
			|| strpos($logicalBasename, '\\') !== false
			|| $logicalBasename[0] === '/'
			|| preg_match('/\A[A-Za-z]:\//', $logicalBasename) === 1) {
			return $fallback;
		}

		$segments = explode('/', $logicalBasename);
		if (count($segments) < 2) {
			return $fallback;
		}
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return $fallback;
			}
		}

		$separator = DIRECTORY_SEPARATOR;
		$directory = str_replace('/', $separator, dirname($logicalBasename));
		$pluginRoot = rtrim((string)WP_PLUGIN_DIR, '/\\');
		return $pluginRoot . $separator . $directory . $separator;
	}
}

if (!function_exists('abj404_option_shape_read_available')) {
	/**
	 * Whether ABJ_404_Solution_OptionShapeRead, the only reader of plugin
	 * options, can be called. Boot and fallback paths run before the autoloader
	 * is registered or after it failed; the accessor depends on no other plugin
	 * file, so it is required directly when it is not loaded yet.
	 *
	 * @return bool False only when the accessor's file is missing or unreadable.
	 */
	function abj404_option_shape_read_available() {
		if (class_exists('ABJ_404_Solution_OptionShapeRead', false)) {
			return true;
		}
		$path = __DIR__ . '/../services/OptionShapeRead.php';
		if (!is_readable($path)) {
			return false;
		}
		require_once $path;
		return class_exists('ABJ_404_Solution_OptionShapeRead', false);
	}
}

if (!function_exists('abj404_get_settings_options')) {
	/**
	 * Centralized settings read so call sites don't repeat option-shape checks.
	 * This is the plugin-root fallback boundary for raw abj404_settings reads:
	 * ordinary callers must use this helper or options_repository.
	 *
	 * @return array<string, mixed>
	 */
	function abj404_get_settings_options() {
		if (function_exists('abj_service_optional')) {
			$optionsRepository = abj_service_optional('options_repository');
			if (is_object($optionsRepository) && method_exists($optionsRepository, 'getOptions')) {
				try {
					$options = $optionsRepository->getOptions(true);
					if (is_array($options)) {
						$normalizedOptions = array();
						foreach ($options as $key => $value) {
							if (is_string($key)) {
								$normalizedOptions[$key] = $value;
							}
						}
						return $normalizedOptions;
					}
					if (function_exists('abj404_logRuntimeWarning')) {
						abj404_logRuntimeWarning('options_repository->getOptions(true) returned ' . gettype($options) . '; falling back to raw bootstrap option read');
					}
				} catch (\Throwable $e) {
					if (function_exists('abj404_logRuntimeWarning')) {
						abj404_logRuntimeWarning('options_repository->getOptions(true) failed while reading settings options; falling back to raw bootstrap option read', $e);
					} else {
						abj404_logPhpFallback(
							'early-boot',
							'options_repository->getOptions(true) failed while reading settings options; falling back to raw bootstrap option read (' . $e->getMessage() . ')'
						);
					}
				}
			}
		}
		// The shape-checked read, so a persistent object cache that answers with
		// a non-array cannot read as no settings.
		$options = abj404_option_shape_read_available() && function_exists('get_option')
			? ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_settings') : false;
		if (!is_array($options)) {
			return array();
		}
		$normalizedOptions = array();
		foreach ($options as $key => $value) {
			if (is_string($key)) {
				$normalizedOptions[$key] = $value;
			}
		}
		return $normalizedOptions;
	}
}

if (!function_exists('abj404_get_raw_setting_value')) {
	/**
	 * Read one setting without resolving or normalizing the options repository.
	 *
	 * This is the degraded/cold-boot companion to the repository's
	 * getRawSettingValue() accessor. It stays in this bootstrap module so
	 * OptionShapeRead remains the only raw reader before the service container
	 * is ready.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	function abj404_get_raw_setting_value($key, $default = null) {
		if (!is_string($key) || $key === '' || !abj404_option_shape_read_available()
			|| !function_exists('get_option')) {
			return $default;
		}
		$options = ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_settings');
		if (!is_array($options) || !array_key_exists($key, $options)) {
			return $default;
		}
		return $options[$key];
	}
}
