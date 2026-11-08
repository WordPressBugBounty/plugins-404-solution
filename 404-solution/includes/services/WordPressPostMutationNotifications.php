<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress boundary protocol for guarded post and post-meta SQL mutations.
 *
 * Recovery writes through conditional SQL because the normal WordPress writers
 * cannot carry the complete post snapshot predicate. This service preserves
 * the filters, actions, serialized payloads, and cache eviction those writers
 * owe callers after the guarded statement has admitted the mutation.
 */
final class ABJ_404_Solution_WordPressPostMutationNotifications {

    /**
     * @param array{postId:int,key:string,rawValue:mixed} $event
     * @return array{allowed:bool,reason:string}
     */
    public function beforeAdd(array $event): array {
        $postId = $event['postId'];
        $key = $event['key'];
        $rawValue = $event['rawValue'];
        if (function_exists('apply_filters')) {
            $check = apply_filters('add_post_metadata', null, $postId, $key, $rawValue, false);
            if ($check !== null) {
                return array('allowed' => false, 'reason' => 'add_post_metadata short-circuit: ' . $this->describe($check));
            }
        }
        if (function_exists('do_action')) {
            do_action('add_post_meta', $postId, $key, $rawValue);
        }
        return array('allowed' => true, 'reason' => '');
    }

    /**
     * @param array{metaId:int,postId:int,key:string,rawValue:mixed,serializedValue:string} $event
     * @return array{allowed:bool,reason:string}
     */
    public function beforeUpdate(array $event): array {
        $metaId = $event['metaId'];
        $postId = $event['postId'];
        $key = $event['key'];
        $rawValue = $event['rawValue'];
        $serializedValue = $event['serializedValue'];
        if (function_exists('apply_filters')) {
            $check = apply_filters('update_post_metadata', null, $postId, $key, $rawValue, '');
            if ($check !== null) {
                return array('allowed' => false, 'reason' => 'update_post_metadata short-circuit: ' . $this->describe($check));
            }
        }
        if (function_exists('do_action')) {
            do_action('update_post_meta', $metaId, $postId, $key, $rawValue);
            do_action('update_postmeta', $metaId, $postId, $key, $serializedValue);
        }
        return array('allowed' => true, 'reason' => '');
    }

    /**
     * @param array{metaId:int,postId:int,key:string,rawValue:mixed} $event
     */
    public function afterAdd(array $event): void {
        $metaId = $event['metaId'];
        $postId = $event['postId'];
        $key = $event['key'];
        $rawValue = $event['rawValue'];
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($postId, 'post_meta');
        }
        if (function_exists('do_action')) {
            do_action('added_post_meta', $metaId, $postId, $key, $rawValue);
        }
    }

    /**
     * @param array{metaId:int,postId:int,key:string,rawValue:mixed,serializedValue:string} $event
     */
    public function afterUpdate(array $event): void {
        $metaId = $event['metaId'];
        $postId = $event['postId'];
        $key = $event['key'];
        $rawValue = $event['rawValue'];
        $serializedValue = $event['serializedValue'];
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($postId, 'post_meta');
        }
        if (function_exists('do_action')) {
            do_action('updated_post_meta', $metaId, $postId, $key, $rawValue);
            do_action('updated_postmeta', $metaId, $postId, $key, $serializedValue);
        }
    }

    /** @param WP_Post $before */
    public function afterDraft(WP_Post $before): void {
        $after = clone $before;
        $after->post_status = 'draft';
        clean_post_cache((int)$before->ID);
        wp_transition_post_status('draft', 'publish', $after);
        foreach (array('edit_post_page', 'edit_post', 'save_post_page', 'save_post', 'wp_insert_post') as $hook) {
            do_action($hook, (int)$before->ID, $after, true);
        }
        do_action('post_updated', (int)$before->ID, $after, $before);
        do_action('wp_after_insert_post', (int)$before->ID, $after, true, $before);
    }

    /**
     * Apply the same unslash/sanitize boundary that WordPress metadata writers
     * apply before filters and serialization.
     *
     * @param mixed $value
     * @return array{raw:mixed,stored:string}
     */
    public function prepareValue(int $postId, string $key, $value): array {
        $raw = function_exists('wp_unslash') ? wp_unslash($value) : $value;
        if (function_exists('sanitize_meta')) {
            $subtype = function_exists('get_object_subtype')
                ? get_object_subtype('post', $postId) : '';
            $raw = sanitize_meta($key, $raw, 'post', $subtype);
        }
        return array('raw' => $raw, 'stored' => $this->serializeValue($raw));
    }

    /** @param mixed $value */
    public function serializeValue($value): string {
        if (function_exists('maybe_serialize')) {
            $serialized = maybe_serialize($value);
            return $this->normalizeSerializedValue($serialized);
        }
        if (is_array($value) || is_object($value)) {
            return serialize($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return $this->normalizeSerializedValue($value);
    }

    /** @param mixed $value */
    private function normalizeSerializedValue($value): string {
        if ($value === null) { return ''; }
        if (is_bool($value)) { return $value ? '1' : ''; }
        if (is_int($value) || is_float($value) || is_string($value)) { return (string)$value; }
        throw new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
            'metadata serialization returned an unsupported value of type ' . $this->describe($value)
        );
    }

    /** @param mixed $value */
    private function describe($value): string {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }
        if (is_object($value)) {
            return get_class($value);
        }
        return is_array($value) ? 'array' : 'unknown';
    }
}
