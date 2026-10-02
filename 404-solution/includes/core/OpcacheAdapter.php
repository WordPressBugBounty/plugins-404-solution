<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability-safe boundary for the OPcache functions.
 *
 * Split from ABJ_404_Solution_PhpRuntimeCapabilityAdapter on the same line as
 * ABJ_404_Solution_PcntlSignalAdapter: OPcache is an extension, so it is
 * present or absent as a unit, and hosts additionally gate
 * opcache_get_status() behind opcache.restrict_api independently of
 * disable_functions. That makes "can this host answer OPcache questions" its
 * own question rather than two more entries in a flat capability list.
 *
 * Depends on the main adapter for the availability check and never the other
 * way round, so the boundary classes stay acyclic.
 */
final class ABJ_404_Solution_OpcacheAdapter {

    /** Functions this boundary owns; consumed by the disable-functions lint. */
    private const OWNED_FUNCTIONS = array(
        'opcache_get_status',
        'opcache_invalidate',
        'opcache_is_script_cached',
    );

    /** @return array<int, string> Functions whose direct use this boundary owns. */
    public static function ownedFunctions(): array {
        return self::OWNED_FUNCTIONS;
    }

    /**
     * The OPcache status array, or false when this host will not disclose it.
     *
     * `$includeScripts` has no default on purpose. True adds the per-script
     * map, which walks every script cached on the HOST (every site and plugin
     * sharing the pool), so its cost grows with the host rather than with this
     * plugin; a caller must choose it explicitly. Per-path questions belong to
     * isScriptCached().
     *
     * @return array<string, mixed>|false
     */
    public static function status(bool $includeScripts) {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('opcache_get_status')
            ? @opcache_get_status($includeScripts) : false;
    }

    /**
     * Whether OPcache holds a compiled entry for this one path: one hash
     * lookup, whatever the host has cached. Null (never false) when the
     * function is unavailable, so a missing API is not reported as "not
     * cached". Under opcache.restrict_api PHP answers false with a warning,
     * so a caller that must tell "restricted" from "not cached" checks that
     * setting first, as ABJ_404_Solution_OpcacheGenerationProbe does.
     */
    public static function isScriptCached(string $path): ?bool {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('opcache_is_script_cached')
            ? (bool)@opcache_is_script_cached($path) : null;
    }

    /** Attempt to invalidate one OPcache entry. */
    public static function invalidate(string $path, bool $force = false): bool {
        return ABJ_404_Solution_PhpRuntimeCapabilityAdapter::isFunctionAvailable('opcache_invalidate')
            && @opcache_invalidate($path, $force);
    }
}
