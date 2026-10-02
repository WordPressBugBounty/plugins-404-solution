<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Picks where the URL of a post that is being trashed or deleted should now
 * send visitors: the most specific place on the site that still lists or
 * contains related content.
 *
 * A blanket redirect to the homepage is what Google reports as a soft 404, so
 * the homepage is only the last resort. Lookup order:
 *
 *   1. Primary term of the post's first public hierarchical taxonomy
 *      (category for posts, product_cat for WooCommerce products, ...). The
 *      primary term set by Yoast or Rank Math wins; otherwise the lowest term
 *      id, which is the term WordPress itself puts in %category% permalinks.
 * *      The taxonomy's default term ("Uncategorized", read from the
 *      default_{taxonomy} option WordPress and WooCommerce use) is a
 *      catch-all, not a parent, and a term with no other published post would show an empty
 *      archive, so both are skipped.
 *   2. Nearest published ancestor, for hierarchical post types (pages).
 *   3. The post type archive, when the type has one and it still lists other
 *      published posts.
 *   4. The homepage.
 *
 * Must run while the post still has its terms and parent, which is the case
 * on transition_post_status (trash keeps both) and on before_delete_post
 * (runs before wp_delete_post() removes the term relationships and
 * re-parents children).
 *
 * Targets are stored as redirect types that are resolved at redirect time
 * (term id, post id), so later renames of the target are followed. A post
 * type archive has no dedicated redirect type, so its URL is stored as an
 * external-type destination; RedirectWriteAdmissionPolicy admits an
 * automatic redirect of that shape only when the URL is a live archive.
 */
class ABJ_404_Solution_RemovedPostParentResolver {

    /** Guards against corrupt post_parent chains that loop. */
    const MAX_ANCESTOR_DEPTH = 100;

    /** Post meta keys SEO plugins use for the primary term, by taxonomy. */
    const PRIMARY_TERM_META_FORMATS = array('_yoast_wpseo_primary_%s', 'rank_math_primary_%s');

    const REASON_CATEGORY = 'parent category';
    const REASON_PARENT_PAGE = 'parent page';
    const REASON_ARCHIVE = 'post type archive';
    const REASON_HOMEPAGE = 'homepage';

    /**
     * Resolve the redirect target for a post that is being removed.
     *
     * @param object $post The WP_Post being trashed or deleted (needs ID, post_type, post_parent).
     * @return array{type: int, dest: string, reason: string} Redirect type
     *     (ABJ404_TYPE_*), final_dest value for that type, and a short reason
     *     naming which rule matched.
     */
    public function resolve($post): array {
        $postId = (is_object($post) && isset($post->ID)) ? (int)$post->ID : 0;
        $postType = (is_object($post) && isset($post->post_type) && is_string($post->post_type))
            ? $post->post_type : '';
        if ($postId <= 0 || $postType === '') {
            return $this->homepage();
        }

        $termId = $this->primaryTermId($postId, $postType);
        if ($termId > 0) {
            return array('type' => ABJ404_TYPE_CAT, 'dest' => (string)$termId, 'reason' => self::REASON_CATEGORY);
        }

        $postTypeObject = get_post_type_object($postType);

        if (is_object($postTypeObject) && !empty($postTypeObject->hierarchical)) {
            $ancestorId = $this->nearestPublishedAncestorId($post, $postId);
            if ($ancestorId > 0) {
                return array('type' => ABJ404_TYPE_POST, 'dest' => (string)$ancestorId, 'reason' => self::REASON_PARENT_PAGE);
            }
        }

        if (is_object($postTypeObject) && !empty($postTypeObject->has_archive)) {
            $archiveUrl = get_post_type_archive_link($postType);
            if (is_string($archiveUrl) && $archiveUrl !== '' &&
                    $this->hasOtherPublishedPosts($postId, $postType, null)) {
                return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $archiveUrl, 'reason' => self::REASON_ARCHIVE);
            }
        }

        return $this->homepage();
    }

    /** @return array{type: int, dest: string, reason: string} */
    private function homepage(): array {
        return array('type' => ABJ404_TYPE_HOME, 'dest' => '0', 'reason' => self::REASON_HOMEPAGE);
    }

    /**
     * The term to redirect to from the first public hierarchical taxonomy
     * the post has a usable term in, or 0.
     *
     * @param int $postId
     * @param string $postType
     * @return int
     */
    private function primaryTermId(int $postId, string $postType): int {
        $taxonomies = get_object_taxonomies($postType, 'objects');
        if (!is_array($taxonomies)) {
            return 0;
        }
        // 'category' first: it is the parent WordPress itself puts in post permalinks.
        uksort($taxonomies, function ($a, $b) {
            return ($a === 'category' ? 0 : 1) <=> ($b === 'category' ? 0 : 1);
        });

        foreach ($taxonomies as $taxonomyName => $taxonomy) {
            if (!is_object($taxonomy) || empty($taxonomy->hierarchical) || empty($taxonomy->publicly_queryable)) {
                continue;
            }
            $termId = $this->usableTermIdInTaxonomy($postId, $postType, (string)$taxonomyName);
            if ($termId > 0) {
                return $termId;
            }
        }
        return 0;
    }

    /**
     * @param int $postId
     * @param string $postType
     * @param string $taxonomy
     * @return int
     */
    private function usableTermIdInTaxonomy(int $postId, string $postType, string $taxonomy): int {
        $assigned = get_the_terms($postId, $taxonomy);
        if (!is_array($assigned) || $assigned === array()) {
            return 0;
        }
        $assignedIds = array();
        foreach ($assigned as $term) {
            if ($term->term_id > 0) {
                $assignedIds[] = $term->term_id;
            }
        }
        sort($assignedIds);

        $candidates = $assignedIds;
        $primaryId = $this->seoPrimaryTermId($postId, $taxonomy);
        if ($primaryId > 0 && in_array($primaryId, $assignedIds, true)) {
            $candidates = array_values(array_unique(array_merge(array($primaryId), $assignedIds)));
        }

        // default_category for posts, default_product_cat for WooCommerce.
        $defaultOption = get_option('default_' . $taxonomy);
        $defaultCategory = ABJ_404_Solution_ExactInteger::readOr($defaultOption, 1, 0);
        foreach ($candidates as $termId) {
            if ($termId === $defaultCategory) {
                continue;
            }
            if ($this->termIsLiveArchive($termId, $taxonomy) &&
                    $this->hasOtherPublishedPosts($postId, $postType, array('taxonomy' => $taxonomy, 'term' => $termId))) {
                return $termId;
            }
        }
        return 0;
    }

    /**
     * @param int $postId
     * @param string $taxonomy
     * @return int
     */
    private function seoPrimaryTermId(int $postId, string $taxonomy): int {
        foreach (self::PRIMARY_TERM_META_FORMATS as $format) {
            $value = get_post_meta($postId, sprintf($format, $taxonomy), true);
            $termId = ABJ_404_Solution_ExactInteger::readOr($value, 1, 0);
            if ($termId > 0) {
                return $termId;
            }
        }
        return 0;
    }

    /**
     * @param int $termId
     * @param string $taxonomy
     * @return bool
     */
    private function termIsLiveArchive(int $termId, string $taxonomy): bool {
        $term = get_term($termId, $taxonomy);
        if (!is_object($term) || is_wp_error($term)) {
            return false;
        }
        $link = get_term_link($term);
        return is_string($link) && $link !== '';
    }

    /**
     * Whether a listing still shows at least one published post other than
     * the one being removed, so the redirect does not land on an empty
     * archive (itself a soft 404).
     *
     * @param int $postId
     * @param string $postType
     * @param array{taxonomy: string, term: int}|null $term Restrict to one term, or null for the whole type.
     * @return bool
     */
    private function hasOtherPublishedPosts(int $postId, string $postType, ?array $term): bool {
        $args = array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'post__not_in' => array($postId),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        );
        if ($term !== null) {
            $args['tax_query'] = array(array(
                'taxonomy' => $term['taxonomy'],
                'field' => 'term_id',
                'terms' => array($term['term']),
                'include_children' => true,
            ));
        }
        $found = get_posts($args);
        return is_array($found) && $found !== array();
    }

    /**
     * @param object $post
     * @param int $postId
     * @return int The nearest ancestor that is published, or 0.
     */
    private function nearestPublishedAncestorId($post, int $postId): int {
        $visited = array($postId => true);
        $parentId = isset($post->post_parent) ? (int)$post->post_parent : 0;
        for ($depth = 0; $parentId > 0 && $depth < self::MAX_ANCESTOR_DEPTH; $depth++) {
            if (isset($visited[$parentId])) {
                return 0;
            }
            $visited[$parentId] = true;
            $parent = ABJ_404_Solution_PostRef::fromWpPost(get_post($parentId));
            if ($parent === null) {
                return 0;
            }
            if ($parent->isPublished()) {
                return $parent->getId();
            }
            $parentId = $parent->getParentId();
        }
        return 0;
    }
}
