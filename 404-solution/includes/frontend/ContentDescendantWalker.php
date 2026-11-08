<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds the descendants of a term or of a hierarchical post, never more than a
 * fixed cap of them.
 *
 * Both walks expand one bounded frontier of immediate children at a time, so
 * no read depends on WordPress building a complete hierarchy, and both give up
 * as a whole when anything goes wrong (a failed or malformed read, or more
 * descendants than the cap): an empty result, with a warning, rather than a
 * partial list. The caller writes one redirect per descendant it is given, so a
 * partial prefix would redirect some of a moved parent's children and silently
 * leave the rest broken.
 */
class ABJ_404_Solution_ContentDescendantWalker {

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var int Maximum descendants returned for one root; more than this returns none. */
    private $cap;

    /**
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepository Bounded content reads.
     * @param ABJ_404_Solution_Logging $logger Logger.
     * @param int $cap Maximum descendants returned for one root.
     */
    public function __construct(
        ABJ_404_Solution_ContentRepositoryInterface $contentRepository,
        ABJ_404_Solution_Logging $logger,
        int $cap
    ) {
        $this->contentRepository = $contentRepository;
        $this->logger = $logger;
        $this->cap = $cap;
    }

    /**
     * Walk bounded immediate-child frontiers without asking WordPress to build
     * its complete taxonomy hierarchy. A failed or over-cap read discards all
     * descendants so a request never writes a misleading partial prefix.
     *
     * @return array<int,int>
     */
    public function termDescendantIds(int $rootId, string $taxonomy): array {
        $frontier = array($rootId);
        $seen = array($rootId => true);
        $descendantIds = array();

        while ($frontier !== array() && count($descendantIds) <= $this->cap) {
            $remaining = $this->cap - count($descendantIds);
            $termChildRead = $this->contentRepository->readTermChildIds(array(
                'taxonomy' => $taxonomy,
                'parents' => $frontier,
                'limit' => $remaining + 1,
            ));
            if (!is_array($termChildRead) || !isset($termChildRead['state'])) {
                $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                    ' taxonomy ' . $taxonomy . ': malformed bounded read result.');
                return array();
            }
            if ($termChildRead['state'] !== 'read') {
                $error = isset($termChildRead['error']) && is_scalar($termChildRead['error'])
                    ? (string)$termChildRead['error'] : 'unknown bounded read failure';
                $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                    ' taxonomy ' . $taxonomy . ': bounded read failed (' . $error . ').');
                return array();
            }
            if (!isset($termChildRead['ids']) || !is_array($termChildRead['ids'])) {
                $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                    ' taxonomy ' . $taxonomy . ': malformed bounded read IDs.');
                return array();
            }
            if (count($termChildRead['ids']) > $remaining + 1) {
                $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                    ' taxonomy ' . $taxonomy . ': bounded read exceeded cap ' . $this->cap . '.');
                return array();
            }

            $nextFrontier = array();
            foreach ($termChildRead['ids'] as $childId) {
                $parsedId = ABJ_404_Solution_ExactInteger::read($childId, 1);
                if ($parsedId === null) {
                    $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                        ' taxonomy ' . $taxonomy . ': malformed child ID.');
                    return array();
                }
                if (isset($seen[$parsedId])) {
                    continue;
                }
                $seen[$parsedId] = true;
                $descendantIds[] = $parsedId;
                $nextFrontier[] = $parsedId;
                if (count($descendantIds) > $this->cap) {
                    $this->warn('Skipping automatic term descendant redirects for root ID ' . $rootId .
                        ' taxonomy ' . $taxonomy . ': descendants exceed cap ' . $this->cap . '.');
                    return array();
                }
            }
            $frontier = $nextFrontier;
        }

        return $descendantIds;
    }

    /**
     * Walk immediate parent frontiers so WordPress limits each bounded query
     * before the next frontier is expanded. `get_pages(child_of, number)`
     * limits the complete site result before filtering descendants, which can
     * silently omit a moved page's children on large sites.
     *
     * @return array<int, WP_Post>
     */
    public function postDescendants(int $rootId, string $postType): array {
        if (!function_exists('get_posts')) {
            return array();
        }

        $frontier = array($rootId);
        $seen = array();
        $descendants = array();

        while ($frontier !== array() && count($descendants) <= $this->cap) {
            $remaining = $this->cap + 1 - count($descendants);
            $rows = get_posts(array(
                'post_parent__in' => $frontier,
                'post_type' => $postType,
                // A published child remains public below a draft/private
                // parent, so traversal must include those intermediate nodes.
                'post_status' => 'any',
                'posts_per_page' => $remaining,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'cache_results' => false,
            ));
            if (!is_array($rows) || $rows === array()) {
                break;
            }

            $nextFrontier = array();
            foreach ($rows as $row) {
                if (!is_object($row)) {
                    continue;
                }
                $descendantId = (int)$row->ID;
                if ($descendantId <= 0 || isset($seen[$descendantId])) {
                    continue;
                }
                $seen[$descendantId] = true;
                $descendants[] = $row;
                $nextFrontier[] = $descendantId;
                if (count($descendants) > $this->cap) {
                    return $this->discardOverCapDescendants($rootId, count($descendants));
                }
            }
            $frontier = $nextFrontier;
        }

        return $descendants;
    }

    /** @return array<int, WP_Post> */
    private function discardOverCapDescendants(int $rootId, int $count): array {
        $this->warn('Skipping automatic descendant redirects for post ID ' . $rootId . ': ' .
            $count . ' descendants exceeds cap ' . $this->cap . '.');
        return array();
    }

    private function warn(string $message): void {
        $this->logger->warn(__CLASS__ . ': ' . $message);
    }
}
