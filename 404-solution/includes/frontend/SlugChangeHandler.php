<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_SlugChangeHandler {

    /** Engine-label prefix for a redirect created when a published post is trashed. */
    const EVENT_POST_TRASHED = 'post trashed';

    /** Engine-label prefix for a redirect created just before a published post is deleted. */
    const EVENT_POST_DELETED = 'post deleted';

    /** @var self|null */
    private static $instance = null;

    /** @var mixed */
    private $contentRepository;

    /** @var mixed */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RemovedPostParentResolver|null */
    private $parentResolver = null;

    /**
     * Track post IDs already processed within the current request.
     * WordPress fires save_post multiple times per save; this prevents duplicate redirects.
     * @var array<int, bool>
     */
    private static $processedPosts = [];

    /**
     * Test seam: clear the per-request processed-post guard set without
     * private-field reflection. Resets to the empty-array default (not null)
     * so production loops over the property stay valid (M105 singleton-reset).
     *
     * @return void
     */
    public static function resetForTests() {
        self::$processedPosts = [];
    }

    /**
     * @param ABJ_404_Solution_ContentRepository|null $contentRepository Content repository
     * @param ABJ_404_Solution_RedirectsRepository|null $redirectsRepository Redirects repository
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     */
    public function __construct($contentRepository = null, $redirectsRepository = null, $logging = null) {
        $this->contentRepository = $contentRepository;
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /** @return mixed */
    private function getContentRepository() {
        return $this->contentRepository !== null ? $this->contentRepository : abj_service('content_repository');
    }

    /** @return mixed */
    private function getRedirectsRepository() {
        return $this->redirectsRepository !== null ? $this->redirectsRepository : abj_service('redirects_repository');
    }

    /** @return ABJ_404_Solution_RemovedPostParentResolver */
    private function getParentResolver() {
        if ($this->parentResolver === null) {
            $this->parentResolver = new ABJ_404_Solution_RemovedPostParentResolver();
        }
        return $this->parentResolver;
    }

    /**
     * @param int $postId
     * @return string|null
     */
    private function getPermalinkFromCache(int $postId): ?string {
        $repository = $this->getContentRepository();
        if (!is_object($repository) || !method_exists($repository, 'getPermalinkFromCache')) {
            return null;
        }
        $permalink = call_user_func(array($repository, 'getPermalinkFromCache'), $postId);
        return is_scalar($permalink) ? (string)$permalink : null;
    }

    /**
     * @param ABJ_404_Solution_RedirectSpec $spec Built from named fields, so the
     *     source path, destination and engine label cannot be transposed.
     * @return void
     */
    private function setupRedirect(ABJ_404_Solution_RedirectSpec $spec): void {
        $repository = $this->getRedirectsRepository();
        if (!is_object($repository) || !method_exists($repository, 'setupRedirect')) {
            return;
        }
        call_user_func(array($repository, 'setupRedirect'), $spec);
    }

    /**
     * @param array<string, mixed> $options Plugin options.
     * @return string The site's configured redirect code, 301 when unset.
     */
    private function defaultRedirectCode(array $options): string {
        return (isset($options['default_redirect']) && is_scalar($options['default_redirect']))
            ? (string)$options['default_redirect'] : '301';
    }

    /**
     * Get singleton instance
     * @return ABJ_404_Solution_SlugChangeHandler
     */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_SlugChangeHandler();
        }
        return self::$instance;
    }

    /**
     * Initialize the handler and register WordPress hooks
     * @return void
     */
    static function init() {
        $me = abj_service('slug_change_handler');
        add_action('save_post', array($me, 'save_postHandler'), 10, 3);
        add_action('transition_post_status', array($me, 'postStatusTransitionHandler'), 10, 3);
        add_action('before_delete_post', array($me, 'beforeDeletePostHandler'), 10, 2);
    }

    /** We'll just make sure the permalink gets updated in case it's changed.
     * @param int $post_id The post ID.
     * @param \WP_Post $post The post object.
     * @param bool $update Whether this is an existing post being updated or not.
     * @return void
     */
    function save_postHandler($post_id, $post, $update) {
        try {
            // Prevent duplicate processing within same request.
            // WordPress fires save_post multiple times per save operation;
            // the guard must live directly in the registered hook callback
            // (not delegated to a private *Impl() method) so a static audit
            // of the registered handler can verify it holds without having
            // to follow call graphs. See HookLifecycleAuditTest (Pattern 11).
            if (isset(self::$processedPosts[$post_id])) {
                $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                    ": Already processed post ID " . $post_id . " in this request (skipped).");
                return;
            }

            $this->save_postHandlerImpl($post_id, $post, $update);
        } catch (\Throwable $e) {
            // save_post fires on every post save site-wide (admin, REST,
            // import, other plugins' programmatic wp_insert_post() calls).
            // A transient failure resolving this plugin's services (the
            // VRMU incident: a class momentarily missing during a plugin
            // self-update racing a live request) must not crash the
            // save/request that triggered it.
            $this->logHandlerFailure('save_postHandler', $e);
        }
    }

    /**
     * @param int $post_id
     * @param \WP_Post $post
     * @param bool $update
     * @return void
     */
    private function save_postHandlerImpl($post_id, $post, $update): void {
        $abj404logging = $this->logger;

        // Request-level dedup is enforced by the caller, save_postHandler(),
        // before this method is ever invoked (self::$processedPosts[$post_id]
        // guard). Not re-checked here since this method is private and has
        // exactly one call site.

        // Defensive: WordPress hook may pass unexpected types at runtime.
        if (!is_object($post) || !property_exists($post, 'post_name')) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Invalid post object or missing post_name property for post ID " . $post_id . ".");
            return;
        }

        if (!$update) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Non-update skipped for post ID " . $post_id . ".");
            return;
        }

        // Check if we should create a redirect (respects per-post override from editor)
        $options = abj_service('options_repository')->getOptions();

        // Check for per-post override from Quick Edit, Classic Editor, or Gutenberg
        if (class_exists('ABJ_404_Solution_PostEditorIntegration')) {
            $shouldCreate = ABJ_404_Solution_PostEditorIntegration::shouldCreateRedirect($post_id, $options);
        } else {
            // Fallback to global setting if PostEditorIntegration not loaded
            $shouldCreate = @$options['auto_slugs'] == '1';
        }

        if (!$shouldCreate) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Auto slug redirects off " .
                "or disabled for this post (skipped) (post ID " . $post_id . ").");
            return;
        }

        // Use post_status from $post object instead of database query
        /** @var string|false $postStatus */
        $postStatus = property_exists($post, 'post_status') ? $post->post_status : get_post_status($post_id);
        if (!in_array($postStatus, array('publish', 'published'))) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Post status: " .
                $postStatus . " (skipped) (post ID " . $post_id . ").");
            return;
        }

        // get the old slug
        $oldURL = $this->getPermalinkFromCache($post_id);

        if ($oldURL === null || $oldURL === "") {
            $abj404logging->debugMessage("Couldn't find old slug for updated page. ID " .
                $post_id . ", old URL: " . $oldURL . ", post name: " . $post->post_name .
                ", update: " . $update);
            return;
        }

        $newURL = get_permalink($post);

        // Defensive: get_permalink may return WP_Error via filters in some environments.
        if (is_wp_error($newURL)) {
            $abj404logging->debugMessage("Could not get permalink for post (WP_Error). ID: " .
                $post_id . ", error: " . $newURL->get_error_message());
            return;
        }

        if ($newURL === false || $newURL === '') {
            $abj404logging->debugMessage("Could not get permalink for post (invalid return). ID: " .
                $post_id);
            return;
        }

        // Safely parse the old URL
        $oldURLParsed = parse_url($oldURL);
        if ($oldURLParsed === false) {
            $abj404logging->debugMessage("Could not parse old URL (malformed). ID: " .
                $post_id . ", URL: " . $oldURL);
            return;
        }

        if (!isset($oldURLParsed['path']) || $oldURLParsed['path'] === '') {
            $abj404logging->debugMessage("Old URL has no path component. ID: " .
                $post_id . ", URL: " . $oldURL);
            return;
        }

        $oldSlug = $oldURLParsed['path'];

        if ($oldURL == $newURL) {
            $abj404logging->debugMessage("Save post listener: Old and new URL are the same. (Ignored) " .
                "ID: " . $post_id . ", old URL: " . $oldURL . ", old slug: " . $oldSlug .
                ", new slug: " . $post->post_name . ", update: " . $update);

                return;
        }

        // Mark as processed before creating redirect to prevent duplicates
        self::$processedPosts[$post_id] = true;

        // create a redirect from the old to the new.
        $this->setupRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
            'fromURL' => $oldSlug,
            'status' => (string)ABJ404_STATUS_AUTO,
            'type' => (string)ABJ404_TYPE_POST,
            'finalDest' => (string)$post_id,
            'code' => $this->defaultRedirectCode($options),
            'disabled' => 0,
            'engine' => 'slug change',
        )));
        $abj404logging->infoMessage("Added automatic redirect after slug change from " .
            $oldURL . ' to ' . $newURL . " for post ID " . $post_id);
    }

    /**
     * Fires when a published post is moved to trash.
     * Creates a redirect from the old permalink to the post's closest parent
     * (see ABJ_404_Solution_RemovedPostParentResolver).
     *
     * @param string $new_status New post status.
     * @param string $old_status Old post status.
     * @param \WP_Post $post Post object.
     * @return void
     */
    function postStatusTransitionHandler($new_status, $old_status, $post) {
        try {
            $this->postStatusTransitionHandlerImpl($new_status, $old_status, $post);
        } catch (\Throwable $e) {
            // Same reasoning as save_postHandler(): this fires on every
            // post status change site-wide and must not crash the request
            // that triggered it.
            $this->logHandlerFailure('postStatusTransitionHandler', $e);
        }
    }

    /**
     * @param string $new_status
     * @param string $old_status
     * @param \WP_Post $post
     * @return void
     */
    private function postStatusTransitionHandlerImpl($new_status, $old_status, $post): void {
        // Only care about published posts being trashed.
        if ($old_status !== 'publish' || $new_status !== 'trash') {
            return;
        }

        if (!is_object($post) || !property_exists($post, 'ID')) {
            return;
        }

        $this->redirectRemovedPostToParent($post, (int)$post->ID, self::EVENT_POST_TRASHED);
    }

    /**
     * Fires just before a published post is permanently deleted, while its
     * terms and parent are still attached. Creates a redirect from the old
     * permalink to the post's closest parent.
     *
     * @param int $post_id Post ID.
     * @param \WP_Post $post Post object.
     * @return void
     */
    function beforeDeletePostHandler($post_id, $post) {
        try {
            $this->beforeDeletePostHandlerImpl($post_id, $post);
        } catch (\Throwable $e) {
            // Same reasoning as save_postHandler(): this fires on every
            // post deletion site-wide and must not crash the request that
            // triggered it.
            $this->logHandlerFailure('beforeDeletePostHandler', $e);
        }
    }

    /**
     * Record a failure one of this class's three registered hook callbacks
     * absorbed, at the severity the CAUSE deserves.
     *
     * The three callbacks are entry points for every post save, status change
     * and deletion on the site, so they also absorb the seconds-long window in
     * which WordPress is replacing this plugin's own files (production report
     * 266: `Class "ABJ_404_Solution_TableReadinessGate" not found`, raised from
     * save_post during a wp-cron run that had booted 4.3.2 while 4.3.3 landed
     * on disk). Reporting that as an error emails the maintainer about a
     * hosting event that fixes itself on the next request; a genuine failure
     * in the redirect-creation path still reports as an error.
     *
     * @param string $callback Name of the hook callback that caught it.
     * @param \Throwable $e
     * @return void
     */
    private function logHandlerFailure($callback, \Throwable $e) {
        $message = $callback . ' failed: ' . get_class($e) . ': ' . $e->getMessage();
        if (function_exists('abj404_logCallbackFailure')) {
            abj404_logCallbackFailure($this->logger, $message, $e);
            return;
        }
        $this->logger->errorMessage($message, $e instanceof \Exception ? $e : null);
    }

    /**
     * @param int $post_id
     * @param \WP_Post $post
     * @return void
     */
    private function beforeDeletePostHandlerImpl($post_id, $post): void {
        if (!is_object($post) || !property_exists($post, 'post_status')) {
            return;
        }

        // Only published posts (not already-trashed posts being force-deleted).
        $postStatus = (string)$post->post_status;
        if (!in_array($postStatus, array('publish', 'published'), true)) {
            return;
        }

        $this->redirectRemovedPostToParent($post, (int)$post_id, self::EVENT_POST_DELETED);
    }

    /**
     * Redirect a trashed or deleted post's old path to its closest parent
     * (category, ancestor page, post type archive), falling back to the
     * homepage only when none exists. The engine column records the event
     * and the rule that picked the target, e.g. "post trashed: parent category".
     *
     * Owns every step the trash and delete hooks share once each has decided
     * the post qualifies: the auto_trash_redirect option, the per-request
     * dedup, and deriving the redirect source from the cached permalink. The
     * source path and the log URL are derived here rather than passed in, so
     * no caller can hand them over in the wrong order.
     *
     * @param object $post The post being removed; its terms and parent must still be attached.
     * @param int $postId
     * @param string $event self::EVENT_POST_TRASHED or self::EVENT_POST_DELETED.
     * @return void
     */
    private function redirectRemovedPostToParent($post, int $postId, string $event): void {
        $options = abj_service('options_repository')->getOptions();
        if (!isset($options['auto_trash_redirect']) || $options['auto_trash_redirect'] != '1') {
            return;
        }

        // Prevent duplicate processing within same request
        if (isset(self::$processedPosts[$postId])) {
            return;
        }

        $oldURL = $this->getPermalinkFromCache($postId);
        if ($oldURL === null || $oldURL === '') {
            return;
        }

        $oldURLParsed = parse_url($oldURL);
        if ($oldURLParsed === false || !isset($oldURLParsed['path']) || $oldURLParsed['path'] === '') {
            return;
        }

        self::$processedPosts[$postId] = true;

        $target = $this->getParentResolver()->resolve($post);

        $this->setupRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
            'fromURL' => $oldURLParsed['path'],
            'status' => (string)ABJ404_STATUS_AUTO,
            'type' => (string)$target['type'],
            'finalDest' => (string)$target['dest'],
            'code' => $this->defaultRedirectCode($options),
            'disabled' => 0,
            'engine' => $event . ': ' . $target['reason'],
        )));

        $this->logger->infoMessage("Added automatic redirect to " . $target['reason'] . " (" .
            $target['dest'] . ") after " . $event . ". ID: " . $postId . ", old URL: " . $oldURL);
    }
}
