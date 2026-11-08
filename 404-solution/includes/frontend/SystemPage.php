<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../repositories/SiteResourceLock.php';
require_once __DIR__ . '/../repositories/LegacySuggestionPageRecovery.php';

/**
 * Manages the auto-created "Page Not Found" system page used by 404 Solution
 * when the "Suggest similar pages" behavior is selected.
 *
 * The system page contains the [abj404_solution_page_suggestions] shortcode and
 * renders through the theme's own page.php template, inheriting all theme fonts,
 * colors, and layout automatically.
 *
 * The page is tagged with post meta _abj404_system_page = 1 so the plugin can
 * find it reliably.
 *
 * What is shown to people about the page (deleted-page notice, editor notices,
 * front-end banner) is SystemPageNotices; this class owns the page itself.
 */
class ABJ_404_Solution_SystemPage {

    /** Post meta key used to identify the system page. */
    const META_KEY = ABJ_404_Solution_LegacySuggestionPageRecovery::META_KEY;

    /** SEO-plugin noindex metadata shared by created and recovered pages. */
    private const NOINDEX_META = ABJ_404_Solution_LegacySuggestionPageRecovery::NOINDEX_META;

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it. Mirrors the setInstance()
     * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /** @return self */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Find the existing system page ID, or return 0 if none exists.
     *
     * @return int The page ID, or 0 if not found.
     */
    public function getSystemPageId(): int {
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => array('publish', 'draft', 'private'),
            'meta_key'       => self::META_KEY,
            'meta_value'     => '1',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'cache_results'  => false,
        ));

        if (!empty($pages) && is_array($pages)) {
            return (int) $pages[0];
        }

        return 0;
    }

    /**
     * Check whether the system page exists and is published.
     *
     * @return bool
     */
    public function systemPageExists(): bool {
        $pageId = $this->getSystemPageId();
        if ($pageId <= 0) {
            return false;
        }
        return get_post_status($pageId) === 'publish';
    }

    /**
     * Ensure the system page exists and is published. If the page already
     * exists and is published, return its ID. Otherwise, republish or create it.
     *
     * @return int The page ID, or 0 on failure.
     */
    public function ensureSystemPage(): int {
        return $this->createSystemPage();
    }

    /**
     * Legacy alias for callers that still use the old read-like name.
     *
     * @return int The page ID, or 0 on failure.
     */
    public function getOrCreateSystemPage(): int {
        return $this->ensureSystemPage();
    }

    /**
     * Find and publish the system page, or create it once with the shortcode.
     *
     * @return int The published page ID, or 0 on failure.
     */
    public function createSystemPage(): int {
        $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
        if (!$lock->acquire()) { return 0; }
        try {
            // Setup, first reads and recreation all arbitrate at this boundary.
            // Suppress cached query results taken before another creator committed.
            $existingId = $this->getSystemPageId();
            if ($existingId > 0) {
                if (get_post_status($existingId) === 'publish') { return $existingId; }
                // If the page exists but is trashed/drafted, try to republish it.
                $result = wp_update_post(array('ID' => $existingId, 'post_status' => 'publish'), true);
                return !is_wp_error($result) && (int)$result > 0 ? $existingId : 0;
            }
            return $this->insertSystemPage();
        } finally {
            $lock->release();
        }
    }

    /** @return int ID of the new page; called only while creation is locked. */
    private function insertSystemPage(): int {
        $title = __('Page Not Found', '404-solution');

        try {
            $pageId = wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => '[abj404_solution_page_suggestions]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id() ?: 1,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
                // WordPress writes meta_input before post-save callbacks. Publish
                // identity and noindex metadata before those callbacks can find us.
                'meta_input' => array_merge(array(self::META_KEY => '1'), self::NOINDEX_META),
            ), true);
        } catch (Throwable $failure) {
            // A post-save callback can throw after core committed our post and
            // meta_input. Recover that identified page under the same lock,
            // without letting boot-time logging publish a fallback settings row.
            abj404_logPhpFallback('system-page-creation', 'WordPress insertion interrupted by '
                . get_class($failure) . ' (code ' . $failure->getCode() . ') at '
                . $failure->getFile() . ':' . $failure->getLine() . ': ' . $failure->getMessage());
            $pageId = $this->getSystemPageId();
        }

        if (is_wp_error($pageId) || (int) $pageId <= 0) {
            $detail = is_object($pageId) && method_exists($pageId, 'get_error_message')
                ? $pageId->get_error_message() : 'WordPress returned no post ID';
            abj404_logPhpFallback('system-page-creation', 'Could not insert suggestion page: ' . $detail);
            return 0;
        }

        // Metadata writes can be declined independently of the post INSERT.
        // Judge the durable end state, not update_post_meta's changed-byte flag.
        if (!(new ABJ_404_Solution_LegacySuggestionPageRecovery())->persistPageMetadata((int)$pageId, array_merge(array(self::META_KEY => '1'), self::NOINDEX_META))) {
            abj404_logPhpFallback('system-page-creation', 'Metadata incomplete for new page ' . (int)$pageId
                . '; rolling back the incomplete suggestion destination.');
            $deleted = wp_delete_post((int)$pageId, true);
            if (!$deleted) {
                abj404_logPhpFallback('system-page-creation', 'Could not roll back new page ' . (int)$pageId);
            }
            return 0;
        }

        return (int) $pageId;
    }

    /**
     * Delete the system page permanently.
     *
     * @return bool True if deleted, false otherwise.
     */
    public function deleteSystemPage(): bool {
        $pageId = $this->getSystemPageId();
        if ($pageId <= 0) {
            return false;
        }

        $result = wp_delete_post($pageId, true);
        return $result !== false && $result !== null;
    }

    /**
     * Check if a given post ID is the system page.
     *
     * @param int $postId
     * @return bool
     */
    public static function isSystemPage(int $postId): bool {
        if ($postId <= 0) {
            return false;
        }
        return get_post_meta($postId, self::META_KEY, true) === '1';
    }

    /**
     * When the system page is deleted or trashed externally, flip the behavior
     * setting to 'theme_default' and set a transient for the admin notice.
     *
     * @return void
     */
    public function handleSystemPageDeleted(): void {
        $logic = abj_service('plugin_logic');
        $options = abj_service('options_repository')->getOptions(true);

        if (isset($options['dest404_behavior']) && $options['dest404_behavior'] === 'suggest') {
            $options['dest404_behavior'] = 'theme_default';
            $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
            abj_service('options_repository')->updateOptions($options);

            set_transient(ABJ_404_Solution_SystemPageNotices::DELETED_TRANSIENT, '1', DAY_IN_SECONDS);
        }
    }

    /**
     * On each 404 hit, verify that the system page still exists when behavior is 'suggest'.
     * This catches bulk deletes, DB restores, cleanup plugins, etc.
     *
     * @return void
     */
    public function verifySystemPageOnRequest(): void {
        $logic = abj_service('plugin_logic');
        $options = abj_service('options_repository')->getOptions(true);

        if (!isset($options['dest404_behavior']) || $options['dest404_behavior'] !== 'suggest') {
            return;
        }

        if (!$this->systemPageExists()) {
            $this->handleSystemPageDeleted();
        }
    }

    /**
     * Hook: before_delete_post / wp_trash_post - detect when system page is trashed/deleted.
     *
     * @param int $postId
     * @return void
     */
    public static function onPostDeleteOrTrash(int $postId): void {
        if (!self::isSystemPage($postId)) {
            return;
        }

        $instance = self::getInstance();
        $instance->handleSystemPageDeleted();
    }

    /**
     * Hook: admin_init - handle the recreate system page action.
     *
     * @return void
     */
    public static function handleRecreateAction(): void {
        if (!isset($_GET['abj404_recreate_system_page'])) {
            return;
        }

        if (!ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin()) {
            return;
        }

        if (!wp_verify_nonce(
            ABJ_404_Solution_RequestInputNormalizer::readText($_GET, array('name' => '_wpnonce')),
            'abj404_recreate_system_page'
        )) {
            return;
        }

        $instance = self::getInstance();
        $pageId = $instance->createSystemPage();

        if ($pageId > 0) {
            $logic = abj_service('plugin_logic');
            $options = abj_service('options_repository')->getOptions(true);
            $options['dest404_behavior'] = 'suggest';
            $options['dest404page'] = $pageId . '|' . ABJ404_TYPE_POST;
            abj_service('options_repository')->updateOptions($options);
        }

        // Redirect back to settings page (without the action param)
        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
        if (is_callable(array('ABJ_404_Solution_RedirectLoopGuard', 'permitsDestination'))
            && !ABJ_404_Solution_RedirectLoopGuard::permitsDestination($settingsUrl)) {
            if (is_callable(array('ABJ_404_Solution_WPNotices', 'respondToRedirectRefusal'))) {
                ABJ_404_Solution_WPNotices::respondToRedirectRefusal($settingsUrl);
            }
            // Keep rendering the current admin page; the guard's existing
            // notice path explains the refused destination.
            return;
        }
        wp_safe_redirect($settingsUrl);
        exit;
    }

    /**
     * Hook: wp_robots - add noindex to the system page.
     *
     * @param array<string, bool|string> $robots
     * @return array<string, bool|string>
     */
    public static function addNoindexToSystemPage($robots) {
        if (!is_page()) {
            return $robots;
        }

        $postId = get_the_ID();
        if ($postId && self::isSystemPage($postId)) {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    /**
     * Hook: wp_page_menu_args / wp_get_nav_menu_items - exclude system page from nav menus.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function excludeFromPageMenu($args) {
        $pageId = self::getInstance()->getSystemPageId();
        if ($pageId > 0) {
            $existing = isset($args['exclude']) ? $args['exclude'] : '';
            $excludeList = $existing !== '' ? $existing . ',' . $pageId : (string) $pageId;
            $args['exclude'] = $excludeList;
        }
        return $args;
    }

    /**
     * Register all WordPress hooks for system page management.
     *
     * @return void
     */
    public static function registerHooks(): void {
        // Deletion protection
        add_action('before_delete_post', array(__CLASS__, 'onPostDeleteOrTrash'));
        add_action('wp_trash_post', array(__CLASS__, 'onPostDeleteOrTrash'));

        // Admin notices
        add_action('admin_notices', array(ABJ_404_Solution_SystemPageNotices::class, 'maybeShowDeletedPageNotice'));

        // Recreate action
        add_action('admin_init', array(__CLASS__, 'handleRecreateAction'));

        // Editor notice (classic editor)
        add_action('edit_form_after_title', array(ABJ_404_Solution_SystemPageNotices::class, 'showEditorNotice'));

        // Editor notice (block editor / Gutenberg)
        add_action('enqueue_block_editor_assets', array(ABJ_404_Solution_SystemPageNotices::class, 'enqueueBlockEditorNotice'));

        // Exclude from sitemaps (WordPress 5.5+ native robots)
        add_filter('wp_robots', array(__CLASS__, 'addNoindexToSystemPage'));

        // Exclude from page menus
        add_filter('wp_page_menu_args', array(__CLASS__, 'excludeFromPageMenu'));

        // Admin-only frontend 404 banner
        add_action('template_redirect', array(ABJ_404_Solution_SystemPageNotices::class, 'maybeShowAdminFrontend404Banner'));
    }
}
