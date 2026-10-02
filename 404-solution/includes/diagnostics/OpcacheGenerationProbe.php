<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Are the opcodes this request is executing the same generation as the files
 * on disk? (Bruno timeout cause matrix, cause D "stale or mixed code".)
 *
 * Disk hashes prove what the filesystem holds. They cannot prove which opcodes
 * PHP actually ran: an opcode cache with `validate_timestamps` off, or one
 * that recompiled some files and not others across a deploy, will happily run
 * last release's bytecode for a file whose disk contents are current. The
 * exact proof is the compiled build marker: ABJ404_DIAGNOSTIC_BUILD_ID is
 * compiled into the boundary modules, and DiagnosticModuleManifest compares
 * it with the build ID derived from the files on disk
 * (`precomputed_build_matches_files`). This probe adds the per-file "is it
 * cached at all" answer and the cache-wide restart history.
 *
 * Constant cost per request, by design. The only per-script timestamp source
 * is `opcache_get_status(true)`, which walks every script cached on the HOST,
 * every site and plugin in the pool, not just this one. On a shared host with
 * 3466 cached scripts and a saturated CPU that walk took 31 to 38 seconds,
 * and the self-arming table canary paid it on every Redirects-tab load. The
 * timestamp comparison it bought was worth little: with validate_timestamps
 * off the cache reports no timestamp, and with it on the cache recompiles a
 * changed file within revalidate_freq seconds, so a mismatch was visible only
 * inside that window. So the cache-wide summary comes from
 * `opcache_get_status(false)` and each per-file answer from one
 * `opcache_is_script_cached()` lookup.
 *
 * Three-valued throughout: "cached", "not cached", and "unknown" are
 * different findings, and an unavailable status API must never collapse into
 * "not cached" -- that would read as a fresh deploy on every request of every
 * host with `opcache.restrict_api` set.
 */
final class ABJ_404_Solution_OpcacheGenerationProbe {

    /** @var (callable(string): ?bool)|null Per-path cache lookup, or null when unavailable. */
    private $cachedLookup;

    /** @var array<string, mixed> */
    private $summary;

    /**
     * @param (callable(string): ?bool)|null $cachedLookup
     * @param array<string, mixed> $summary
     */
    private function __construct(?callable $cachedLookup, array $summary) {
        $this->cachedLookup = $cachedLookup;
        $this->summary = $summary;
    }

    /** Read the opcode cache's state for this request. */
    public static function read(): self {
        $summary = self::unavailableSummary();
        if (self::apiRestricted()) {
            $summary['reason'] = 'opcache-api-restricted';
            return new self(null, $summary);
        }

        $status = ABJ_404_Solution_OpcacheAdapter::status(false);
        if (!is_array($status) || (array_key_exists('opcache_enabled', $status) && !$status['opcache_enabled'])) {
            return new self(null, $summary);
        }
        return new self(
            static function (string $path): ?bool {
                return ABJ_404_Solution_OpcacheAdapter::isScriptCached($path);
            },
            self::summaryFromStatus($summary, $status));
    }

    /**
     * Build a probe over a known set of cached paths. The seam a test uses to
     * drive a specific cache state without needing a host whose opcode cache
     * is in that state; null means "no per-script data".
     *
     * @param array<int, string>|null $cachedPaths
     */
    public static function forCachedPaths(?array $cachedPaths): self {
        $summary = self::unavailableSummary();
        if ($cachedPaths === null) {
            return new self(null, $summary);
        }
        $summary['reason'] = 'available';
        $cached = array_fill_keys(array_map('strval', $cachedPaths), true);
        return new self(
            static function (string $path) use ($cached): bool {
                return isset($cached[$path]);
            },
            $summary);
    }

    /**
     * Constant-cost OPcache evidence for one boundary module.
     *
     * Early boot checkpoints record only whether this one module is cached
     * plus the timestamp-validation policy that controls its freshness. The
     * compiled build marker beside this snapshot provides the exact
     * generation comparison.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function boundarySnapshot(string $path): array {
        $reason = 'opcache-unavailable';
        $cached = null;
        if (self::apiRestricted()) {
            $reason = 'opcache-api-restricted';
        } else {
            $cached = ABJ_404_Solution_OpcacheAdapter::isScriptCached($path);
            if ($cached !== null) {
                $reason = 'available';
            }
        }
        return array(
            'reason' => $reason,
            'cached' => $cached,
            'validate_timestamps' => self::iniBoolean(ini_get('opcache.validate_timestamps')),
            'revalidate_freq' => self::numericInteger(ini_get('opcache.revalidate_freq')),
        );
    }

    /**
     * The per-request summary for the journal.
     *
     * @return array<string, mixed>
     */
    public function summary(): array {
        return $this->summary;
    }

    /** Whether per-script state is available at all. False means every answer is "unknown". */
    public function hasPerScriptData(): bool {
        return $this->cachedLookup !== null;
    }

    /**
     * Whether OPcache holds this file. Null (not false) when there is no
     * per-script data, so an unavailable status API is never reported as an
     * uncached file.
     */
    public function isCached(string $path): ?bool {
        if ($this->cachedLookup === null) {
            return null;
        }
        return $path !== '' ? ($this->cachedLookup)($path) : false;
    }

    /**
     * Annotate loaded-file fingerprints with their opcode-cache state, keyed
     * by the `path` each entry already carries.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public function annotate(array $files): array {
        foreach ($files as &$file) {
            $file['opcache_cached'] = $this->isCached(is_string($file['path'] ?? null) ? $file['path'] : '');
        }
        unset($file);
        return $files;
    }

    /**
     * Whether opcache.restrict_api bars this plugin from the status API.
     * Checked before any call because a restricted call answers false with a
     * warning, which would read as "not cached".
     */
    private static function apiRestricted(): bool {
        $restrictApi = ini_get('opcache.restrict_api');
        return function_exists('abj404_opcache_api_is_restricted')
            ? abj404_opcache_api_is_restricted($restrictApi, __FILE__)
            : (is_string($restrictApi) && trim($restrictApi) !== '');
    }

    /** @return array<string, mixed> */
    private static function unavailableSummary(): array {
        return array(
            'reason' => 'opcache-unavailable',
            'validate_timestamps' => self::iniBoolean(ini_get('opcache.validate_timestamps')),
            'revalidate_freq' => self::numericInteger(ini_get('opcache.revalidate_freq')),
            'restart_pending' => null,
            'restart_in_progress' => null,
            'start_time' => null,
            'last_restart_time' => null,
            'restart_counts' => array('oom' => null, 'hash' => null, 'manual' => null),
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function summaryFromStatus(array $summary, array $status): array {
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : array();
        $summary['reason'] = 'available';
        $summary['restart_pending'] = isset($status['restart_pending']) ? (bool)$status['restart_pending'] : null;
        $summary['restart_in_progress'] = isset($status['restart_in_progress']) ? (bool)$status['restart_in_progress'] : null;
        $summary['start_time'] = self::numericInteger($statistics['start_time'] ?? null);
        $summary['last_restart_time'] = self::numericInteger($statistics['last_restart_time'] ?? null);
        $summary['restart_counts'] = array(
            'oom' => self::numericInteger($statistics['oom_restarts'] ?? null),
            'hash' => self::numericInteger($statistics['hash_restarts'] ?? null),
            'manual' => self::numericInteger($statistics['manual_restarts'] ?? null),
        );
        return $summary;
    }

    /** @param mixed $value */
    private static function iniBoolean($value): ?bool {
        if ($value === false || $value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @param mixed $value */
    private static function numericInteger($value): ?int {
        return ABJ_404_Solution_ExactInteger::read($value, PHP_INT_MIN);
    }
}
