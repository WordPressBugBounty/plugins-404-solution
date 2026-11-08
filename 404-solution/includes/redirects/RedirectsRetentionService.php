<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRetentionPolicy.php';
require_once __DIR__ . '/RedirectsCleanupRepository.php';

/**
 * Scheduled-maintenance workflow for the redirects table.
 *
 * Owns the redirect/log retention pruning, auto-redirect expiry, junk
 * auto-trash, orphan cleanup, and the coordinated cron run. Previously fronted
 * by RedirectsRetentionServiceInterface, but that
 * interface had a single implementer and no narrowing consumer, so it was
 * inlined to remove the unrealized abstraction.
 * @phpstan-import-type MaintenancePass from ABJ_404_Solution_MaintenanceDrain
 */
class ABJ_404_Solution_RedirectsRetentionService {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRetentionPolicy */
    private $retentionPolicy;

    /** @var ABJ_404_Solution_RedirectsCleanupRepository */
    private $cleanupRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RedirectsRetentionPolicy|null $retentionPolicy
     * @param ABJ_404_Solution_RedirectsCleanupRepository|null $cleanupRepository
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo,
        $functions = null,
        $logging = null,
        ?ABJ_404_Solution_RedirectsRetentionPolicy $retentionPolicy = null,
        ?ABJ_404_Solution_RedirectsCleanupRepository $cleanupRepository = null
    ) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->retentionPolicy = $retentionPolicy !== null
            ? $retentionPolicy
            : new ABJ_404_Solution_RedirectsRetentionPolicy();
        $this->cleanupRepository = $cleanupRepository !== null
            ? $cleanupRepository
            : new ABJ_404_Solution_RedirectsCleanupRepository(
                $dbCore,
                $redirectsRepo,
                $this->f,
                $this->logger,
                $this->retentionPolicy
            );
    }

    /** @return int Number of orphaned auto redirects removed. */
    public function cleanupOrphanedAutoRedirects(): int {
        return $this->cleanupRepository->cleanupOrphanedAutoRedirects();
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @return int
     */
    public function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        return $this->cleanupRepository->deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType);
    }

    /**
     * @param int $daysToKeep
     * @param int $now
     * @return int
     */
    public function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        return $this->cleanupRepository->deleteOldLogsByAge($daysToKeep, $now);
    }

    /**
     * @param mixed $passId
     * @return string Human-readable progress from one bounded cron slice.
     */
    function deleteOldRedirectsCron($passId = null): string {
        $pass = abj_service('maintenance_drain')->tick($passId, ['manual' => $this->isManualMaintenanceRun()]);
        if ($pass === null) { return ''; }
        $viewRead = abj_service('view_read_service');
        $manually_fired = $pass['manual'];
        $removed = $pass['removed'];
        $logsSizeBytes = $viewRead->getLogDiskUsage();
        // -1 means the size query failed or timed out: an unknown size.
        $logSizeText = $logsSizeBytes >= 0 ? round($logsSizeBytes / (1024 * 1000), 2) . 'MB' : 'unknown';
        $age = $removed['logs_age'] ?? 0;
        $size = $removed['logs_size'] ?? 0;
        $message = "deleteOldRedirectsCron. Old captured URLs removed: " .
                ($removed['captured'] ?? 0) . ", Old automatic redirects removed: " . ($removed['automatic'] ?? 0) .
                ", Old manual redirects removed: " . ($removed['manual'] ?? 0) .
                ", Orphaned auto redirects removed: " . ($removed['orphaned'] ?? 0) .
                ", Junk URLs auto-trashed: " . ($removed['junk'] ?? 0) .
                ", Old log lines removed: " . ($age + $size) .
                " (age: " . $age . ", size: " . $size . ")" .
                ", New log size: " . $logSizeText .
                ", Duplicate rows deleted: " . ($removed['duplicates'] ?? 0);
        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;
        $this->logger->infoMessage($message);
        return $message;
    }

    /** @return array<string,callable> Independent, bounded retention phases. */
    public function maintenanceStages(): array {
        $limits = ['batch_size' => 1000, 'max_batches' => 1, 'timeout' => 5];
        $counted = function (int $count, int $batch = 1000): array {
            return ['removed' => $count, 'complete' => $count < $batch,
                'failure' => $this->cleanupRepository->lastCleanupFailure()];
        };
        return [
            'tables' => static function (): array {
                abj_service('database_upgrades')->components()->bootstrapUpgrade()->repairMissingTables();
                return ['complete' => true];
            },
            'logs_age' => function () use ($limits, $counted): array {
                $days = $this->retentionPolicy->daysFromOptions($this->currentMaintenanceOptions(), 'capture_deletion');
                return $counted($this->cleanupRepository->deleteOldLogsByAge($days, abj_clock()->now(), $limits));
            },
            'logs_size' => function (array $pass) use ($limits): array {
                $plan = $pass['log_plan'];
                if ($plan === null) { return ['complete' => true]; }
                $current = $this->currentMaintenanceOptions();
                if (($current['maximum_log_disk_usage'] ?? null) !== $plan['cap_raw']) {
                    return ['complete' => false];
                }
                $outcome = $this->cleanupRepository->trimOldestLogsToCount($plan['target_keep'], $limits);
                $outcome['failure'] = $this->cleanupRepository->lastCleanupFailure();
                return $outcome;
            },
            'captured' => fn() => $this->redirectRetentionSlice(['key' => 'capture_deletion',
                'statuses' => ABJ404_STATUS_CAPTURED . ', ' . ABJ404_STATUS_IGNORED . ', ' . ABJ404_STATUS_LATER,
                'label' => 'Captured 404', 'limits' => $limits]),
            'automatic' => fn() => $this->redirectRetentionSlice(['key' => 'auto_deletion',
                'statuses' => (string)ABJ404_STATUS_AUTO, 'label' => 'Automatic redirect', 'limits' => $limits]),
            'manual' => fn() => $this->redirectRetentionSlice(['key' => 'manual_deletion',
                'statuses' => ABJ404_STATUS_MANUAL . ', ' . ABJ404_STATUS_REGEX, 'label' => 'Manual redirect', 'limits' => $limits]),
            'orphaned' => fn() => $counted($this->cleanupRepository->cleanupOrphanedAutoRedirects($limits)),
            'junk' => fn() => $counted($this->cleanupRepository->autoTrashJunkCapturedUrls($this->currentMaintenanceOptions(), $limits)),
            'duplicates' => fn(array $pass) => $this->cleanupRepository->removeDuplicatesSlice([
                'scan' => $pass['duplicate_scan'] ?? null,
                'limits' => ['batch_size' => 20, 'max_batches' => 1, 'timeout' => 5],
            ]),
            'auto_expiration' => function () use ($counted): array {
                $days = $this->retentionPolicy->daysFromOptions($this->currentMaintenanceOptions(), 'auto_302_expiration_days');
                return $counted($this->cleanupRepository->expireOldAutoRedirects($days, abj_clock()->now(),
                    ['batch_size' => 100, 'max_batches' => 1, 'timeout' => 5]), 100);
            },
            'files' => function (): array {
                $this->deleteTempExportFile(abj_service('plugin_logic'));
                $this->limitDebugFileSize();
                return ['complete' => true];
            },
            'notifications' => function (array $pass): array {
                $options = $this->currentMaintenanceOptions();
                $message = $this->appendAdminNotificationMessage('', $options, $pass['manual'], abj_service('plugin_logic'));
                $this->logger->infoMessage($this->appendDeveloperLogMessage($message, $options));
                return ['complete' => true];
            },
            'permalinks' => static function (): array {
                $cron = abj_cron_scheduler();
                $hook = ABJ_404_Solution_CronScheduler::HOOK_UPDATE_PERMALINK_CACHE;
                $ok = $cron->hasAnyScheduledEvent($hook) || $cron->scheduleSingleIfMissing($hook, 10, [1, 1]);
                return ['complete' => $ok, 'failure' => $ok ? '' : $cron->lastFailureDetail()];
            },
            'updates' => static function (): array {
                abj_service('database_upgrades')->components()->pluginUpdateUpgrade()->updatePluginCheck();
                return ['complete' => true];
            },
        ];
    }

    /**
     * Persist a stable size target before the first destructive size slice.
     * @param MaintenancePass $pass
     * @return MaintenancePass
     */
    public function prepareMaintenanceStage(string $phase, array $pass): array {
        if ($phase !== 'logs_size') { return $pass; }
        $options = $this->currentMaintenanceOptions();
        $view = abj_service('view_read_service');
        $pass['log_plan'] = $this->retentionPolicy->sizeRetentionPlan($options, $pass['log_plan'],
            $view->getLogDiskUsage(), static function () use ($view): int { return $view->getLogsCount(0); });
        return $pass;
    }

    /** @return array<string,mixed> Re-read settings before every destructive slice. */
    private function currentMaintenanceOptions(): array {
        $repository = abj_service('options_repository');
        $repository->clearCache();
        $options = $repository->getOptions(true);
        foreach (['capture_deletion', 'auto_deletion', 'manual_deletion', 'maximum_log_disk_usage',
            'auto_302_expiration_days', 'auto_trash_junk_urls', 'auto_trash_junk_patterns'] as $key) {
            $options[$key] = $repository->getRawSettingValue($key, $options[$key] ?? null);
        }
        return $options;
    }

    /**
     * @param array{key:string,statuses:string,label:string,limits:array<string,int>} $request
     * @return array{removed:int,complete:bool,failure:string}
     */
    private function redirectRetentionSlice(array $request): array {
        $count = $this->cleanupRepository->deleteOldRedirectsByType($this->currentMaintenanceOptions(), abj_clock()->now(),
            $request['key'], $request['statuses'], $request['label'], $request['limits']);
        return ['removed' => $count, 'complete' => $count < $request['limits']['batch_size'],
            'failure' => $this->cleanupRepository->lastCleanupFailure()];
    }

    private function isManualMaintenanceRun(): bool {
        $manuallyFired = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('manually_fired', 'false');
        return $this->f->strtolower($manuallyFired) == 'true';
    }

    /**
     * @param mixed $abj404logic
     * @return void
     */
    private function deleteTempExportFile($abj404logic): void {
        $tempFile = null;
        if (is_object($abj404logic) && method_exists($abj404logic, 'importExport')) {
            $importExport = $abj404logic->importExport();
            if (is_object($importExport) && method_exists($importExport, 'getExportFilename')) {
                $tempFile = $importExport->getExportFilename();
            }
        }
        if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($tempFile);
        }
    }

    /**
     * @param string $message
     * @param array<string, mixed> $options
     * @param bool $manuallyFired
     * @param mixed $abj404logic
     * @return string
     */
    private function appendAdminNotificationMessage(string $message, array $options, bool $manuallyFired, $abj404logic): string {
        $adminEmailVal = array_key_exists('admin_notification_email', $options) ? $options['admin_notification_email'] : '';
        $adminEmail = is_string($adminEmailVal) ? $adminEmailVal : '';
        if ($this->f->strlen(trim($adminEmail)) <= 5) {
            return $message . ', Admin email notification option turned off.';
        }

        if ($manuallyFired) {
            return $message . ', The admin email notification option is skipped for user initiated maintenance runs.';
        }

        if (!is_object($abj404logic) || !method_exists($abj404logic, 'pageOrdering')) {
            $this->logger->warn('Admin email notification skipped: plugin logic pageOrdering unavailable.');
            return $message . ', Admin email notification option unavailable.';
        }

        $pageOrdering = $abj404logic->pageOrdering();
        if (!is_object($pageOrdering) || !method_exists($pageOrdering, 'emailCaptured404Notification')) {
            $this->logger->warn('Admin email notification skipped: page ordering email sender unavailable.');
            return $message . ', Admin email notification option unavailable.';
        }

        return $message . ', ' . $pageOrdering->emailCaptured404Notification();
    }

    /**
     * @param string $message
     * @param array<string, mixed> $options
     * @return string
     */
    private function appendDeveloperLogMessage(string $message, array $options): string {
        if (!isset($options['send_error_logs']) || $options['send_error_logs'] != '1') {
            return $message;
        }

        // Drain any pending crash beacon first: a fatal/OOM that could not phone
        // home at the time is the highest-value signal, and it is independent of
        // whether there is also a fresh error line to email this run.
        if ($this->logger->drainCrashBeaconIfNecessary()) {
            $message .= ", Crash beacon reported to developer.";
        }

        if ($this->logger->emailErrorLogIfNecessary()) {
            return $message . ", Log file emailed to developer.";
        }

        if ($this->logger->sendHeartbeatIfDueWeekly()) {
            return $message . ", Heartbeat log emailed to developer.";
        }

        return $message;
    }

    /** @return bool Whether the debug log file was rotated. */
    function limitDebugFileSize(): bool {
        $renamed = false;

        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }

        return $renamed;
    }

    /** @return int Number of duplicate redirect rows removed. */
    function removeDuplicatesCron(): int {
        return $this->cleanupRepository->removeDuplicatesCron();
    }

    /**
     * @param array<string, mixed> $options Plugin options array.
     * @return int Number of captured URLs auto-trashed.
     */
    function autoTrashJunkCapturedUrls(array $options): int {
        return $this->cleanupRepository->autoTrashJunkCapturedUrls($options);
    }

    /**
     * Move auto-created redirects to trash if they are older than the configured expiration.
     *
     * @return int Number of redirects moved to trash.
     */
    public function expireOldAutoRedirects(): int {
        $options = abj_service('options_repository')->getOptions();
        $days = $this->retentionPolicy->daysFromOptions(is_array($options) ? $options : array(), 'auto_302_expiration_days');
        return $this->cleanupRepository->expireOldAutoRedirects($days, abj_clock()->now());
    }
}
