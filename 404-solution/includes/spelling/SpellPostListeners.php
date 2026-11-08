<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress post save/delete listeners and permalink/N-gram cache
 * invalidation for the spell-checking subsystem.
 *
 * Extracted from SpellCheckerTrait_PostListeners as a standalone class
 * with explicit dependency injection.
 */
class ABJ_404_Solution_SpellPostListeners {

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_PermalinkCache */
	private $permalinkCache;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/** @var ABJ_404_Solution_PublishedPostsProvider|null */
	private ?ABJ_404_Solution_PublishedPostsProvider $publishedPostsProvider = null;

	/**
	 * Saves already handled in this request, keyed by
	 * ABJ_404_Solution_PostRef::saveKey().
	 *
	 * @var array<string, bool>
	 */
	private static $processedSavePostIds = [];

	/** @var array<int, array<int, \WP_Post|null>> Post context retained until core completes deletion. */
	private static array $postsBeingDeleted = array();

	/**
	 * Reset the per-request save dedup and the deletion context. Test seam:
	 * both live for one request in production (the SAPI discards them), so a
	 * process replaying several requests must not let one request's handled
	 * post IDs suppress the next request's save_post.
	 *
	 * @return void
	 */
	public static function resetForTests(): void {
		self::$processedSavePostIds = array();
		self::$postsBeingDeleted = array();
	}

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param ABJ_404_Solution_PermalinkCache $permalinkCache
	 * @param ABJ_404_Solution_NGramFilter $ngramFilter
	 */
	public function __construct($functions, $logger, $contentRepository, $permalinkCache, $ngramFilter) {
		$this->f = $functions;
		$this->logger = $logger;
		$this->contentRepository = $contentRepository;
		$this->permalinkCache = $permalinkCache;
		$this->ngramFilter = $ngramFilter;
	}

	/** @return ABJ_404_Solution_PublishedPostsProvider|null */
	public function getPublishedPostsProvider(): ?ABJ_404_Solution_PublishedPostsProvider {
		return $this->publishedPostsProvider;
	}

	/**
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 * @param bool|null $update
	 */
	function save_postListener($post_id, $post = null, $update = null): void {
		if ($post == null) {
			$post = get_post($post_id);
		}
		// Skip only WordPress's repeated fires of one save; a later save in
		// this request (an importer publishing the draft it just inserted, a
		// slug rewrite) is handled.
		$saveKey = ABJ_404_Solution_PostRef::saveKey($post_id, $post);
		if (isset(self::$processedSavePostIds[$saveKey])) {
			return;
		}
		self::$processedSavePostIds[$saveKey] = true;
		if ($update == null) {
			$update = true;
		}

		$this->savePostHandler($post_id, $post, $update, 'save');
    }

	/**
	 * Capture context without rebuilding while the published row still exists.
	 * WordPress before 5.5 passes only the ID to both deletion hooks.
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 */
	public static function captureDeletingPost($post_id, $post = null): void {
		self::$postsBeingDeleted[get_current_blog_id()][$post_id] = $post ?? get_post($post_id);
	}

	/**
	 * Consume context even when settings initialization defers invalidation.
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 * @return \WP_Post|null
	 */
	public static function consumeDeletedPostContext($post_id, $post = null) {
		$blogId = get_current_blog_id();
		$post = $post ?? (self::$postsBeingDeleted[$blogId][$post_id] ?? null);
		unset(self::$postsBeingDeleted[$blogId][$post_id]);
		return $post;
	}

	/**
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 */
    function delete_postListener($post_id, $post = null): void {
    	if ($post == null) {
    		$post = get_post($post_id);
    	}

        $this->savePostHandler($post_id, $post, true, 'delete');
    }

	/**
	 * created_term / edited_term / delete_term listener. A category or tag
	 * create/rename/delete changes what a spelling lookup can match
	 * (findMatchingPosts matches on categories and tags too), so the spelling
	 * cache -- including memoized no-match results -- must be invalidated.
	 * Restricted to the built-in category/post_tag taxonomies so unrelated
	 * taxonomy churn (menus, link categories, plugin taxonomies) does not keep
	 * truncating the cache.
	 *
	 * @param int $term_id
	 * @param int $tt_id
	 * @param string $taxonomy
	 */
	function term_changedListener(int $term_id, int $tt_id = 0, string $taxonomy = ''): void {
		if ($taxonomy !== '' && !in_array($taxonomy, array('category', 'post_tag'), true)) {
			return;
		}
		$this->contentRepository->deleteSpellingCache();
		$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
			": Spelling cache invalidated by term change. term_id: " . $term_id .
			", taxonomy: " . $taxonomy);
	}

	/**
	 * @param int $post_id
	 * @param \WP_Post|mixed $post
	 * @param bool $update
	 * @param string $saveOrDelete
	 */
	function savePostHandler($post_id, $post, $update, $saveOrDelete): void {
		$options = abj_service('options_repository')->getOptions();
		if (!is_object($post) || !isset($post->post_type) || !isset($post->post_status) || !isset($post->post_name)) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Invalid post object for ID: " . $post_id . " (skipped).");
			return;
		}
		$postType = $post->post_type;

		$recognizedPostTypesRaw = isset($options['recognized_post_types']) ? $options['recognized_post_types'] : '';
		$acceptedPostTypes = $this->f->explodeNewlineOrComma(is_string($recognizedPostTypesRaw) ? $recognizedPostTypesRaw : '');

		$deleteSpellingCache = false;
		$deleteFromPermalinkCache = false;
		$invalidateNGramCache = false;
		$reason = '';

		/** @var array<string, mixed> $cacheRow */
		$cacheRow = $this->contentRepository->getPermalinkEtcFromCache($post_id) ?: array();
		$cacheUrlRaw = (array_key_exists('url', $cacheRow)) ? $cacheRow['url'] : null;
		$oldSlug = (is_string($cacheUrlRaw)) ?
			rtrim(ltrim($cacheUrlRaw, '/'), '/') : '(not found)';
		$newSlug = $post->post_name;
		$matches = array();
		$metaRowRaw = array_key_exists('meta', $cacheRow) ? $cacheRow['meta'] : '';
		$metaRow = is_string($metaRowRaw) ? $metaRowRaw : '';
		preg_match('/s:(\\w+?),/', $metaRow, $matches);
		$oldStatus = count($matches) > 1 ? $matches[1] : '(not found)';
		preg_match('/t:(\\w+?),/', $metaRow, $matches);
		$oldPostType = count($matches) > 1 ? $matches[1] : '(not found)';
		if ($update && $saveOrDelete == 'save' &&
				($oldSlug != $newSlug ||
				$oldStatus != $post->post_status ||
				$oldPostType != $post->post_type)
			) {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$invalidateNGramCache = true;
			$reason = 'change. slug (' . $oldSlug . '(to)' . $newSlug . '), status (' .
				$oldStatus . '(to)' . $post->post_status . '), type (' . $oldPostType .
				'(to)' . $post->post_type . ')';
		}

		if (!in_array($oldPostType, $acceptedPostTypes) &&
			!in_array($post->post_type, $acceptedPostTypes)) {

			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post types). " .
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", types: " .
				$oldPostType . "/" . $post->post_type . ", agent: " .
					$httpUserAgent);
			return;
		}

		$interestingStatuses = array('publish', 'published');
		if (!in_array($oldStatus, $interestingStatuses) &&
			!in_array($post->post_status, $interestingStatuses)) {

			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post statuses). " .
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", statuses: " .
				$oldStatus . "/" . $post->post_status . ", agent: " .
				$httpUserAgent);
			return;
		}

		if (!$update && $saveOrDelete == 'save') {
			$deleteSpellingCache = true;
			$deleteFromPermalinkCache = false;
			$invalidateNGramCache = false;
			$reason = 'new page';
		}

		if ($saveOrDelete == 'delete') {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$invalidateNGramCache = true;
			$reason = 'deleted page';
		}

		if ($deleteFromPermalinkCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Delete from permalink cache: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$this->contentRepository->removeFromPermalinkCache($post_id);
				$this->permalinkCache->updatePermalinkCache(1);
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating permalink cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($invalidateNGramCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Invalidate N-gram cache entry: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$result = $this->ngramFilter->invalidatePage($post_id);
				if (!$result) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to invalidate N-gram cache for post ID " . $post_id .
						". The cache may be out of sync until next daily maintenance.");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while invalidating N-gram cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($deleteSpellingCache) {
			// TODO only delete the items from the cache that refer
			// to the post ID that was deleted?
			try {
				$this->contentRepository->deleteSpellingCache();
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while deleting spelling cache: " . $e->getMessage());
			}

			// The line itself is not gated on isDebug(): debugMessage() already
			// writes at once in debug mode and otherwise buffers it for the next
			// error, and "the spelling cache was emptied by this post change" is
			// what explains a suggestion list that suddenly changed. Only the
			// visitor-side user agent stays debug-only, so the held line carries
			// no client identity.
			$agentNote = '';
			if ($this->logger->isDebug() && array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$agentNote = ", agent: " . $_SERVER['HTTP_USER_AGENT'];
			}

			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Spelling cache deleted (post change). Action: " . $saveOrDelete .
				", ID: " . $post_id . ", type: " . $postType . ", reason: " .
				$reason . $agentNote);
		}

		if ($saveOrDelete == 'save' && in_array($post->post_status, array('publish', 'published'))) {
			try {
				$this->permalinkCache->updatePermalinkCache(1);

				$stats = $this->ngramFilter->updateNGramsForPages(array($post_id));

				if ($stats['success'] > 0) {
					$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Incrementally updated N-grams for post ID: " . $post_id .
						" (processed: {$stats['processed']}, success: {$stats['success']}, failed: {$stats['failed']})");
				} else if ($stats['failed'] > 0) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to update N-grams for post ID: " . $post_id .
						" (stats: " . json_encode($stats) . ")");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating N-grams for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}
	}

	/**
	 * @param string $var1
	 * @param mixed $newStructure
	 */
	function permalinkStructureChanged($var1, $newStructure): void {
		if ($var1 != 'permalink_structure') {
			return;
		}

		$structure = empty($newStructure) ? '(empty)' : $newStructure;
		$this->contentRepository->deleteSpellingCache();
		$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Spelling cache deleted because the permalink structure changed " . "to " . $structure);
	}

	function initializePublishedPostsProvider(): void {
		if ($this->publishedPostsProvider == null) {
			// Build a FRESH provider from the content repository this collaborator
			// was constructed with. Two reasons it is not resolved from the
			// container's shared 'published_posts_provider' service:
			//   1. Wrong repository. The shared provider is built from the
			//      container's 'content_repository'; a caller that injected a
			//      different repository here (a test mock, or a SpellChecker
			//      constructed with a specific repo) would otherwise scan the
			//      wrong repository and silently return no candidates.
			//   2. Stale scan state. PublishedPostsProvider is stateful
			//      (batch cursor, restricted ids, local-data mode); reusing a
			//      shared instance that another caller already advanced would
			//      leak that state into this scan.
			// Building per-collaborator from the injected repository keeps the
			// dependency the caller chose authoritative and the scan state clean.
			$this->publishedPostsProvider =
				new ABJ_404_Solution_PublishedPostsProvider($this->contentRepository);
		}
		$this->permalinkCache->updatePermalinkCache(1);
	}

}
