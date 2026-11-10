<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../repositories/LegacySuggestionPageRecovery.php';
require_once __DIR__ . '/../repositories/SiteResourceLock.php';
require_once __DIR__ . '/../repositories/SettingsOptionWriter.php';

/**
 * Owns the plugin version upgrade orchestration: detect a version mismatch,
 * acquire the upgrade synchronizer lock, invalidate opcache for files whose
 * APIs may have changed across versions, run DDL upgrades, run versioned
 * data migrations, stamp DB_VERSION, and refresh the permalink cache.
 *
 * Extracted from PluginLogic so the upgrade pipeline can be exercised and
 * reasoned about as a single service rather than tangled into the
 * options/redirect orchestration class. Composed (not inherited).
 */
class ABJ_404_Solution_PluginLogicVersionUpgrader {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var object */
    private $dbCore;

    /** @var string Log-context id for correlating upgrade messages. */
    private $uniqID;

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param object $dbCore
     */
    public function __construct(
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger,
        object $dbCore
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->dbCore = $dbCore;
        $this->uniqID = uniqid('', true);
    }

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (function_exists('abj_service')) {
            $dao = abj_service('data_access');
            self::$instance = new self(
                self::functions(),
                self::logging(),
                self::dbCoreFromService($dao)
            );
            return self::$instance;
        }
        throw new \RuntimeException(
            'PluginLogicVersionUpgrader::getInstance() requires the service container helper '
            . 'abj_service() to be loaded.'
        );
    }

    /**
     * Synchronized entry point: acquire the lock, run the upgrade action, then
     * refresh the permalink cache.
     *
     * Deliberately does NOT touch opcache. Stale post-upgrade bytecode has to
     * be flushed before the autoloader links anything, which is strictly
     * earlier than this method can ever run -- by the time the booted plugin
     * calls it, a mismatched parent/subclass pair has already fataled. That
     * flush now lives in includes/root-boot/OpcacheUpgradeGuard.php, invoked
     * from 404-solution.php ahead of spl_autoload_register().
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function upgradeIfNeeded(array $options) {
        $syncUtils = self::syncUtils();

        $synchronizedKeyFromUser = 'update_db_version';
        $uniqueID = $syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

        if ($uniqueID == '' || $uniqueID == null) {
            $this->logger->debugMessage('Avoiding infinite loop on database update.');
            return $options;
        }

        $returnValue = $options;

        try {
            $returnValue = $this->runUpgradeAction($options);
        } catch (Throwable $e) {
            $this->logger->errorMessage('Error updating to new version. ', $e instanceof \Exception ? $e : null);
            throw $e;
        } finally {
            $syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
        }

        $permalinkCache = self::permalinkCache();
        $permalinkCache->updatePermalinkCache(1);

        return $returnValue;
    }

    /**
     * The unsynchronized upgrade body: schema creation, cron re-registration,
     * versioned migrations, DB_VERSION stamp. Public so integration tests can
     * exercise migration branches without taking the synchronizer lock.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runUpgradeAction(array $options) {
        $initializationLock = new ABJ_404_Solution_SiteResourceLock('initialization');
        if (!$initializationLock->acquire()) {
            $this->logger->warn('Database upgrade deferred because the initialization lock was unavailable.');
            return $options;
        }
        try {
            $options = array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $options);
            $freshSnapshot = ABJ_404_Solution_SettingsOptionWriter::readSnapshot();
            $freshOptions = $this->decodeFreshSettings($freshSnapshot);
            if ($freshOptions === null) {
                $this->logger->warn('Database upgrade deferred because the settings row was not readable after lock acquisition.');
                return $options;
            }
            // The row read inside the lock is authoritative; migrations run on its
            // normalized view, as on a healthy site (the checked writer throws on a raw invalid row).
            $options = ABJ_404_Solution_StoredSettingsReconciler::servedForm($freshOptions);

        $currentDBVersion = self::currentDbVersionFromOptions($options);
        $this->logger->infoMessage($this->uniqID . ': Updating database version from ' .
            $currentDBVersion . ' to ' . ABJ404_VERSION . ' (begin).');

        ABJ_404_Solution_FileSystemService::deleteDirectoryRecursively(ABJ404_PATH . 'temp/');
        $this->createDatabaseTables();
        $this->refreshUpgradeCrons();

        if (version_compare($currentDBVersion, '1.8.0') < 0) {
            // Refuse to run from cron. Migration is operator-driven (admin upgrade path).
            if (!function_exists('wp_doing_cron') || !wp_doing_cron()) {
                (new ABJ_404_Solution_LegacyLogsTableMigration($this->dbCore, $this->logger))->migrate();
            }
        }
        (new ABJ_404_Solution_SettingsVersionMigrations($this->f, $this->logger))->apply($options, $currentDBVersion);
        $this->repairEscapedExternalDestinations($currentDBVersion);

        $options = $this->stampDbVersion($options);
        $this->logger->infoMessage($this->uniqID . ': Updating database version to ' .
            ABJ404_VERSION . ' (end).');

            return $options;
        } finally {
            $initializationLock->release();
        }
    }

    /**
     * Stamp DB_VERSION on the options array and persist it. Called both as the
     * tail of runUpgradeAction() and directly from activation paths
     * (PluginLogicLifecycle::activateSingleSite, DatabaseUpgradeMultiSite) so
     * a freshly-activated site records the running version without going
     * through the full upgrade action.
     *
     * The returned array is what the stamp could confirm, which is the stored
     * row as it stands when the stamp write is refused or deferred. It is not
     * resolved settings: PluginLogicOptionsResolver normalizes it before
     * caching, and no other caller uses it.
     *
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function stampDbVersion($options = null): array {
        $initializationLock = new ABJ_404_Solution_SiteResourceLock('initialization');
        if (!$initializationLock->acquire()) {
            $this->logger->warn('Database version stamp deferred because the initialization lock was unavailable.');
            return is_array($options) ? $options : array();
        }
        try {
            $repository = abj_service('options_repository');
            $snapshot = ABJ_404_Solution_SettingsOptionWriter::readSnapshot();
            $fresh = $this->decodeFreshSettings($snapshot);
            if ($fresh === null) {
                $this->logger->warn('Database version stamp deferred because the settings row was not readable.');
                return is_array($options) ? $options : array();
            }
            $currentOptions = $fresh;

            // Recovery reads the current saved destination itself after this
            // lock. The caller's options array is never destination authority.
            (new ABJ_404_Solution_LegacySuggestionPageRecovery())->recoverLegacyPages(0);

            $afterRecovery = ABJ_404_Solution_SettingsOptionWriter::readSnapshot();
            $after = $this->decodeFreshSettings($afterRecovery);
            if ($after === null) {
                $this->logger->warn('Database version stamp deferred because recovery left settings unreadable.');
                return $currentOptions;
            }
            $options = ABJ_404_Solution_SettingsVersionStamp::stamp(ABJ_404_Solution_StoredSettingsReconciler::servedForm($after));

            if (!is_callable(array($repository, 'updateOptionsChecked'))
                || !is_callable(array($repository, 'getRawSettingsSnapshot'))) {
                $this->logger->warn('Database version stamp deferred because the checked storage writer is unavailable.');
                return $currentOptions;
            }
            // Bind the data and its compare authority to the same exact raw
            // read. A second snapshot here could belong to a re-entrant save
            // and let this stale stamp overwrite that newer destination.
            if ($repository->updateOptionsChecked($options, $afterRecovery, $initializationLock) !== true) {
                $reason = is_callable(array($repository, 'lastRefusalReason'))
                    ? (string)call_user_func(array($repository, 'lastRefusalReason')) : '';
                $this->logger->warn('Database version stamp refused by the checked settings writer ('
                    . ($reason !== '' ? $reason : 'reason not reported') . ').');
                return $currentOptions;
            }
            ABJ_404_Solution_SchemaHighWaterMark::raiseTo(ABJ404_VERSION);
            return $options;
        } finally {
            $initializationLock->release();
        }
    }

    /**
     * @param array{status:string,value:string} $snapshot
     * @return array<string,mixed>|null
     */
    private function decodeFreshSettings(array $snapshot): ?array {
        if ($snapshot['status'] !== 'found') {
            return null;
        }
        $decoded = @unserialize($snapshot['value'], array('allowed_classes' => false));
        if (!is_array($decoded)) {
            return null;
        }
        $stringKeyed = array();
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $stringKeyed[$key] = $value;
        }
        return $stringKeyed;
    }

    /** @param array<string, mixed> $options */
    private static function currentDbVersionFromOptions(array $options): string {
        if (array_key_exists('DB_VERSION', $options) && is_string($options['DB_VERSION'])) {
            return $options['DB_VERSION'];
        }
        return '(unknown)';
    }

    /** @return void */
    private function createDatabaseTables(): void {
        $upgradesEtc = abj_service('database_upgrades');
        if (!is_object($upgradesEtc)
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'runSelfHealPrologue')
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'createDatabaseTables')) {
            $this->logger->warn('Service "database_upgrades" does not expose upgrade methods.');
        }
        if (!is_object($upgradesEtc)
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'runSelfHealPrologue')
            || !$this->databaseUpgradeServiceCanInvoke($upgradesEtc, 'createDatabaseTables')) {
            throw new \RuntimeException('Service "database_upgrades" does not expose upgrade methods.');
        }
        $upgradesEtc->components()->selfHealUpgrade()->runSelfHealPrologue();
        $upgradesEtc->components()->bootstrapUpgrade()->createDatabaseTables(true);
    }

    /** @return void */
    private function refreshUpgradeCrons(): void {
        // Deactivation clears every hook. An upgrade must retain queued
        // background work, including overdue events and cursor arguments.
        abj_cron_scheduler()->clearRegisteredHooks(array(
            ABJ_404_Solution_CronScheduler::HOOK_CLEANUP,
            ABJ_404_Solution_CronScheduler::HOOK_GSC_FETCH,
            ABJ_404_Solution_CronScheduler::HOOK_DUPLICATE_LEGACY,
            ABJ_404_Solution_CronScheduler::HOOK_REMOVE_DUPLICATES_LEGACY,
            ABJ_404_Solution_CronScheduler::HOOK_DELETE_OLD_REDIRECTS_LEGACY,
            ABJ_404_Solution_CronScheduler::HOOK_REBUILD_VIEW_DONE_LEGACY,
        ));
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }

    /**
     * @param mixed $service
     */
    private function databaseUpgradeServiceCanInvoke($service, string $method): bool {
        return is_object($service)
            && (method_exists($service, $method) || method_exists($service, '__call'));
    }

    /**
     * Report 753: before 4.4.1 the Add/Edit Redirect forms stored an external
     * destination HTML-escaped ("&" as "&#038;"), which broke every query
     * parameter after the first. Decode the rows that older versions wrote. A
     * fresh install has none, and the fixed save path no longer writes any, so
     * the step runs on the upgrade that crosses 4.4.1, and again on a later
     * upgrade only if a failing database stopped it before it finished. A
     * destination typed on 4.4.1 or later is never rewritten otherwise.
     *
     * @return void
     */
    private function repairEscapedExternalDestinations(string $currentDBVersion): void {
        if ($currentDBVersion === '0.0.0') {
            return;
        }
        if (version_compare($currentDBVersion, '4.4.1') >= 0
                && !ABJ_404_Solution_DatabaseUpgradePluginUpdate::escapedExternalDestinationRepairPending()) {
            return;
        }
        abj_service('database_upgrades')->components()->pluginUpdateUpgrade()->decodeEscapedExternalDestinations();
    }

    /** @return ABJ_404_Solution_Functions */
    private static function functions(): ABJ_404_Solution_Functions {
        return self::service('functions', ABJ_404_Solution_Functions::class);
    }

    /** @return ABJ_404_Solution_Logging */
    private static function logging(): ABJ_404_Solution_Logging {
        return self::service('logging', ABJ_404_Solution_Logging::class);
    }

    /** @return ABJ_404_Solution_SynchronizationUtils */
    private static function syncUtils(): ABJ_404_Solution_SynchronizationUtils {
        return self::service('sync_utils', ABJ_404_Solution_SynchronizationUtils::class);
    }

    /** @return ABJ_404_Solution_PermalinkCache */
    private static function permalinkCache(): ABJ_404_Solution_PermalinkCache {
        return self::service('permalink_cache', ABJ_404_Solution_PermalinkCache::class);
    }

    /**
     * @template T of object
     * @param string $name
     * @param class-string<T> $className
     * @return T
     */
    private static function service(string $name, string $className) {
        $service = abj_service($name);
        if (!$service instanceof $className) {
            throw new \RuntimeException('Service "' . $name . '" is not a ' . $className . ' instance.');
        }
        return $service;
    }

    /**
     * @param mixed $dao
     * @return object
     */
    private static function dbCoreFromService($dao): object {
        $dbCore = is_object($dao) && method_exists($dao, 'getDbCore') ? $dao->getDbCore() : $dao;
        if (!is_object($dbCore)) {
            throw new \RuntimeException('PluginLogicVersionUpgrader requires a database service object.');
        }
        return $dbCore;
    }
}
