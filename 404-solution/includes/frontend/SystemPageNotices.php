<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What 404 Solution tells people about its auto-created "Page Not Found"
 * system page: the plugin-settings notice after the page is deleted, the notices
 * on its classic and block editor screens, and the admin-only banner on the
 * front-end 404.
 *
 * Presentation only. SystemPage owns the page record and decides when it has
 * been deleted (it sets DELETED_TRANSIENT); this class reads that flag and
 * renders the copy through the external templates in includes/html. All
 * methods are static WordPress hook callbacks, registered by
 * SystemPage::registerHooks().
 */
class ABJ_404_Solution_SystemPageNotices {

    /** Transient SystemPage sets when the page is deleted; cleared once the notice has been shown. */
    const DELETED_TRANSIENT = 'abj404_system_page_deleted';

    /**
     * The plugin's Options screen, where the suggestion page can be recreated or replaced.
     *
     * @return string
     */
    private static function settingsUrl(): string {
        return admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
    }

    /**
     * Hook: admin_notices on plugin settings page - show notice if system page was deleted.
     *
     * @return void
     */
    public static function maybeShowDeletedPageNotice(): void {
        if (get_transient(self::DELETED_TRANSIENT) !== '1') {
            return;
        }

        // Only show on our plugin's settings page
        $page = ABJ_404_Solution_RequestInputNormalizer::readText($_GET, array('name' => 'page'));
        if ($page !== ABJ404_PP) {
            return;
        }

        $settingsUrl = self::settingsUrl();
        $recreateUrl = wp_nonce_url(
            add_query_arg('abj404_recreate_system_page', '1', $settingsUrl),
            'abj404_recreate_system_page'
        );

        $tpl = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/systemPageDeletedAdminNotice.html'
        );
        $body = esc_html__('Your 404 suggestion page was deleted.', '404-solution')
            . ' <a href="' . esc_url($recreateUrl) . '">' . esc_html__('Recreate it', '404-solution') . '</a>'
            . ' ' . esc_html__('or choose a different option below.', '404-solution');
        echo strtr($tpl, ['{message_with_link}' => $body]);

        delete_transient(self::DELETED_TRANSIENT);
    }

    /**
     * Hook: edit_form_after_title - show admin notice when editing the system page.
     *
     * @param \WP_Post $post
     * @return void
     */
    public static function showEditorNotice($post): void {
        if (!ABJ_404_Solution_SystemPage::isSystemPage($post->ID)) {
            return;
        }

        $settingsUrl = self::settingsUrl();
        $tpl = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/systemPageEditorNotice.html'
        );
        $body = esc_html__('This page is used by 404 Solution to display suggested pages to visitors.', '404-solution')
            . ' <a href="' . esc_url($settingsUrl) . '">' . esc_html__('Learn more', '404-solution') . '</a>';
        echo strtr($tpl, ['{message_with_link}' => $body]);
    }

    /**
     * Hook: template_redirect - show admin-only banner when system page is deleted
     * and an admin visits a 404 page.
     *
     * @return void
     */
    public static function maybeShowAdminFrontend404Banner(): void {
        if (!is_404()) {
            return;
        }

        if (!is_user_logged_in() || !ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin()) {
            return;
        }

        // Check if behavior was 'suggest' but page was deleted
        if (get_transient(self::DELETED_TRANSIENT) !== '1') {
            return;
        }

        $settingsUrl = self::settingsUrl();

        add_action('wp_footer', function() use ($settingsUrl) {
            /* allow-hardcoded-color: wp_footer front-end banner; admin theme vars are not loaded on the public site */
            $tpl = ABJ_404_Solution_FileSystemService::readFileContents(
                dirname(__DIR__) . '/html/systemPageAdminFrontend404Banner.html'
            );
            echo strtr($tpl, [
                '{message}'        => esc_html__('Your 404 Solution suggestion page was deleted. Visitors are seeing this default 404 page instead.', '404-solution'),
                '{settings_url}'   => esc_url($settingsUrl),
                '{settings_label}' => esc_html__('Go to settings', '404-solution'),
            ]);
        });
    }

    /**
     * Hook: enqueue_block_editor_assets - show notice in the block editor when editing the system page.
     *
     * Uses the wp.data notices store to create an info notice at the top of the block editor.
     *
     * @return void
     */
    public static function enqueueBlockEditorNotice(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        // Check if the current post is the system page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $postId = (isset($_GET['post']) && is_scalar($_GET['post'])) ? (int) $_GET['post'] : 0;
        if (!$postId || !ABJ_404_Solution_SystemPage::isSystemPage($postId)) {
            return;
        }

        $settingsUrl = self::settingsUrl();
        $message = esc_html__('This page is used by 404 Solution to display suggested pages to visitors.', '404-solution');
        $linkText = esc_html__('Learn more', '404-solution');

        wp_add_inline_script('wp-edit-post', sprintf(
            'wp.domReady(function(){' .
            'wp.data.dispatch("core/notices").createNotice("info",%s+%s,{id:"abj404-system-page-notice",isDismissible:false,' .
            'actions:[{label:%s,url:%s}]});' .
            '});',
            // Encoded through ABJ_404_Solution_JsonResponseEncoder rather than
            // wp_json_encode(): $message and $linkText are __() results, so a
            // co-plugin hooked on `gettext` can make wp_json_encode() return
            // false. sprintf('%s') renders false as '', and the emitted line
            // becomes createNotice("info",+,{...}) -- a syntax error that takes
            // the whole block editor script with it.
            ABJ_404_Solution_JsonResponseEncoder::encode($message . ' ')->json(),
            ABJ_404_Solution_JsonResponseEncoder::encode('')->json(),
            ABJ_404_Solution_JsonResponseEncoder::encode($linkText)->json(),
            ABJ_404_Solution_JsonResponseEncoder::encode($settingsUrl)->json()
        ));
    }
}
