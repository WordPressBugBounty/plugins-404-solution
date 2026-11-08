<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped runtime state and static configuration for database upgrades.
 */
final class ABJ_404_Solution_DatabaseUpgradeRuntimeState {

    /**
     * Conservative initial batch size for backfillRedirectsCanonicalUrl(); a
     * pass-local sizer grows successful batches within its bounded maximum.
     * @var int
     */
    public const CANONICAL_URL_BACKFILL_CHUNK_SIZE = 5000;

    /**
     * Per-invocation wall-clock budget for redirect canonical URL backfill.
     * @var int
     */
    public const CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 25;

    /**
     * Per-invocation wall-clock budget for logsv2 canonical URL backfill.
     * @var int
     */
    public const LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 15;

    /**
     * wp_options key flipped when every logsv2 canonical_url value is filled.
     * @var string
     */
    public const LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION = 'abj404_logsv2_canonical_url_backfill_complete';

    /**
     * wp_options key flipped when every redirects canonical_url value is filled.
     * Lets the hits-rebuild phase2 JOIN drop the COALESCE wrap on the redirects
     * side and probe idx_canonical_url directly. See i359.
     * @var string
     */
    public const REDIRECTS_CANONICAL_URL_BACKFILL_COMPLETE_OPTION = 'abj404_redirects_canonical_url_backfill_complete';

    /**
     * Conservative initial batch size for backfillRedirectsDenormColumns(); a
     * pass-local sizer grows dense batches within a bounded maximum, while
     * sparse explicit-ID writes retain a smaller bound. Each chunk performs
     * derived projection and rollup work, so the initial size keeps statements
     * bounded while the pass measures successful work.
     * @var int
     */
    public const REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE = 1000;

    /**
     * Per-invocation wall-clock budget for the redirects denorm backfill.
     * @var int
     */
    public const REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC = 20;

    /**
     * Conservative initial batch size for reconcileRedirectsDenormColumns()
     * (Denorm Step 3d). Its pass-local sizer grows dense batches within a
     * bounded maximum and keeps sparse explicit-ID writes smaller; each chunk
     * also performs a logs_hits rollup join.
     * @var int
     */
    public const REDIRECTS_DENORM_RECONCILE_CHUNK_SIZE = 1000;

    /**
     * Per-invocation wall-clock budget for the nightly redirects denorm
     * reconcile. A pass that exhausts it persists its id cursor and resumes
     * through bounded single-shot continuations of that same nightly sweep.
     * @var int
     */
    public const REDIRECTS_DENORM_RECONCILE_TIME_BUDGET_SEC = 20;

    /**
     * wp_options key holding the resumable ascending-id cursor for the nightly
     * full reconcile (Denorm Step 3d): the highest redirect id recomputed in the
     * current pass, or 0 when no pass is mid-flight (the next tick starts fresh).
     * @var string
     */
    public const REDIRECTS_DENORM_RECONCILE_CURSOR_OPTION = 'abj404_redirects_denorm_reconcile_cursor';

    /**
     * wp_options key holding the ascending-id cursor for the one-time redirects
     * denorm backfill (Denorm Step 3a). It prevents each daily/on-demand chunk
     * from repeatedly scanning the already-drained low-id prefix on large hosts.
     * @var string
     */
    public const REDIRECTS_DENORM_BACKFILL_CURSOR_OPTION = 'abj404_redirects_denorm_backfill_cursor';

    /**
     * Known plugin table suffixes for adoption.
     * @var array<int, string>
     */
    private const PLUGIN_TABLE_SUFFIXES = [
        'abj404_redirects',
        'abj404_logsv2',
        'abj404_spelling_cache',
        'abj404_permalink_cache',
        'abj404_lookup',
        'abj404_ngram_cache',
        'abj404_logs_hits',
        'abj404_stats_dimensions',
        'abj404_stats_buckets',
        'abj404_redirect_conditions',
        'abj404_engine_profiles',
        'abj404_view_cache',
    ];

    /** @var string|null */
    private static $runtimeId = null;

    /** @return void */
    public static function initializeRuntimeId(): void {
        if (self::$runtimeId === null) {
            self::$runtimeId = uniqid('', true);
        }
    }

    /** @return string|null */
    public static function getRuntimeId() {
        return self::$runtimeId;
    }

    /** @return void */
    public static function resetRuntimeIdForTests(): void {
        self::$runtimeId = null;
    }

    /** @return array<int, string> */
    public static function getPluginTableSuffixes(): array {
        return self::PLUGIN_TABLE_SUFFIXES;
    }
}
