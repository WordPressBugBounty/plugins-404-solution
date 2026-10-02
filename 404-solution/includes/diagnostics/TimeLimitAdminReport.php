<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller for the plugin's own admin surface of time-limit deaths: decides
 * when the quiet notice shows and builds the Tools card, reading the records
 * from ABJ_404_Solution_TimeLimitFatalStore and rendering them through
 * ABJ_404_Solution_TimeLimitReportView.
 *
 * The notice follows the plugin's admin-notice rules
 * (docs/ui-aesthetic/UI_AESTHETIC.md, CLAUDE.md): only on the plugin's own
 * page, only for plugin admins, never on other wp-admin screens, never by
 * email. It shows while a record exists that is newer than the last time the
 * report was opened, and not on the Tools tab, which shows the card itself.
 * Opening the card is what marks the records seen.
 */
final class ABJ_404_Solution_TimeLimitAdminReport {

    /**
     * Hook the notice on admin requests. One add_action; the notice reads
     * the store only on the plugin's own page.
     *
     * @return void
     */
    public static function register(): void {
        if (function_exists('is_admin') && is_admin()) {
            // An admin page view carries no request id, so the empty id makes this
            // bracket persist nothing; it exists because the diagnostics-wide
            // guard (DecisiveRecordManifestTest) requires every hook mutation
            // here to run inside traceBoundary().
            (new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'time_limit_admin_report'))->traceBoundary(
                ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION, 'admin_notices', static function (): void {
                    add_action('admin_notices', array(self::class, 'echoNotice'));
                });
        }
    }

    /**
     * admin_notices callback.
     *
     * @return void
     */
    public static function echoNotice(): void {
        if (!defined('ABJ404_PP') || !abj404_current_user_is_plugin_admin()) {
            return;
        }
        if (ABJ_404_Solution_RequestInputNormalizer::readText($_GET, array('name' => 'page')) !== ABJ404_PP
                || ABJ_404_Solution_RequestInputNormalizer::readText($_GET, array('name' => 'subpage')) === 'abj404_tools') {
            return;
        }
        if (!ABJ_404_Solution_TimeLimitFatalStore::hasUnseen()) {
            return;
        }
        echo ABJ_404_Solution_TimeLimitReportView::notice(
            '?page=' . ABJ404_PP . '&subpage=abj404_tools#' . ABJ_404_Solution_TimeLimitReportView::ANCHOR);
    }

    /**
     * The Tools card, or null when there is no record (the card then does
     * not exist). Building it marks the records seen.
     *
     * @param int $now epoch seconds.
     * @return array{html: string, unseen: bool}|null unseen: whether it held
     *   a record the admin had not seen (the card opens expanded then).
     */
    public static function toolsCard(int $now): ?array {
        $entries = ABJ_404_Solution_TimeLimitFatalStore::read();
        if ($entries === array()) {
            return null;
        }
        $unseen = ABJ_404_Solution_TimeLimitFatalStore::hasUnseen();
        $html = ABJ_404_Solution_TimeLimitReportView::card($entries, ABJ_404_Solution_TimeLimitFatalStore::readSlowCallbacks());
        if ($unseen) {
            ABJ_404_Solution_TimeLimitFatalStore::markSeen($now);
        }
        return array('html' => $html, 'unseen' => $unseen);
    }
}
