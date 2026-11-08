<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../policies/PluginAdminAuthorization.php';

// allow-no-test-found: factory edge is covered by tests/NGramBlockedCronBehaviorTest.php and tests/NGramCronFallbackTest.php.

/**
 * Owns the fallback lifecycle boundary around the shared n-gram rebuild lock.
 * Admission policy and durable state remain in NGramCronFallback.
 */
class ABJ_404_Solution_NGramCronFallbackCoordinator {

    /** @var ABJ_404_Solution_NGramCronFallback */
    private $fallback;

    /** @var ABJ_404_Solution_SynchronizationUtils */
    private ABJ_404_Solution_SynchronizationUtils $syncUtils;

    /** @var ABJ_404_Solution_NGramCacheRebuildScheduler */
    private ABJ_404_Solution_NGramCacheRebuildScheduler $scheduler;

    /** @var ABJ_404_Solution_Logging */
    private ABJ_404_Solution_Logging $logger;

    /**
     * @param array{
     *     logger: ABJ_404_Solution_Logging,
     *     syncUtils: ABJ_404_Solution_SynchronizationUtils,
     *     runBatch: callable():void
     * } $options
     */
    public function __construct(
        ABJ_404_Solution_NGramNetworkOptionStore $optionStore,
        ABJ_404_Solution_NGramCacheRebuildScheduler $scheduler,
        ABJ_404_Solution_Clock $clock,
        array $options
    ) {
        $this->scheduler = $scheduler;
        $this->syncUtils = $options['syncUtils'];
        $this->logger = $options['logger'];
        $this->fallback = new ABJ_404_Solution_NGramCronFallback(
            $optionStore,
            $scheduler,
            $clock,
            array(
                'logger' => $this->logger,
                'runBatch' => \Closure::fromCallable($options['runBatch']),
                'runAfterResponseCallback' => function (): void {
                    $this->runLocked();
                },
            )
        );
    }

    /** Schedule the chain while keeping fallback admission outside its lock. */
    public function scheduleRebuild(): bool {
        $lockKey = 'ngram_schedule';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram rebuild scheduling: Another process holds the lock. Skipping.");
            return true;
        }
        try {
            $scheduled = $this->scheduler->scheduleRebuild();
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
        $this->arm();
        return $scheduled;
    }

    /** @return bool */
    public function arm(): bool {
        if (!ABJ_404_Solution_RequestKind::isAdminPageRender()
                || !ABJ_404_Solution_PluginAdminAuthorization::currentUserCanAccessPluginAdmin()) {
            return false;
        }
        return $this->fallback->arm();
    }

    /** Run one admitted slice under the existing shared rebuild lock. */
    public function runLocked(): void {
        $lockKey = 'ngram_rebuild';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);
        if (empty($uniqueID)) {
            $this->logger->debugMessage('N-gram fallback skipped because another rebuild holds the lock.');
            return;
        }
        try {
            $this->fallback->runLocked();
        } finally {
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }
}
