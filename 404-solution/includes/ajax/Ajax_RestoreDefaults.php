<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../repositories/SiteResourceLock.php';
require_once __DIR__ . '/../services/OptionCacheInvalidator.php';

/**
 * AJAX handler for restoring plugin settings to canonical defaults.
 *
 * Entry point: wp_ajax_abj404_restore_defaults.
 * Gates: valid nonce ('abj404_restore_defaults') AND plugin admin capability.
 * Action: writes PluginLogic::getDefaultOptions() through PluginLogic::updateOptions(),
 * preserving the DB_VERSION key so a settings restore does not trigger a schema downgrade.
 */
class ABJ_404_Solution_Ajax_RestoreDefaults {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /** @return self */
    public static function getInstance(): self {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_Ajax_RestoreDefaults();
        }
        return self::$instance;
    }

    /**
     * Initialize AJAX handler.
     * @return void
     */
    static function init(): void {
        $me = ABJ_404_Solution_Ajax_RestoreDefaults::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction(ABJ_404_Solution_PluginAjaxActions::hook('abj404_restore_defaults'),
            array($me, 'handleRestoreDefaults'));
    }

    /**
     * Handle the restore-defaults AJAX request. Verifies nonce + admin
     * capability, then overwrites abj404_settings with getDefaultOptions().
     * DB_VERSION is preserved from the current settings so a settings reset
     * does not look like a schema downgrade to DatabaseUpgradesEtc.
     * @return void
     */
    function handleRestoreDefaults(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-restore-defaults')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_restore_defaults');

        $abj404logic = abj_service('plugin_logic');

        $defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();

        $repository = abj_service('options_repository');
        if (!is_callable(array($repository, 'getRawSettingsSnapshot'))
            || !is_callable(array($repository, 'updateOptionsChecked'))) {
            wp_send_json_error(array(
                'message' => __('Settings could not be restored because the checked storage writer is unavailable.', '404-solution') . ' [ABJ404_SETTINGS_WRITER_UNAVAILABLE]',
            ), 503);
        }
        $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
        if (!$lock->acquire()) {
            wp_send_json_error(array(
                'message' => __('Settings could not be restored because another update is in progress.', '404-solution') . ' [ABJ404_SETTINGS_WRITE_BUSY]',
            ), 409);
        }
        try {
            $restored = $this->restoreOptionsChecked($repository, $defaults, $lock);
        } finally {
            $lock->release();
        }

        // JSON responses terminate PHP without running finally. Keep the
        // response outside the connection-owned lock's lifetime.
        if (!$restored) {
            wp_send_json_error(array(
                'message' => __('Settings could not be restored. Reload this page and try again.', '404-solution') . ' [ABJ404_SETTINGS_RESTORE_REFUSED]',
            ), 409);
        }

        wp_send_json_success(array(
            'message' => __('Settings restored to defaults.', '404-solution'),
        ));
    }

    /**
     * Restore defaults only while their preserved values have a stable raw authority.
     * @param ABJ_404_Solution_PluginLogicOptionsResolver $repository
     * @param array<string,mixed> $defaults
     */
    private function restoreOptionsChecked($repository, array $defaults,
        ABJ_404_Solution_SiteResourceLock $lock): bool {
        ABJ_404_Solution_OptionCacheInvalidator::site('abj404_settings');
        if (is_callable(array($repository, 'clearCache'))) {
            call_user_func(array($repository, 'clearCache'));
        }
        // Preserve DB_VERSION so the restore does not appear as a schema
        // downgrade to the upgrade engine.
        $before = $repository->getRawSettingsSnapshot();
        $current = $repository->getOptions(true);
        $expected = $repository->getRawSettingsSnapshot();
        if (!$this->sameRawSettingsSnapshot($before, $expected)) {
            if (is_callable(array($repository, 'clearCache'))) {
                call_user_func(array($repository, 'clearCache'));
            }
            ABJ_404_Solution_OptionCacheInvalidator::site('abj404_settings');
            $before = $expected;
            $current = $repository->getOptions(true);
            $expected = $repository->getRawSettingsSnapshot();
            if (!$this->sameRawSettingsSnapshot($before, $expected)) {
                return false;
            }
        }
        if (is_array($current) && array_key_exists('DB_VERSION', $current)) {
            $defaults['DB_VERSION'] = $current['DB_VERSION'];
        }
        if ($repository->updateOptionsChecked($defaults, $expected, $lock) !== true) {
            return false;
        }
        return true;
    }

    /**
     * @param array{status:string,value:string} $left
     * @param array{status:string,value:string} $right
     */
    private function sameRawSettingsSnapshot(array $left, array $right): bool {
        return isset($left['status'], $left['value'], $right['status'], $right['value'])
            && $left['status'] === $right['status'] && $left['value'] === $right['value'];
    }
}
