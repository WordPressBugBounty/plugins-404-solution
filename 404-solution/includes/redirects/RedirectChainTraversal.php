<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Describes the redirect-chain traversal capability used by write admission.
 *
 * Implementations return the same structured result for every walk so callers
 * can distinguish a terminating chain, a detected cycle, and a chain that
 * could not be verified because a required collaborator was unavailable.
 */
interface ABJ_404_Solution_RedirectChainTraversal {

    /**
     * Walk the redirect chain beginning at a same-site URL.
     *
     * The visited prefix is preserved in the returned path. A result has a
     * `status` of `ends`, `cycle`, or `unverifiable`; every result has a
     * `path`, and an `unverifiable` result also has a string `reason`.
     *
     * @param string $url Same-site path or URL at which to begin walking.
     * @param array<int, string> $visited Same-site path prefix already visited.
     * @return array{status: string, path: array<int, string>, reason?: string}
     */
    public function walk(string $url, array $visited): array;

    /**
     * Resolve a typed redirect destination and continue walking from it.
     *
     * Resolution happens before the optional lookup context is applied. An
     * initial resolution refusal returns the supplied source as its path so a
     * pending regex keeps the same diagnostic path as ordinary writes.
     *
     * @param array{type: int, destination: mixed, source: string, code?: int} $destination
     * @param array<int, string> $visited Same-site path prefix already visited.
     * @param array<string, mixed> $lookupContext Request-local lookup context.
     * @return array{status: string, path: array<int, string>, reason?: string}
     */
    public function walkFromDestination(array $destination, array $visited, array $lookupContext = array()): array;

    /**
     * Return a traversal with request-local lookup context applied.
     *
     * The context may include `excluded_redirect_id` and `pending_regex`
     * values used to evaluate a pending write against the live graph.
     *
     * @param array<string, mixed> $context Request-local lookup context.
     * @return self A traversal with the supplied context.
     */
    public function withLookupContext(array $context): self;
}
