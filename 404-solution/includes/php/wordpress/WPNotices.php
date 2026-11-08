<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_WPNotices {

    /** Pending cycle notices survive until the next plugin-screen render. */
    const REDIRECT_CYCLE_PENDING_TRANSIENT = 'abj404_redirect_cycle_pending';
    const REDIRECT_CYCLE_DEDUP_PREFIX = 'abj404_redirect_cycle_seen_';
    const REDIRECT_CYCLE_TTL = 86400;
    
    /** @var array<ABJ_404_Solution_WPNotice> */
    private static $adminNotices = array();

    /**
     * Test seam: clear the accumulated admin-notice queue without private-field
     * reflection. Resets to the empty-array default (M105 singleton-reset seam).
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$adminNotices = array();
    }

    /** Display a message with the specified importance level.
     * @param string $noticeLevel see ABJ_404_Solution_WPNotice for notice levels.
     * @param string $message
     */
    /**
     * @param string $noticeLevel
     * @param string $message
     * @return void
     */
    public static function registerAdminMessage(string $noticeLevel, string $message): void {
        $notice = new ABJ_404_Solution_WPNotice($noticeLevel, $message);
        
        self::registerAdminNotice($notice);
    }

    /** Display a message with the specified importance level.
     * @param ABJ_404_Solution_WPNotice $adminNotice
     */
    /**
     * @param ABJ_404_Solution_WPNotice $adminNotice
     * @return void
     */
    public static function registerAdminNotice(ABJ_404_Solution_WPNotice $adminNotice): void {
        self::$adminNotices[] = $adminNotice;
        
        self::$adminNotices = array_unique(self::$adminNotices, SORT_REGULAR);
    }

    /**
     * Specific plain-text error for refused AJAX redirects; escaped at output.
     * @param string $destination Refused destination URL.
     * @return string Source and destination of the stopped redirect.
     */
    public static function redirectRefusalMessage(string $destination): string {
        $source = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI'] : '';
        return __('A redirect loop was stopped:', '404-solution') . ' '
            . $source . ' -> ' . $destination . ' -> ' . $source;
    }

    /**
     * Finish an admin refusal using its existing response shape. AJAX requires
     * a specific terminal error; page handlers continue into the destination
     * settings section with the existing queued loop notice.
     * @param string $destination Refused destination URL.
     * @return void
     */
    public static function respondToRedirectRefusal(string $destination): void {
        if (wp_doing_ajax()) {
            // A custom description is required for 508 on WordPress versions
            // whose built-in status table omits Loop Detected.
            status_header(508, 'Loop Detected');
            wp_die(esc_html(self::redirectRefusalMessage($destination)), '', array('response' => 508));
        }
        $query = parse_url($destination, PHP_URL_QUERY);
        if (is_string($query)) {
            $parts = array();
            parse_str($query, $parts);
            if (isset($parts['subpage']) && is_string($parts['subpage'])) {
                $_GET['subpage'] = $parts['subpage'];
            }
        }
    }

    /**
     * Persist one warning for a detected redirect cycle.
     *
     * The sorted path is the identity used for the 24-hour deduplication key;
     * the original ordered path is retained for the human-readable warning.
     * @param array<int, string> $path
     * @return void
     */
    public static function queueRedirectCycle(array $path): void {
        if (!function_exists('set_transient') || !function_exists('get_transient')) {
            return;
        }
        $clean = array();
        foreach ($path as $part) {
            if (is_scalar($part) && (string)$part !== '') {
                $clean[] = (string)$part;
            }
        }
        if (count($clean) < 2) {
            return;
        }

        // A walker reports a closed path (A -> B -> A). The repeated closing
        // node is presentation-only; omit it before building the cycle
        // identity so rotations of the same cycle share one 24-hour key.
        $cycle = $clean;
        if (count($cycle) > 1 && end($cycle) === $cycle[0]) {
            array_pop($cycle);
        }
        $cycle = array_values(array_unique($cycle));
        if (count($cycle) < 2) {
            return;
        }

        $pending = get_transient(self::REDIRECT_CYCLE_PENDING_TRANSIENT);
        // Keep one pending native notice. Every detection is still logged by
        // RedirectLoopGuard, while one admin render should not grow an
        // unbounded transient or emit a stack of notices.
        if (is_array($pending) && !empty($pending)) {
            return;
        }

        $sorted = $cycle;
        sort($sorted, SORT_STRING);
        $dedupKey = self::REDIRECT_CYCLE_DEDUP_PREFIX . md5(implode("\n", $sorted));
        if (get_transient($dedupKey) !== false) {
            return;
        }
        set_transient($dedupKey, 1, self::REDIRECT_CYCLE_TTL);

        set_transient(self::REDIRECT_CYCLE_PENDING_TRANSIENT, array(
            array('key' => $dedupKey, 'path' => $clean),
        ), self::REDIRECT_CYCLE_TTL);
    }

    /**
     * @return string the messages to display.
     */
    static function echoAdminNotices() {
    	$f = abj_service('functions');

    	$allHTML = '';
        if (!abj_service('admin_access_policy')->isPluginAdmin()) {
            return '';
        }

        self::drainPendingRedirectCycles();

        foreach (self::$adminNotices as $oneNotice) {
            $html = ABJ_404_Solution_FileSystemService::readFileContents(ABJ404_PATH . "/includes/html/notice.html");
            $html = $f->str_replace('{class}', 'notice is-dismissable is-dismissible ' . $oneNotice->getType(), $html);
            $msg = $oneNotice->getMessage();
            $html = $f->str_replace('{message}', esc_html(is_string($msg) ? $msg : (is_scalar($msg) ? (string)$msg : '')), $html);
            
            $allHTML .= $html;
        }
        
        echo $allHTML;
        
        return $allHTML;
    }

    /** @return void */
    private static function drainPendingRedirectCycles(): void {
        if (!function_exists('get_transient')) {
            return;
        }
        $pending = get_transient(self::REDIRECT_CYCLE_PENDING_TRANSIENT);
        if (!is_array($pending)) {
            return;
        }
        if (function_exists('delete_transient')) {
            delete_transient(self::REDIRECT_CYCLE_PENDING_TRANSIENT);
        }
        foreach ($pending as $item) {
            $path = is_array($item) && isset($item['path']) && is_array($item['path'])
                ? $item['path'] : array();
            $display = array();
            foreach ($path as $part) {
                if (is_scalar($part)) {
                    $display[] = (string)$part;
                }
            }
            if (count($display) >= 2) {
                self::registerAdminMessage(
                    ABJ_404_Solution_WPNotice::WARNING,
                    sprintf(
                        __('A redirect loop was stopped: %s. Edit these rules in Page Redirects.', '404-solution'),
                        implode(' -> ', $display)
                    )
                );
            }
        }
    }

}
