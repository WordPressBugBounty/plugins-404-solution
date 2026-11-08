<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/SiteResourceLock.php';
require_once __DIR__ . '/SettingsOptionWriter.php';
require_once __DIR__ . '/../services/QueuedCronEventAdminContinuation.php';

/** Repairs positively identified legacy generated posts without changing user destinations. */
class ABJ_404_Solution_LegacySuggestionPageRecovery {
    /** Post meta key used to identify the system page. */
    const META_KEY = '_abj404_system_page';

    /** Durable ID keyset, scoped to the current blog and never autoloaded. */
    public const CURSOR_OPTION = 'abj404_legacy_page_recovery_cursor';
    /** Bound both materialized posts and metadata writes per request. */
    private const BATCH_SIZE = 25;

    /** SEO-plugin noindex metadata shared by created and recovered pages. */
    public const NOINDEX_META = array(
        '_yoast_wpseo_meta-robots-noindex' => '1',
        'rank_math_robots' => array('noindex'),
    );

    /**
     * Complete declined metadata writes once, then verify the stored values.
     * @param int $pageId
     * @param array<string, mixed> $metadata
     * @return bool Whether every requested value is durably present.
     */
    public function persistPageMetadata(int $pageId, array $metadata): bool {
        foreach ($metadata as $key => $value) {
            if (get_post_meta($pageId, $key, true) === $value) { continue; }
            update_post_meta($pageId, $key, $value);
            if (get_post_meta($pageId, $key, true) !== $value) {
                abj404_logPhpFallback('system-page-metadata', 'Metadata write refused for page ' . $pageId . ', key ' . $key);
                return false;
            }
        }
        return true;
    }

    /**
     * Recover untouched pages left by the pre-tagging initialization failure.
     * Called on activation and version upgrade. Preserve edited/user pages and
     * the saved destination; surplus generated copies become reversible drafts.
     *
     * @param int $destinationId Legacy caller hint; ignored in favor of the
     *   exact settings row read after the initialization lock.
     * @return void
     */
    public function recoverLegacyPages(int $destinationId): void {
        $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
        if (!$lock->acquire()) {
            // INSERT IGNORE preserves a concurrent worker's existing cursor.
            // Retain a retry marker even when this upgrade cannot enter yet.
            (new ABJ_404_Solution_ExclusiveOptionRow())->claim(array(
                'optionName' => self::CURSOR_OPTION, 'value' => '0'));
            abj_cron_scheduler()->scheduleSingleIfMissing(ABJ_404_Solution_CronScheduler::HOOK_LEGACY_PAGE_RECOVERY, 30);
            return;
        }
        try {
            $cursor = $this->readCursor();
            if ($cursor === null || !$this->persistCursor($cursor)) { return; }
            // Arm continuation before work: process death cannot strand a batch.
            if (!abj_cron_scheduler()->scheduleSingleIfMissing(ABJ_404_Solution_CronScheduler::HOOK_LEGACY_PAGE_RECOVERY, 30)) { return; }
            // A caller's pre-lock snapshot cannot arbitrate against page creators.
            // Read the exact settings row after acquiring this lock; the raw
            // bytes are also carried into every guarded post/meta mutation.
            $savedSettings = $this->savedSettingsReference();
            if ($savedSettings === null) { return; }
            $destinationId = $savedSettings['destinationId'];
            $canonicalId = ABJ_404_Solution_SystemPage::getInstance()->getSystemPageId();
            $mutation = new ABJ_404_Solution_LegacyPageRecoveryMutation(
                abj_service('data_access')->getDbCore(), $lock, $savedSettings['raw']
            );
            $notifications = new ABJ_404_Solution_WordPressPostMutationNotifications();
            $ids = $this->batchIds($cursor);
            if ($ids === null) { return; }
            if (empty($ids)) { $this->finish(); return; }
            $pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish',
                'post__in' => $ids, 'posts_per_page' => self::BATCH_SIZE, 'no_found_rows' => true,
                'orderby' => 'ID', 'order' => 'ASC', 'cache_results' => false));
            if (!is_array($pages)) { return; }
            $byId = array();
            foreach ($pages as $page) {
                if (!($page instanceof WP_Post) || !in_array((int)$page->ID, $ids, true)) { return; }
                $byId[(int)$page->ID] = $page;
            }
            $advance = true;
            foreach ($ids as $id) {
                // A deleted or unpublished page no longer needs recovery.
                $completed = !isset($byId[$id]) || $this->recoverPage(
                    $byId[$id], $destinationId, $canonicalId, $mutation, $notifications
                );
                $advance = $advance && $completed;
                if ($advance && !$this->persistCursor($id)) { return; }
            }
            if ($advance && count($ids) < self::BATCH_SIZE) { $this->finish(); }
        } finally {
            $lock->release();
        }
    }

    /** @return bool Whether daily maintenance should retry a stranded continuation. */
    public function hasPendingBatch(): bool {
        return (new ABJ_404_Solution_ExclusiveOptionRow())->valueOf(self::CURSOR_OPTION) !== '';
    }

    /**
     * One bounded continuation batch with the site's current saved
     * destination: the step the cron listener, daily maintenance and the
     * admin-view continuation all run.
     *
     * @return void
     */
    public function continuePendingBatch(): void {
        if (!$this->hasPendingBatch()) { return; }
        // recoverLegacyPages() re-reads the exact saved row after taking the
        // shared lock. Never let this pre-lock continuation snapshot choose a
        // destination for a later mutation.
        $this->recoverLegacyPages(0);
    }

    /**
     * Admit the queued continuation on an authorized ordinary admin page
     * render of a site whose WP-Cron is proved stalled. Each batch still takes
     * the initialization lock itself, as the cron listener's does.
     *
     * @return bool Whether a post-response slice was registered.
     */
    public function armAdminContinuation(): bool {
        return (new ABJ_404_Solution_QueuedCronEventAdminContinuation(array(
            'hook' => ABJ_404_Solution_CronScheduler::HOOK_LEGACY_PAGE_RECOVERY,
            'lockKey' => 'legacy_page_recovery',
            'label' => 'legacy page recovery',
            'sync' => abj_service('sync_utils'),
            'clock' => abj_clock(),
            'cron' => abj_cron_scheduler(),
            'logger' => abj_service('logging'),
            'pending' => function (): bool { return $this->hasPendingBatch(); },
            'slice' => function (float $deadline): array { return $this->continueUntil($deadline); },
            'describe' => function (): array {
                return array('cursor' => $this->rawCursor(),
                    'dest404page' => abj_service('options_repository')->getRawSettingValue('dest404page', '0|0'));
            },
        )))->arm();
    }

    /**
     * Batches until the deadline. Progress is the durable cursor moving, the
     * only state a batch commits.
     *
     * @return array{complete:bool,progressed:bool}
     */
    private function continueUntil(float $deadline): array {
        $progressed = false;
        do {
            $before = $this->rawCursor();
            $this->continuePendingBatch();
            if (!$this->hasPendingBatch()) { return array('complete' => true, 'progressed' => true); }
            if ($this->rawCursor() === $before) { return array('complete' => false, 'progressed' => $progressed); }
            $progressed = true;
        } while (abj_clock()->nowFloat() < $deadline);
        return array('complete' => false, 'progressed' => true);
    }

    private function rawCursor(): string {
        return (new ABJ_404_Solution_ExclusiveOptionRow())->valueOf(self::CURSOR_OPTION);
    }

    /**
     * @return array{destinationId:int,raw:string}|null
     */
    private function savedSettingsReference(): ?array {
        $snapshot = ABJ_404_Solution_SettingsOptionWriter::readSnapshot();
        if ($snapshot['status'] !== 'found') {
            abj404_logPhpFallback('system-page-recovery',
                'Saved recovery settings row is absent or unreadable; preserving legacy pages.');
            return null;
        }
        $decoded = @unserialize($snapshot['value'], array('allowed_classes' => false));
        $destination = is_array($decoded) && array_key_exists('dest404page', $decoded)
            ? $decoded['dest404page'] : null;
        if (!is_string($destination) || !preg_match('/^([0-9]+)[|]([015])$/D', $destination, $match)) {
            abj404_logPhpFallback('system-page-recovery', 'Saved recovery destination is unreadable: '
                . (is_scalar($destination) ? (string)$destination : gettype($destination))
                . '; preserving legacy pages.');
            return null;
        }
        $destinationId = ABJ_404_Solution_ExactInteger::read($match[1], 0);
        if ($destinationId === null) {
            abj404_logPhpFallback('system-page-recovery', 'Saved recovery destination ID is out of range: '
                . $match[1] . '; preserving legacy pages.');
            return null;
        }
        return array('destinationId' => $match[2] === '1' ? $destinationId : 0,
            'raw' => $snapshot['value']);
    }

    /** @return int|null Null refuses malformed or unreadable persisted state. */
    private function readCursor(): ?int {
        $raw = $this->rawCursor();
        if ($raw === '') { return 0; }
        $cursor = ABJ_404_Solution_ExactInteger::read($raw, 0);
        if ($cursor === null) {
            abj_service('logging')->warn('Legacy suggestion page recovery cursor is malformed: ' . $raw);
        }
        return $cursor;
    }

    /** @param int $cursor @return bool Verify the durable checkpoint, not the changed-byte flag. */
    private function persistCursor(int $cursor): bool {
        update_option(self::CURSOR_OPTION, (string)$cursor, false);
        if ((new ABJ_404_Solution_ExclusiveOptionRow())->valueOf(self::CURSOR_OPTION) === (string)$cursor) { return true; }
        abj404_logPhpFallback('system-page-recovery', 'Could not persist recovery cursor ' . $cursor);
        return false;
    }

    /** @return void Completed batches remove their marker and outstanding continuation. */
    private function finish(): void {
        delete_option(self::CURSOR_OPTION);
        if ($this->hasPendingBatch()) {
            abj404_logPhpFallback('system-page-recovery', 'Could not remove completed recovery cursor; retaining continuation.');
            return;
        }
        abj_cron_scheduler()->clearHook(ABJ_404_Solution_CronScheduler::HOOK_LEGACY_PAGE_RECOVERY);
    }

    /**
     * Read a bounded, stable ID keyset.
     * @param int $cursor
     * @return array<int, int>|null
     */
    private function batchIds(int $cursor): ?array {
        $core = abj_service('data_access')->getDbCore();
        // allow-unbounded-select: the statement ends with "LIMIT self::BATCH_SIZE" (the LIMIT lands in a separate concatenated string than SELECT/FROM)
        $result = $core->queryAndGetResults("SELECT ID FROM {wp_posts} WHERE ID > %d"
            . " AND post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s"
            . ' ORDER BY ID ASC LIMIT ' . self::BATCH_SIZE,
            array('query_params' => array($cursor, '%[abj404\\_solution\\_page\\_suggestions]%')));
        $failure = $this->batchQueryFailure($result);
        if ($failure !== '') {
            abj404_logPhpFallback('system-page-recovery', $failure);
            return null;
        }
        if (!is_array($result['rows'] ?? null)) {
            abj404_logPhpFallback('system-page-recovery', 'Legacy recovery batch query returned malformed rows.');
            return null;
        }
        $ids = array();
        foreach ($result['rows'] as $row) {
            $id = is_array($row) ? ABJ_404_Solution_ExactInteger::read($row['ID'] ?? null, $cursor + 1) : null;
            if ($id === null) { return null; }
            $ids[] = $id;
        }
        return $ids;
    }

    /** @param mixed $result @return string Empty means the batch read succeeded. */
    private function batchQueryFailure($result): string {
        if (!is_array($result)) { return 'Legacy recovery batch query returned an invalid result envelope.'; }
        $error = $result['last_error'] ?? '';
        if (!is_string($error)) { return 'Legacy recovery batch query returned malformed error metadata.'; }
        if ($error !== '') { return 'Legacy recovery batch query failed: ' . $error; }
        if (array_key_exists('timed_out', $result) && !is_bool($result['timed_out'])) {
            return 'Legacy recovery batch query returned malformed timeout metadata.';
        }
        if (($result['timed_out'] ?? false) === true) { return 'Legacy recovery batch query timed out.'; }
        if (!array_key_exists('query_succeeded', $result) || !is_bool($result['query_succeeded'])) {
            return 'Legacy recovery batch query returned unknown query status.';
        }
        if ($result['query_succeeded'] !== true) {
            return 'Legacy recovery batch query failed without a database error.';
        }
        if (!array_key_exists('rows', $result) || !is_array($result['rows'])) {
            return 'Legacy recovery batch query returned malformed rows.';
        }
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) { return 'Legacy recovery batch query returned malformed row data.'; }
        }
        return '';
    }

    /** @param WP_Post $page @param int $destinationId @param int $canonicalId @return bool */
    private function recoverPage(WP_Post $page, int $destinationId, int &$canonicalId,
        ABJ_404_Solution_LegacyPageRecoveryMutation $mutation,
        ABJ_404_Solution_WordPressPostMutationNotifications $notifications): bool {
        if (!$this->isUntouchedLegacyPage($page) || (int)$page->ID === $destinationId) { return true; }
        $id = (int)$page->ID;
        $metadata = self::NOINDEX_META;
        if ($canonicalId === 0) { $metadata[self::META_KEY] = '1'; }
        try {
            $metadataResult = $this->persistRecoveryMetadata($page, $metadata, $mutation, $notifications);
        } catch (ABJ_404_Solution_DatabaseMutationFailedException $failure) {
            abj404_logPhpFallback('system-page-recovery', $failure->getMessage());
            return false;
        }
        if ($metadataResult['kind'] === ABJ_404_Solution_LegacyPageRecoveryMutation::FAILED) { return false; }
        if ($metadataResult['kind'] === ABJ_404_Solution_LegacyPageRecoveryMutation::CHANGED) { return true; }
        if ($canonicalId === 0) {
            if ($metadataResult['canonical_kind'] !== ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED) {
                return true;
            }
            $canonicalId = $id;
            return true;
        }
        if ($id === $canonicalId) { return true; }
        try {
            $draftResult = $mutation->draftIfUnchanged(array(
                'snapshot' => $page, 'systemTagKey' => self::META_KEY,
            ));
        } catch (ABJ_404_Solution_DatabaseMutationFailedException $failure) {
            abj404_logPhpFallback('system-page-recovery', $failure->getMessage());
            return false;
        }
        if ($draftResult['kind'] === ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED) {
            $notifications->afterDraft($page);
        }
        return $draftResult['kind'] !== ABJ_404_Solution_LegacyPageRecoveryMutation::FAILED;
    }

    /**
     * Apply metadata in canonical order, keeping WordPress hooks immediately
     * before each guarded statement and after only a genuine SQL write.
     *
     * @param WP_Post $page
     * @param array<string,mixed> $metadata
     * @return array{kind:string,canonical_kind:string}
     */
    private function persistRecoveryMetadata(WP_Post $page, array $metadata,
        ABJ_404_Solution_LegacyPageRecoveryMutation $mutation,
        ABJ_404_Solution_WordPressPostMutationNotifications $notifications): array {
        $canonicalKind = ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED;
        foreach ($metadata as $key => $value) {
            $key = (string)$key;
            $prepared = $notifications->prepareValue((int)$page->ID, $key, $value);
            $value = $prepared['raw'];
            $stored = $prepared['stored'];
            $old = $mutation->readMetadata($page, $key);
            if ($old !== null && $old['meta_value'] === $stored) {
                continue;
            }
            if ($old === null) {
                $before = $notifications->beforeAdd(array(
                    'postId' => (int)$page->ID, 'key' => $key, 'rawValue' => $value,
                ));
                if (!$before['allowed']) {
                    return $this->metadataRefused($key, $before['reason']);
                }
                $result = $mutation->insertMetadata(array(
                    'snapshot' => $page, 'metaKey' => $key, 'storedValue' => $stored,
                    'systemTagKey' => self::META_KEY,
                ));
                if ($result['kind'] === ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED) {
                    $notifications->afterAdd(array(
                        'metaId' => (int)($result['meta_id'] ?? 0), 'postId' => (int)$page->ID,
                        'key' => $key, 'rawValue' => $value,
                    ));
                }
            } else {
                $before = $notifications->beforeUpdate(array(
                    'metaId' => $old['meta_id'], 'postId' => (int)$page->ID, 'key' => $key,
                    'rawValue' => $value, 'serializedValue' => $stored,
                ));
                if (!$before['allowed']) {
                    return $this->metadataRefused($key, $before['reason']);
                }
                $result = $mutation->updateMetadata(array(
                    'snapshot' => $page, 'metaKey' => $key, 'storedValue' => $stored,
                    'metaId' => $old['meta_id'], 'oldValue' => $old['meta_value'],
                    'systemTagKey' => self::META_KEY,
                ));
                if ($result['kind'] === ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED) {
                    $notifications->afterUpdate(array(
                        'metaId' => $old['meta_id'], 'postId' => (int)$page->ID, 'key' => $key,
                        'rawValue' => $value, 'serializedValue' => $stored,
                    ));
                }
            }
            if ($key === self::META_KEY) {
                $canonicalKind = $result['kind'];
            }
            if ($result['kind'] !== ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED) {
                return array('kind' => $result['kind'], 'canonical_kind' => $canonicalKind);
            }
        }
        return array('kind' => ABJ_404_Solution_LegacyPageRecoveryMutation::APPLIED,
            'canonical_kind' => $canonicalKind);
    }

    /** @return array{kind:string,canonical_kind:string} */
    private function metadataRefused(string $key, string $reason): array {
        abj404_logPhpFallback('system-page-recovery', 'Metadata filter refused key ' . $key . ': ' . $reason);
        return array('kind' => ABJ_404_Solution_LegacyPageRecoveryMutation::FAILED,
            'canonical_kind' => ABJ_404_Solution_LegacyPageRecoveryMutation::FAILED);
    }

    /** @param WP_Post $page @return bool Positive fingerprint of an unedited generated page. */
    private function isUntouchedLegacyPage(WP_Post $page): bool {
        if ($page->ID <= 0
            || $page->post_content !== '[abj404_solution_page_suggestions]'
            || ($page->post_title !== __('Page Not Found', '404-solution') && $page->post_title !== 'Page Not Found')
            || $page->comment_status !== 'closed' || $page->ping_status !== 'closed'
            || $page->post_parent != 0 || $page->post_password !== '' || $page->comment_count != 0
            || $page->post_date !== $page->post_modified) { return false; }
        $slug = sanitize_title($page->post_title);
        return preg_match('/^' . preg_quote($slug, '/') . '(?:-[0-9]+)?$/D', $page->post_name) === 1
            && !metadata_exists('post', $page->ID, self::META_KEY);
    }

}
