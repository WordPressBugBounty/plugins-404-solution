<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PluginLogicDefaults.php';
require_once __DIR__ . '/../settings/StorageOptionContracts.php';
require_once __DIR__ . '/../settings/SettingsVersionStamp.php';
require_once __DIR__ . '/../repositories/SiteResourceLock.php';
require_once __DIR__ . '/../repositories/SettingsOptionWriter.php';
require_once __DIR__ . '/../repositories/SettingsBookkeepingWriter.php';
require_once __DIR__ . '/StoredSettingsReconciler.php';

/**
 * Owns the plugin's persistent settings option (`abj404_settings`):
 * read, normalize-for-read, merge defaults, version-upgrade if the
 * DB_VERSION stamp lags ABJ404_VERSION, normalize suggestion template
 * tokens, cache by db-check mode, and write-back through
 * StorageOptionContracts. Hosts the getOptions/updateOptions pair
 * extracted from PluginLogic. Composed by PluginLogic and exposed via
 * `$pluginLogic->optionsResolver()`; the service container key
 * `options_repository` remains as the internal plumbing handle used by
 * auth-time callers (PluginAdminAccessPolicy) that cannot route through
 * PluginLogic without creating a resolution cycle.
 */
class ABJ_404_Solution_PluginLogicOptionsResolver {

    /** @var array<string, mixed>|null */
    private $rawCache = null;

    /** @var array<string, mixed>|null */
    private $resolvedSkipDbCheck = null;

    /** @var array<string, mixed>|null */
    private $resolvedWithDbCheck = null;

    /** @var int|null Site owning the cached option snapshots. */
    private $cachedBlogId = null;

    /** @var array<int, bool> Sites whose first settings snapshot is being built. */
    private $initializingBlogs = array();

    /** @var string Stable code for why the last updateOptionsChecked() refused; empty when it did not. */
    private $lastRefusal = '';

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = new self();
        return self::$instance;
    }

    /** Reset cached instance and in-process caches (test seam). @return void */
    public static function reset(): void {
        if (self::$instance !== null) {
            self::$instance->clearCache();
        }
        self::$instance = null;
    }

    /** Clear in-process option caches. @return void */
    public function clearCache(): void {
        $this->rawCache = null;
        $this->resolvedSkipDbCheck = null;
        $this->resolvedWithDbCheck = null;
    }

    /** Whether callbacks are re-entering this site's unfinished first settings read. */
    public function isInitializingSettings(): bool {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return isset($this->initializingBlogs[$blogId])
            || ABJ_404_Solution_SettingsBookkeepingWriter::hasInitializationContext(
                ABJ_404_Solution_SettingsBookkeepingWriter::siteContextKey());
    }

    /**
     * Return only the delegated plugin-admin option needed by the
     * authorization policy. This intentionally avoids the full getOptions()
     * read path because capability checks can run while plugin_logic is being
     * resolved; the full path performs suggestion-template normalization via
     * PluginLogicSettingsUpdate and would create an auth-time service cycle.
     *
     * @return mixed String, array, or default value accepted by the policy normalizer.
     */
    public function getPluginAdminUsersOption() {
        $optionResult = ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_settings');
        if (!is_array($optionResult)) {
            return ABJ_404_Solution_PluginLogicDefaults::defaults()['plugin_admin_users'];
        }

        $normalizedOptions = ABJ_404_Solution_StorageOptionContracts::normalizeForRead(
            ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
            $optionResult
        );

        if (array_key_exists('plugin_admin_users', $normalizedOptions)) {
            return $normalizedOptions['plugin_admin_users'];
        }

        return ABJ_404_Solution_PluginLogicDefaults::defaults()['plugin_admin_users'];
    }

    /**
     * Raw, side-effect-free read of a single stored setting, bypassing the
     * entire getOptions() pipeline: no normalize-for-read, no schema-fallback
     * logging, no version upgrade, no default merge, no service resolution, no
     * cache mutation.
     *
     * This is the ONLY read path logging infrastructure may use to fetch the
     * few scalars it needs (the debug-file key, the debug-mode flag). Routing
     * those reads through getOptions() created an unbounded logging<->options
     * recursion: a stored value that fails StorageOptionContracts validation
     * logs a warning, and getDebugFilename()/isDebug() then re-read options to
     * find the log file, re-entering normalizeForRead() and re-logging without
     * bound -- the 4.3.0 "broken sites after the latest update" OOM at this
     * file's line ~250. Reading raw keeps logging strictly downstream of the
     * settings repository and can never re-enter it.
     *
     * @param string $key Setting key inside abj404_settings.
     * @param mixed $default Returned when storage is unusable or the key is absent.
     * @return mixed The raw stored value, or $default.
     */
    public function getRawSettingValue(string $key, $default = null) {
        $raw = ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_settings');
        if (is_array($raw) && array_key_exists($key, $raw)) {
            return $raw[$key];
        }
        return $default;
    }

    /**
     * Return the exact uncached settings-row snapshot used by guarded writers.
     * The array shape is intentionally local rather than a new value object:
     * callers must carry the database's found/absent/failed state explicitly.
     *
     * @return array{status:string,value:string}
     */
    public function getRawSettingsSnapshot(): array {
        return ABJ_404_Solution_SettingsOptionWriter::readSnapshot();
    }

    /**
     * Raw, side-effect-free write of a single stored setting, bypassing the
     * storage write contract (no prepareForWrite, no schema validation, no
     * default merge) and, critically, the runtime logger. Used by logging
     * infrastructure to persist its own metadata keys (debug-file key,
     * last-sent-line counter) without re-entering the settings pipeline,
     * and by any caller that needs to update a single bookkeeping key
     * (e.g. EmailDigest's admin_notification_last_sent cadence timestamp).
     * Other keys already in the row are preserved by a direct byte-level
     * compare-and-swap. This path intentionally bypasses native option hooks
     * to stay below the logging recursion boundary. Every existing
     * non-destination bookkeeping key remains supported, including dynamic
     * logging-state keys. It never creates an absent settings row; the full
     * initializer is the sole creator. Destination-bearing settings must use
     * updateOptionsChecked().
     *
     * @param string $key Setting key inside abj404_settings.
     * @param mixed $value Value to store.
     * @return void
     */
    public function setRawSettingValue(string $key, $value): void {
        if (ABJ_404_Solution_SettingsBookkeepingWriter::set($key, $value)) {
            // A later full getOptions() must re-read the row rather than serve
            // a snapshot taken before this raw CAS (or while the write is queued).
            $this->clearCache();
        }
    }

    /**
     * Resolve the current plugin options. With $skip_db_check=true, the
     * DB_VERSION pipeline is skipped (used during the version-upgrade
     * sequence itself and from contexts that must not trigger upgrades).
     *
     * @param bool $skip_db_check
     * @return array<string, mixed>
     */
    public function getOptions(bool $skip_db_check = false): array {
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        if ($this->isInitializingSettings()) {
            // wp_insert_post fires save_post before it returns. Those callbacks
            // may read settings while the first suggestion destination is still
            // being built. Serve an inert snapshot, without caching, upgrading
            // or publishing it over the initializer's eventual complete result.
            return ABJ_404_Solution_PluginLogicDefaults::defaults();
        }
        if ($this->cachedBlogId !== $blogId) {
            $this->clearCache();
            $this->cachedBlogId = $blogId;
        }
        if ($skip_db_check && is_array($this->resolvedSkipDbCheck)) {
            return $this->resolvedSkipDbCheck;
        }
        if (is_array($this->resolvedWithDbCheck)) {
            return $this->resolvedWithDbCheck;
        }

        $reconciler = new ABJ_404_Solution_StoredSettingsReconciler(
            array($this, 'getRawSettingsSnapshot'),
            array($this, 'updateOptionsChecked'),
            array($this, 'clearCache'),
            static fn() => ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_settings')
        );

        if ($this->rawCache === null) {
            $this->initializingBlogs[$blogId] = true;
            try {
                $this->rawCache = $reconciler->initialize();
            } finally {
                unset($this->initializingBlogs[$blogId]);
            }
            if ($this->rawCache === null) {
                // Initialization is deferred: a concurrent initializer owns the
                // final write, or WordPress cannot create the suggestion page
                // yet. Do not persist or cache a fallback that could overwrite
                // the destination the real initialization writes.
                return ABJ_404_Solution_PluginLogicDefaults::defaults();
            }
        }
        $options = $this->rawCache;

        if ($skip_db_check == false) {
            if (ABJ_404_Solution_SettingsVersionStamp::isStale($options)) {
                try {
                    if (abj_service('db_core')->transactionExecutor()->hasActiveTransaction()) {
                        // CREATE/ALTER would implicitly commit the post writer.
                        // Keep this read uncached as "checked" so a later safe
                        // request can run the still-required schema upgrade.
                        return $options;
                    }
                } catch (ABJ_404_Solution_DatabaseMutationFailedException $failure) {
                    $this->warn('Schema upgrade deferred because transaction ownership could not be established: '
                        . $failure->getMessage());
                    return $options;
                }
                $versionUpgrade = abj_service('version_upgrade');
                if (is_object($versionUpgrade) && method_exists($versionUpgrade, 'upgradeIfNeeded')) {
                    $options = ABJ_404_Solution_StoredSettingsReconciler::servedForm($versionUpgrade->upgradeIfNeeded($options));
                } else {
                    $this->warn('version_upgrade service unavailable while reading options; skipped upgrade check.');
                }
            }
        }

        // The suggestion-template repair is pure, so the read path calls it
        // directly instead of resolving plugin_logic (which constructed
        // PluginLogic, DataAccess, LogsRepository, ViewReadService and
        // LogsMetricsReader on every request just to read settings).
        $options = $reconciler->repairTemplates($options);

        if ($skip_db_check) {
            $this->resolvedSkipDbCheck = $options;
        } else {
            $this->resolvedWithDbCheck = $options;
        }

        return $options;
    }

    /**
     * Persist plugin options. Merges defaults, runs the storage write
     * contract, calls update_option, then invalidates the in-process cache.
     *
     * @param array<string, mixed> $options
     * @return void
     */
    public function updateOptions(array $options): void {
        $lock = null;
        $lockHeld = false;
        try {
            $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
            if (!$lock->acquire()) {
                $this->clearCache();
                $this->warn('Settings write deferred because the initialization lock was unavailable.');
                return;
            }
            $lockHeld = true;
            $writeExpected = $this->getRawSettingsSnapshot();
            if (!$this->updateOptionsChecked($options, $writeExpected, $lock)) {
                $this->clearCache();
                $this->warn('Settings write refused by the checked writer (' . $this->lastRefusal . ').');
            }
        } catch (Throwable $e) {
            $this->clearCache();
            $this->warn('Settings write failed before confirmation: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($lockHeld && $lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Why the last updateOptionsChecked() returned false, as a stable code a
     * log line can carry (a writer refusal reads `writer:<code>`).
     *
     * @return string Empty when the last call succeeded (or none ran).
     */
    public function lastRefusalReason(): string {
        return $this->lastRefusal;
    }

    /**
     * @param string $code
     * @return bool Always false, so a refusal site reads `return $this->refusedBecause(...)`.
     */
    private function refusedBecause(string $code): bool {
        $this->clearCache();
        $this->lastRefusal = $code;
        return false;
    }

    /**
     * Checked settings writer for workflows that must report refusal instead
     * of treating the legacy void updateOptions() boundary as success.
     * lastRefusalReason() names the cause of a false return.
     *
     * @param array<string,mixed> $options
     * @param array{status:string,value:string} $expected
     */
    public function updateOptionsChecked(array $options, array $expected,
        ?ABJ_404_Solution_SiteResourceLock $lock = null): bool {
        $this->lastRefusal = '';
        $ownedHere = false;
        if ($lock === null) {
            $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
            if (!$lock->acquire()) {
                return $this->refusedBecause('lock-unavailable');
            }
            $ownedHere = true;
        } elseif (!$lock->isOwned()) {
            return $this->refusedBecause('lock-not-owned');
        }

        try {
            $this->cachedBlogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $prepared = ABJ_404_Solution_OptionPersistenceTracer::traceCurrent(
                'options_normalization',
                static function () use ($options): array {
                    $merged = array_merge(ABJ_404_Solution_PluginLogicDefaults::defaults(), $options);
                    return ABJ_404_Solution_StorageOptionContracts::prepareForWrite(
                        ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
                        $merged
                    );
                }
            );
            if (!(new ABJ_404_Solution_Dest404PageFence())->isFreshlyPublishable($prepared, $expected)) {
                return $this->refusedBecause('dest404-page-fence');
            }
            $writer = new ABJ_404_Solution_SettingsOptionWriter();
            $written = ABJ_404_Solution_OptionPersistenceTracer::traceCurrentStorageWrite(
                function () use ($writer, $prepared, $expected, $lock): bool {
                    return $writer->write($prepared, $expected, $lock);
                }
            );
            if (!$written) {
                return $this->refusedBecause('writer:' . ($writer->refusalReason() !== '' ? $writer->refusalReason() : 'unreported'));
            }
            $confirmedStringKeys = ABJ_404_Solution_StoredSettingsReconciler::confirmedSettings(
                $this->getRawSettingsSnapshot()
            );
            if ($confirmedStringKeys === null) {
                return $this->refusedBecause('confirmed-settings-unreadable');
            }
            ABJ_404_Solution_OptionPersistenceTracer::traceCurrent(
                'repository_cache_refresh',
                function () use ($confirmedStringKeys): void {
                    $this->rawCache = $confirmedStringKeys;
                    $this->resolvedSkipDbCheck = null;
                    $this->resolvedWithDbCheck = null;
                }
            );
            return true;
        } finally {
            if ($ownedHere) {
                $lock->release();
            }
        }
    }

    /**
     * Record a resolver-internal warning. Logs ONLY to the inert PHP error-log
     * sink, never the runtime logger: this class IS the settings-read path, and
     * the runtime logger reads settings to locate its own log file, so warning
     * through it during a read re-enters the read and recurses without bound
     * (the 4.3.0 "broken sites after the latest update" OOM). Hard rule:
     * normalized settings reads must never call runtime logging.
     */
    private function warn(string $message): void {
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
