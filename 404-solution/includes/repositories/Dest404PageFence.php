<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/DatabaseDriverCall.php';
require_once __DIR__ . '/SettingsWriteTarget.php';

/**
 * Publication rule for a custom 404 page the settings write newly selects.
 *
 * A page/post destination (dest404page = "<id>|<post type>") the write newly
 * selects must be published right now, both when checked up front and as a
 * predicate fenced into the SQL itself so a page unpublished in between cannot
 * be saved. The destination the replaced row already names is the site's own
 * state, not a selection: a custom 404 page drafted, trashed or deleted later
 * is only warned about on the plugin screen, and must not make the writer
 * refuse every other setting and the version stamp.
 */
final class ABJ_404_Solution_Dest404PageFence {

    /** @var ABJ_404_Solution_SettingsWriteTarget|null The write's target, or null to use the global wpdb. */
    private $target;

    /**
     * @param ABJ_404_Solution_SettingsWriteTarget|null $target Where the write is aimed; without one
     *        (or with an unbound one) the current global wpdb is read.
     */
    public function __construct(?ABJ_404_Solution_SettingsWriteTarget $target = null) {
        $this->target = $target;
    }

    /**
     * Whether the destination this write selects may be saved: a page/post
     * destination it newly selects must be published right now.
     *
     * @param array<string,mixed> $options
     * @param array{status:string,value:string} $expected The raw row being replaced.
     */
    public function isFreshlyPublishable(array $options, array $expected): bool {
        $destination = $options['dest404page'] ?? '';
        if (!is_string($destination)) {
            return false;
        }
        $postId = self::newlySelectedPostId($destination, $expected);
        if ($postId === 0) {
            return true;
        }
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return false;
        }
        $table = $this->postsTable($wpdb);
        if ($table === '') {
            return false;
        }
        $prepare = array($wpdb, 'prepare');
        if (!is_callable($prepare)) {
            return false;
        }
        $query = call_user_func($prepare,
            'SELECT post_status FROM ' . $table . ' WHERE ID = %d LIMIT 1', $postId);
        if (!is_string($query) || $query === '') {
            return false;
        }
        $status = ABJ_404_Solution_DatabaseDriverCall::getCheckedVar($wpdb, $query);
        if (!empty($wpdb->last_error)) {
            return false;
        }
        return in_array($status, array('publish', 'published'), true);
    }

    /**
     * The SQL predicate that keeps a newly selected page/post destination
     * published at the moment the write lands, or '' when the value selects
     * no new page/post destination.
     *
     * @param mixed $value The final settings value.
     * @param array{status:string,value:string} $expected The raw row being replaced.
     */
    public function fencePredicate($value, array $expected): string {
        if (!is_array($value) || !isset($value['dest404page']) || !is_string($value['dest404page'])) {
            return '';
        }
        $postId = self::newlySelectedPostId($value['dest404page'], $expected);
        if ($postId === 0) {
            // An unchanged destination needs no liveness fence: the UPDATE
            // already requires the stored row to equal the expected bytes.
            return '';
        }
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return ' AND 1 = 0';
        }
        $table = $this->postsTable($wpdb);
        if ($table === '') {
            return ' AND 1 = 0';
        }
        return ' AND EXISTS (SELECT 1 FROM ' . $table . ' WHERE ID = ' . $postId
            . " AND post_status IN ('publish', 'published'))";
    }

    /**
     * The post id a destination newly selects, or 0 when it names no post row
     * or is the destination the replaced row already holds. Categories, tags,
     * home, external destinations and the theme 404 do not name a post row;
     * only the page/post destination needs the publication check, which
     * preserves existing custom policies.
     *
     * @param array{status:string,value:string} $expected
     */
    private static function newlySelectedPostId(string $destination, array $expected): int {
        if (!preg_match('/^([0-9]+)\\|([0-9]+)$/D', $destination, $match)) {
            return 0;
        }
        if ((int)$match[2] !== (int)ABJ404_TYPE_POST || (int)$match[1] <= 0
            || $destination === self::savedDestination($expected)) {
            return 0;
        }
        return (int)$match[1];
    }

    /**
     * The dest404page of the raw settings row a write replaces, or null when
     * that row is absent or unreadable (every destination is then a selection).
     *
     * @param array{status:string,value:string} $expected
     */
    private static function savedDestination(array $expected): ?string {
        if (($expected['status'] ?? '') !== 'found' || !is_string($expected['value'] ?? null)) {
            return null;
        }
        $saved = @unserialize($expected['value'], array('allowed_classes' => false));
        return is_array($saved) && isset($saved['dest404page']) && is_string($saved['dest404page'])
            ? $saved['dest404page'] : null;
    }

    /** @return object|null The target's wpdb, else the global one, else null. */
    private function wpdb(): ?object {
        $wpdb = $this->target !== null ? $this->target->wpdb() : null;
        $wpdb = $wpdb ?? ($GLOBALS['wpdb'] ?? null);
        return is_object($wpdb) ? $wpdb : null;
    }

    /** @param object $wpdb The wpdb returned by wpdb(). */
    private function postsTable($wpdb): string {
        $target = $this->target;
        return ABJ_404_Solution_SettingsWriteTarget::safeTable(
            $target !== null && $target->wpdb() !== null ? $target->postsTable() : ($wpdb->posts ?? '')
        );
    }
}
