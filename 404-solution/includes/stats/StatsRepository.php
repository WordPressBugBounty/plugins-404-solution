<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/StatsRepositoryInterface.php';
require_once __DIR__ . '/StatsRefreshLock.php';
require_once __DIR__ . '/StatsReadRepository.php';
require_once __DIR__ . '/StatsDashboardSnapshotCache.php';
require_once __DIR__ . '/StatsDigestDataProvider.php';
require_once __DIR__ . '/../repositories/ContentKeywordsRepository.php';

/**
 * Stable facade for stats aggregation, dashboard snapshots, digest data, and keyword updates.
 * @phpstan-import-type Snapshot from ABJ_404_Solution_StatsDashboardSnapshotCache
 */
class ABJ_404_Solution_StatsRepository implements ABJ_404_Solution_StatsRepositoryInterface {

    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsReadRepository::PERIODIC_STATS_CACHE_TTL_SECONDS;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsReadRepository::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS;
    /** @var int Retention for dashboard stats snapshot payload. */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsDashboardSnapshotCache::STATS_DASHBOARD_CACHE_TTL_SECONDS;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsDashboardSnapshotCache::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS;
    /** @var int Cooldown for distributed refresh locks. */
    const REFRESH_LOCK_COOLDOWN_SECONDS = ABJ_404_Solution_StatsRefreshLock::REFRESH_LOCK_COOLDOWN_SECONDS;

    /** @var ABJ_404_Solution_StatsReadRepository */
    private $statsReadRepository;

    /** @var ABJ_404_Solution_StatsDashboardSnapshotCache */
    private $dashboardSnapshotCache;

    /** @var ABJ_404_Solution_StatsDigestDataProvider */
    private $digestDataProvider;

    /** @var ABJ_404_Solution_ContentKeywordsRepository */
    private $contentKeywordsRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepositoryInterface $logsRepo,
        $functions = null,
        $logging = null
    ) {
        $f = $functions !== null ? $functions : abj_service('functions');
        $logger = $logging !== null ? $logging : abj_service('logging');
        $refreshLock = new ABJ_404_Solution_StatsRefreshLock($dbCore);

        $this->statsReadRepository = new ABJ_404_Solution_StatsReadRepository(
            $dbCore,
            $logsRepo,
            $logger,
            $refreshLock
        );
        $this->dashboardSnapshotCache = new ABJ_404_Solution_StatsDashboardSnapshotCache(
            $logger,
            static function (): void {
                // Scheduling is required for a pending acknowledgement. Let the
                // cache report an unavailable read if the producer cannot be armed.
                abj_service('stats_rollup')->schedule();
            }
        );
        $this->digestDataProvider = new ABJ_404_Solution_StatsDigestDataProvider(
            $dbCore,
            $logsRepo,
            $this->statsReadRepository,
            $logger,
            $dbCore->collationHelper()
        );
        $this->contentKeywordsRepository = new ABJ_404_Solution_ContentKeywordsRepository(
            $dbCore,
            $f,
            $logger
        );
    }

    /** @inheritDoc */
    function getStatsCount($query, array $valueParams) {
        return $this->statsReadRepository->getStatsCount($query, $valueParams);
    }

    /** @inheritDoc */
    function getPeriodicStatsSummary($sinceTimestamp) {
        $snapshot = $this->snapshotForDerivedRead();
        foreach ($snapshot['thresholds'] ?? array() as $period => $threshold) {
            if ((int)$sinceTimestamp === $threshold) { return $this->publishedPeriod($snapshot['data']['periods'] ?? null, $period); }
        }
        $this->dashboardSnapshotCache->requestThreshold(abs((int)$sinceTimestamp));
        throw new RuntimeException('Statistics generation is pending for threshold ' . (string)$sinceTimestamp . '.', 503);
    }

    /** @inheritDoc */
    function getPeriodicStatsSummariesCached() {
        $snapshot = $this->snapshotForDerivedRead();
        $periods = $snapshot['data']['periods'] ?? array();
        return array('today' => $this->publishedPeriod($periods, 'today'), 'month' => $this->publishedPeriod($periods, 'month'),
            'year' => $this->publishedPeriod($periods, 'year'), 'all' => $this->publishedPeriod($periods, 'all'));
    }

    /** @inheritDoc */
    function getStatsDashboardSnapshot($allowStale = true) {
        return $this->dashboardSnapshotCache->getStatsDashboardSnapshot($allowStale);
    }

    /** @inheritDoc */
    function refreshStatsDashboardSnapshot($force = false) {
        return $this->dashboardSnapshotCache->refresh($force);
    }

    /** @inheritDoc */
    function getEarliestLogTimestamp() {
        return $this->statsReadRepository->getEarliestLogTimestamp();
    }

    /** @inheritDoc */
    function getConfidenceBandCounts() {
        $snapshot = $this->snapshotForDerivedRead();
        $bands = $snapshot['data']['confidence'] ?? null;
        if (!is_array($bands)) { return null; }
        return array('high' => ABJ_404_Solution_ExactInteger::readOr($bands['high'] ?? 0, 0, 0),
            'medium' => ABJ_404_Solution_ExactInteger::readOr($bands['medium'] ?? 0, 0, 0),
            'low' => ABJ_404_Solution_ExactInteger::readOr($bands['low'] ?? 0, 0, 0),
            'manual' => ABJ_404_Solution_ExactInteger::readOr($bands['manual'] ?? 0, 0, 0),
            'total' => ABJ_404_Solution_ExactInteger::readOr($bands['total'] ?? 0, 0, 0),
            'avg' => is_numeric($bands['avg'] ?? null) ? (float)$bands['avg'] : null);
    }

    /**
     * Derived readers retain pending/unknown contracts while preserving producer failures.
     * A prior publication remains usable even when a refresh reports an error.
     * @return Snapshot
     */
    private function snapshotForDerivedRead(): array {
        $snapshot = $this->dashboardSnapshotCache->getStatsDashboardSnapshot();
        if ($snapshot['data'] === array() && isset($snapshot['error'])) {
            $error = $snapshot['error'];
            throw new RuntimeException($error['message'] . ' (' . $error['code'] . ')',
                is_int($error['code']) ? $error['code'] : 503);
        }
        return $snapshot;
    }

    /**
     * Normalize a published period; never execute a legacy aggregate to fill a gap.
     * @param mixed $periods
     * @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int}
     */
    private function publishedPeriod($periods, string $period): array {
        $zero = array('disp404' => 0, 'distinct404' => 0, 'visitors404' => 0, 'refer404' => 0,
            'redirected' => 0, 'distinctredirected' => 0, 'distinctvisitors' => 0, 'distinctrefer' => 0);
        $stats = is_array($periods) && is_array($periods[$period] ?? null) ? $periods[$period] : array();
        foreach ($zero as $key => $unused) {
            $count = ABJ_404_Solution_ExactInteger::read($stats[$key] ?? null, 0);
            if ($count === null) { throw new RuntimeException('Statistics generation is pending for period ' . $period . '.', 503); }
            $zero[$key] = $count;
        }
        return $zero;
    }

    /** @inheritDoc */
    function getTopCapturedForDigest(int $limit): array {
        return $this->digestDataProvider->getTopCapturedForDigest($limit);
    }

    /** @inheritDoc */
    function buildTopCapturedForDigestQuery(int $limit): string {
        return $this->digestDataProvider->buildTopCapturedForDigestQuery($limit);
    }

    /** @inheritDoc */
    function getDigestSummaryStats(): array {
        return $this->digestDataProvider->getDigestSummaryStats(array($this, 'getStatsCount'));
    }

    /** @inheritDoc */
    function getCapturedCountForNotification(): int {
        return $this->digestDataProvider->getCapturedCountForNotification();
    }

    /** @inheritDoc */
    function getPostsNeedingContentKeywords(int $limit = 500): array {
        return $this->contentKeywordsRepository->getPostsNeedingContentKeywords($limit);
    }

    /** @inheritDoc */
    function bulkUpdateContentKeywords(array $idToKeywords): void {
        $this->contentKeywordsRepository->bulkUpdateContentKeywords($idToKeywords);
    }
}
