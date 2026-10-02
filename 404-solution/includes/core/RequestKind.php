<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that answers "what kind of WordPress request is this?" for
 * code deciding whether post-response work may run on it.
 *
 * Three answers are needed and they are not interchangeable: cron runs its own
 * work inline, an AJAX request must never be made to hold a PHP worker open,
 * and only an admin page render is a request whose user is an administrator
 * who can afford (and is served by) work after the page is delivered. A
 * front-end request is a visitor's, and post-response work never belongs on it.
 *
 * AJAX detection is layered on purpose: wp_doing_ajax() is unset before
 * WordPress core defines DOING_AJAX on some entry points, so the script name
 * and $pagenow are read as well. Keeping the layers in one class means a fix
 * to the detection reaches every caller.
 */
class ABJ_404_Solution_RequestKind {

    /** @return bool Whether this request is WP-Cron executing. */
    public static function isCron(): bool {
        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    /** @return bool Whether this request is an admin-ajax.php call. */
    public static function isAjax(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') {
            return true;
        }
        return isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'admin-ajax.php';
    }

    /**
     * An administrator's wp-admin page load: not a visitor's front-end request,
     * not AJAX (which is_admin() also reports true for), not cron.
     *
     * @return bool
     */
    public static function isAdminPageRender(): bool {
        return function_exists('is_admin') && is_admin() && !self::isAjax() && !self::isCron();
    }
}
