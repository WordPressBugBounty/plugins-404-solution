<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin WordPress hook adapter for AutoRedirectSnapshotService.  It owns only
 * argument unpacking and lifecycle registration; snapshot policy and writes
 * remain in the service layer.
 */
class ABJ_404_Solution_AutoRedirectSnapshotHooks {

    /** @var ABJ_404_Solution_AutoRedirectSnapshotService */
    private $service;

    public function __construct(ABJ_404_Solution_AutoRedirectSnapshotService $service) {
        $this->service = $service;
    }

    /** Register before/after hooks for terms and hierarchical posts. */
    public static function init(): void {
        $me = new self(abj_service('auto_redirect_snapshot_service'));
        add_action('edit_terms', array($me, 'onEditTerms'), 10, 2);
        add_action('edited_term', array($me, 'onEditedTerm'), 10, 3);
        add_action('pre_post_update', array($me, 'onPrePostUpdate'), 10, 3);
        add_action('save_post', array($me, 'onSavePost'), 11, 3);
    }

    /** @param int|string $termId @param string $taxonomy */
    public function onEditTerms($termId, string $taxonomy = ''): void {
        try {
            $this->service->snapshotTerm($termId, $taxonomy);
        } catch (\Throwable $e) {
            $this->logFailure('onEditTerms', $e);
        }
    }

    /**
     * @param int|string $termId
     * @param int|string $ttId
     * @param string $taxonomy
     */
    public function onEditedTerm($termId, $ttId = 0, string $taxonomy = ''): void {
        try {
            $this->service->createTermRedirects($termId, $taxonomy);
        } catch (\Throwable $e) {
            $this->logFailure('onEditedTerm', $e);
        }
    }

    /**
     * @param int|string $postId
     * @param array<string,mixed> $data
     * @param array<string,mixed> $postarr
     */
    public function onPrePostUpdate($postId, array $data = array(), array $postarr = array()): void {
        try {
            $this->service->snapshotPostDescendants(
                $postId,
                $data,
                $postarr
            );
        } catch (\Throwable $e) {
            $this->logFailure('onPrePostUpdate', $e);
        }
    }

    /**
     * Turn the descendant snapshot taken on pre_post_update into redirects.
     *
     * @param int|string $postId
     * @param object|null $post
     * @param bool $update
     */
    public function onSavePost($postId, ?object $post = null, bool $update = false): void {
        // @hook-lifecycle: opt-out - idempotent by construction: the service
        // consumes the post's pending snapshot on the first fire, so a repeat
        // save_post finds none and writes nothing, while a later distinct
        // update in the same request takes a fresh snapshot on its own
        // pre_post_update. AutoRedirectSnapshotIntegrationTest covers both
        // (testAncestorSlugChangeCreatesRowsForDescendants fires save_post
        // twice; testSecondAncestorRenameInOneRequestRedirectsBothOldPaths).
        try {
            if ($update) {
                $this->service->createPostDescendantRedirects($postId);
            }
        } catch (\Throwable $e) {
            $this->logFailure('onSavePost', $e);
        }
    }

    private function logFailure(string $callback, \Throwable $e): void {
        $logger = abj_service('logging');
        $message = __CLASS__ . '/' . $callback . ' failed: ' . get_class($e) . ': ' . $e->getMessage();
        if (function_exists('abj404_logCallbackFailure')) {
            abj404_logCallbackFailure($logger, $message, $e);
        } elseif (is_object($logger) && method_exists($logger, 'errorMessage')) {
            $logger->errorMessage($message, $e instanceof \Exception ? $e : null);
        }
    }
}
