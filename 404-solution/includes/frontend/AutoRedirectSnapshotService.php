<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records automatic redirects for URLs changed by a term edit or by moving a
 * hierarchical post.  WordPress has already discarded the old URL by the
 * time its after hooks run, so this service keeps the small, request-local
 * snapshots created by the corresponding before hooks.
 *
 * The destination is always the immutable WordPress id.  The redirect lookup
 * resolves that id at request time, which keeps these redirects chain-free if
 * the content is renamed again later.
 */
class ABJ_404_Solution_AutoRedirectSnapshotService {

    /** Maximum descendant count for one parent update. */
    const DESCENDANT_CAP = 2000;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_PluginLogicOptionsResolver */
    private $optionsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ContentDescendantWalker */
    private $descendants;

    /**
     * Pending term snapshots, keyed by taxonomy:id. Each edit_terms replaces
     * the entry and edited_term consumes it, so a repeated edited_term finds
     * nothing to write while a later rename in the same request snapshots
     * afresh. There is deliberately no per-term "already handled" latch: one
     * would drop that later rename.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $termSnapshots = array();

    /**
     * Pending descendant snapshots, keyed by post ID, with the same
     * replace-on-snapshot, consume-on-write lifecycle as $termSnapshots.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private $postSnapshots = array();

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository Redirect writer/lookup.
     * @param ABJ_404_Solution_PluginLogicOptionsResolver $optionsRepository Options resolver.
     * @param ABJ_404_Solution_Logging $logger Logger.
     * @param ABJ_404_Solution_ContentRepositoryInterface|null $contentRepository Bounded content reads.
     */
    public function __construct(
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository,
        ABJ_404_Solution_PluginLogicOptionsResolver $optionsRepository,
        ABJ_404_Solution_Logging $logger,
        ?ABJ_404_Solution_ContentRepositoryInterface $contentRepository = null
    ) {
        $this->redirectsRepository = $redirectsRepository;
        $this->optionsRepository = $optionsRepository;
        $this->logger = $logger;
        $this->descendants = new ABJ_404_Solution_ContentDescendantWalker(
            $contentRepository !== null ? $contentRepository : abj_service('content_repository'),
            $logger,
            self::DESCENDANT_CAP
        );
    }

    /** Clear request state for test isolation and long-lived runners. */
    public function reset(): void {
        $this->termSnapshots = array();
        $this->postSnapshots = array();
    }

    /**
     * Snapshot a term and all of its descendants before WordPress updates it.
     *
     * @param int|string $termId
     * @param string $taxonomy
     * @return void
     */
    public function snapshotTerm($termId, string $taxonomy): void {
        $id = is_scalar($termId) ? (int)$termId : 0;
        if ($id <= 0 || $taxonomy === '' || !$this->autoSlugsEnabled()) {
            return;
        }

        $type = $this->termRedirectType($taxonomy);
        if ($type === null) {
            $this->debug('Skipping automatic term snapshot for unsupported taxonomy ' . $taxonomy . '.');
            return;
        }

        $key = $this->termKey($id, $taxonomy);
        // A replacement snapshot must not leave the previous edit replayable
        // if the new bounded read throws or returns a failure.
        unset($this->termSnapshots[$key]);
        $termIds = array_merge(array($id), $this->descendants->termDescendantIds($id, $taxonomy));

        $snapshots = array();
        foreach (array_values(array_unique($termIds)) as $termIdToSnapshot) {
            $term = function_exists('get_term') ? get_term($termIdToSnapshot, $taxonomy) : null;
            if (!is_object($term) || (function_exists('is_wp_error') && is_wp_error($term))) {
                continue;
            }
            $termUrl = function_exists('get_term_link') ? get_term_link($termIdToSnapshot) : '';
            $oldPath = is_string($termUrl) ? $this->pathFromUrl($termUrl) : null;
            if ($oldPath === null) {
                continue;
            }
            $snapshots[] = array(
                'id' => (int)$termIdToSnapshot,
                'old_path' => $oldPath,
                'type' => $type,
            );
        }
        $this->termSnapshots[$key] = $snapshots;
    }

    /**
     * Write redirects for the term and descendants after the edit completes.
     *
     * @param int|string $termId
     * @param string $taxonomy
     * @return void
     */
    public function createTermRedirects($termId, string $taxonomy): void {
        $id = is_scalar($termId) ? (int)$termId : 0;
        if ($id <= 0) {
            return;
        }
        $key = $this->termKey($id, $taxonomy);
        if (!isset($this->termSnapshots[$key])) {
            return;
        }
        $snapshots = $this->termSnapshots[$key];
        unset($this->termSnapshots[$key]);
        foreach ($snapshots as $snapshot) {
            /** @var array{id:int,old_path:string,type:int} $snapshot */
            $termId = $snapshot['id'];
            $term = function_exists('get_term') ? get_term($termId, $taxonomy) : null;
            if (!is_object($term) || (function_exists('is_wp_error') && is_wp_error($term))) {
                continue;
            }
            $termUrl = function_exists('get_term_link') ? get_term_link($termId) : '';
            $newPath = is_string($termUrl) ? $this->pathFromUrl($termUrl) : null;
            if ($newPath === null || $newPath === $snapshot['old_path']) {
                continue;
            }
            $this->writeRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
                'fromURL' => $snapshot['old_path'],
                'status' => (string)ABJ404_STATUS_AUTO,
                'type' => (string)$snapshot['type'],
                'finalDest' => (string)$snapshot['id'],
                'code' => $this->automaticRedirectCode(),
                'disabled' => 0,
                'engine' => 'term rename',
            )));
        }
    }

    /**
     * Snapshot every published descendant before a hierarchical post update.
     * Only slug and parent changes need this work; a title-only save is cheap.
     *
     * @param int|string $postId
     * @param array<string, mixed> $data Data passed to pre_post_update.
     * @param array<string, mixed> $postarr Data passed to pre_post_update.
     * @return void
     */
    public function snapshotPostDescendants($postId, array $data = array(), array $postarr = array()): void {
        $id = is_scalar($postId) ? (int)$postId : 0;
        if ($id <= 0 || !$this->shouldCreateRedirect($id)) {
            return;
        }
        $context = $this->postSnapshotContext($id, $data);
        if ($context === null || ($context['old_name'] === $context['new_name'] &&
                $context['old_parent'] === $context['new_parent'])) {
            return;
        }

        $descendants = $this->descendants->postDescendants($id, $context['post_type']);

        $snapshots = array();
        foreach ($descendants as $descendant) {
            if (!is_object($descendant)) {
                continue;
            }
            $descendantId = (int)$descendant->ID;
            if ($descendantId <= 0) {
                continue;
            }
            $postRef = ABJ_404_Solution_PostRef::fromWpPost($descendant);
            if ($postRef === null || !$postRef->isPublished()) {
                continue;
            }
            $permalink = get_permalink((int)$descendant->ID);
            $oldPath = is_string($permalink) ? $this->pathFromUrl($permalink) : null;
            if ($oldPath === null) {
                continue;
            }
            $snapshots[$descendantId] = array('id' => $descendantId, 'old_path' => $oldPath);
        }
        $this->postSnapshots[$id] = array_values($snapshots);
    }

    /**
     * @param int $id
     * @param array<string,mixed> $data
     * @return array{post_type:string,old_name:string,new_name:string,old_parent:int,new_parent:int}|null
     */
    private function postSnapshotContext(int $id, array $data): ?array {
        if (!function_exists('get_post')) {
            return null;
        }
        $oldPost = get_post($id);
        if (!is_object($oldPost)) {
            return null;
        }
        $postType = (string)$oldPost->post_type;
        if ($postType === '' && isset($data['post_type']) && is_scalar($data['post_type'])) {
            $postType = (string)$data['post_type'];
        }
        if ($postType === '' || !function_exists('is_post_type_hierarchical') || !is_post_type_hierarchical($postType)) {
            return null;
        }
        $oldName = (string)$oldPost->post_name;
        $oldParent = (int)$oldPost->post_parent;
        $newName = $oldName;
        if (array_key_exists('post_name', $data) && is_scalar($data['post_name'])) {
            $newName = (string)$data['post_name'];
        }
        $newParent = $oldParent;
        if (array_key_exists('post_parent', $data) && is_scalar($data['post_parent'])) {
            $newParent = (int)$data['post_parent'];
        }
        return array(
            'post_type' => $postType,
            'old_name' => $oldName,
            'new_name' => $newName,
            'old_parent' => $oldParent,
            'new_parent' => $newParent,
        );
    }

    /**
     * Write descendant redirects after WordPress has committed the update.
     *
     * @param int|string $postId
     * @return void
     */
    public function createPostDescendantRedirects($postId): void {
        $id = is_scalar($postId) ? (int)$postId : 0;
        if ($id <= 0 || !isset($this->postSnapshots[$id])) {
            return;
        }
        $snapshots = $this->postSnapshots[$id];
        unset($this->postSnapshots[$id]);
        foreach ($snapshots as $snapshot) {
            /** @var array{id:int,old_path:string} $snapshot */
            $descendantId = $snapshot['id'];
            if (!$this->shouldCreateRedirect($descendantId, true)) {
                continue;
            }
            $permalink = get_permalink($descendantId);
            $newPath = is_string($permalink) ? $this->pathFromUrl($permalink) : null;
            if ($newPath === null || $newPath === $snapshot['old_path']) {
                continue;
            }
            $this->writeRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
                'fromURL' => $snapshot['old_path'],
                'status' => (string)ABJ404_STATUS_AUTO,
                'type' => (string)ABJ404_TYPE_POST,
                'finalDest' => (string)$descendantId,
                'code' => $this->automaticRedirectCode(),
                'disabled' => 0,
                'engine' => 'ancestor slug change',
            )));
        }
    }

    /** @return string */
    private function termKey(int $id, string $taxonomy): string {
        return $taxonomy . ':' . $id;
    }

    /** @return int|null */
    private function termRedirectType(string $taxonomy): ?int {
        if ($taxonomy === 'post_tag') {
            return ABJ404_TYPE_TAG;
        }
        if ($taxonomy === 'category' || $taxonomy !== '') {
            // TYPE_CAT is resolved through get_term_link(), and therefore also
            // supports hierarchical custom taxonomies. TYPE_TAG cannot make
            // that promise because it resolves through get_tag_link().
            return ABJ404_TYPE_CAT;
        }
        return null;
    }

    /** @return bool */
    private function shouldCreateRedirect(int $postId, bool $forDescendant = false): bool {
        $options = $this->optionsRepository->getOptions();
        if (!is_array($options)) {
            $options = array();
        }
        if ($forDescendant) {
            return $this->shouldCreateRedirectForDescendant($postId, $options);
        }
        if (class_exists('ABJ_404_Solution_PostEditorIntegration')) {
            // The existing save_post listener owns one-shot override
            // consumption. Snapshotting must inspect the same decision before
            // WordPress mutates the post without deleting that override first.
            return (bool)ABJ_404_Solution_PostEditorIntegration::shouldCreateRedirect($postId, $options, false);
        }
        return isset($options['auto_slugs']) && $options['auto_slugs'] == '1';
    }

    /**
     * Resolve a descendant's persisted override without applying the editor
     * request controls belonging to the ancestor currently being saved.
     *
     * @param int $postId
     * @param array<string, mixed> $options
     * @return bool
     */
    private function shouldCreateRedirectForDescendant(int $postId, array $options): bool {
        $meta = get_post_meta($postId, '_abj404_create_redirect', true);
        if ($meta !== '' && $meta !== null) {
            return $meta === '1';
        }

        return isset($options['auto_slugs']) && $options['auto_slugs'] == '1';
    }

    /** @return bool */
    private function autoSlugsEnabled(): bool {
        $options = $this->optionsRepository->getOptions();
        return is_array($options) && isset($options['auto_slugs']) && $options['auto_slugs'] == '1';
    }

    private function pathFromUrl(string $url): ?string {
        if ($url === '') {
            return null;
        }
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['path']) || $parsed['path'] === '') {
            return null;
        }
        return (string)$parsed['path'];
    }

    /** @param ABJ_404_Solution_RedirectSpec $spec */
    private function writeRedirect(ABJ_404_Solution_RedirectSpec $spec): void {
        // The writer owns the atomic source admission policy. Automatic writes
        // refuse an active manual source while captured and other rows do not
        // suppress the new immutable destination id.
        $insertId = $this->redirectsRepository->setupRedirect($spec);
        if ((int)$insertId > 0) {
            $this->info('Added automatic redirect after ' . (string)$spec->getEngine() .
                ' from ' . $spec->getFromURL() . ' to ID ' . $spec->getFinalDest() . '.');
        }
    }

    /** @return string */
    private function automaticRedirectCode(): string {
        $options = $this->optionsRepository->getOptions();
        return is_array($options) && isset($options['default_redirect']) && is_scalar($options['default_redirect'])
            ? (string)$options['default_redirect'] : '301';
    }

    private function debug(string $message): void {
        $this->logger->debugMessage(__CLASS__ . ': ' . $message);
    }

    private function info(string $message): void {
        $this->logger->infoMessage(__CLASS__ . ': ' . $message);
    }
}
