<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PluginLogicDefaults.php';
require_once __DIR__ . '/../settings/StorageOptionContracts.php';
require_once __DIR__ . '/../policies/SettingsSuggestionPolicy.php';
require_once __DIR__ . '/../policies/SettingsRedirectPolicy.php';
require_once __DIR__ . '/../repositories/SiteResourceLock.php';
require_once __DIR__ . '/../repositories/SettingsBookkeepingWriter.php';

/**
 * Brings the stored settings row (`abj404_settings`) to a complete, normalized
 * state for runtime settings reads: creates
 * the row on a fresh install, normalizes legacy values, fills unset keys from
 * the defaults and repairs suggestion template tokens. Every persisted change
 * goes through the injected checked writer against the exact raw snapshot it
 * was derived from. The value it returns is always the stored row read back and
 * normalized in memory, whether or not the write was accepted: a refused write
 * (a concurrent writer, a read-only database) or a vetoed one leaves a row that
 * may still be legacy-shaped or incomplete, and callers must never receive, or
 * cache as the resolved settings, such a row.
 *
 * Runs inside the settings read, so it warns only through the inert
 * abj404_logPhpFallback() error-log sink and never the runtime logger, which
 * reads settings to locate its own log file and would recurse.
 */
final class ABJ_404_Solution_StoredSettingsReconciler {

    /** @var callable(): array{status:string,value:string} */
    private $readSnapshot;

    /** @var callable(array<string,mixed>,array{status:string,value:string},ABJ_404_Solution_SiteResourceLock): bool */
    private $checkedWriter;

    /** @var callable(): void */
    private $invalidateCache;

    /** @var callable(): mixed */
    private $readCached;

    /**
     * Bind the storage and cache boundaries used during reconciliation.
     *
     * @param callable(): array{status:string,value:string} $readSnapshot Uncached row reader.
     * @param callable(array<string,mixed>,array{status:string,value:string},ABJ_404_Solution_SiteResourceLock): bool $checkedWriter Guarded settings writer.
     * @param callable(): void $invalidateCache Discard runtime snapshots after writes or refusal.
     * @param callable(): mixed $readCached Shape-validated row read through the WordPress option cache.
     */
    public function __construct(
        callable $readSnapshot,
        callable $checkedWriter,
        callable $invalidateCache,
        callable $readCached
    ) {
        $this->readSnapshot = $readSnapshot;
        $this->checkedWriter = $checkedWriter;
        $this->invalidateCache = $invalidateCache;
        $this->readCached = $readCached;
    }

    /**
     * Initialize and normalize the site's persisted settings, preserving legacy 404
     * intent before storage migrations backfill keys. Only an absent option
     * initializes the fresh-install suggestion destination.
     *
     * @return array<string, mixed>|null Null when initialization must be deferred.
     */
    public function initialize(): ?array {
        $converged = $this->convergedOptions();
        if ($converged !== null) {
            return $converged;
        }
        $expected = ($this->readSnapshot)();
        if ($expected['status'] === 'failed') {
            abj404_logPhpFallback('service-resolution-fallback', 'Settings initialization deferred because the raw settings row was unreadable.');
            return null;
        }
        if ($expected['status'] === 'absent' && did_action('init') === 0) {
            // A fresh install creates the suggestion page, and WordPress builds
            // what wp_insert_post() needs ($wp_rewrite, pluggable.php, and for a
            // network-activated plugin the post types) only on its way to init.
            // The include-time read in 404-solution.php runs earlier, so it
            // leaves initialization to the first read after init.
            return null;
        }
        if ($expected['status'] === 'absent') {
            $contextKey = ABJ_404_Solution_SettingsBookkeepingWriter::siteContextKey();
            ABJ_404_Solution_SettingsBookkeepingWriter::beginInitializationContext($contextKey);
            $lock = null;
            $lockHeld = false;
            $published = false;
            $result = null;
            try {
                $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
                if (!$lock->acquire()) { return null; }
                $lockHeld = true;
                // Discard WordPress's cached copies of these settings before reading
                // the winner's committed snapshot inside the critical section.
                ABJ_404_Solution_OptionCacheInvalidator::site('abj404_settings');
                $expected = ($this->readSnapshot)();
                if ($expected['status'] === 'failed') {
                    return null;
                }
                $optionResult = self::decodeSettingsSnapshot($expected);
                if ($expected['status'] === 'absent') {
                    $options = array();
                    ABJ_404_Solution_SettingsRedirectPolicy::initializeSuggestionDestination($options);
                    $options = ABJ_404_Solution_SettingsBookkeepingWriter::applyPendingBookkeeping($contextKey, $options);
                    // Publish a complete snapshot before releasing exclusion;
                    // an empty placeholder lets a second reader backfill over us.
                    $result = $this->mergeDefaults($options, $expected);
                } else {
                    if (is_array($optionResult)) {
                        $optionResult = ABJ_404_Solution_SettingsBookkeepingWriter::applyPendingBookkeeping($contextKey, $optionResult);
                        $result = $this->normalizeStoredOptions($optionResult, $expected);
                    } else {
                        $result = $this->mergeDefaults(array(), $expected);
                    }
                }
                $published = $result !== null && $this->snapshotMatchesOptions($result);
            } finally {
                try {
                    ABJ_404_Solution_SettingsBookkeepingWriter::finishInitializationContext(
                        $contextKey, $published, $this->invalidateCache
                    );
                } finally {
                    if ($lockHeld && $lock !== null) {
                        $lock->release();
                    }
                }
            }
            return $published ? $this->servedOptions() : $result;
        }

        $optionResult = self::decodeSettingsSnapshot($expected);
        return is_array($optionResult)
            ? $this->normalizeStoredOptions($optionResult, $expected)
            : $this->mergeDefaults(array(), $expected);
    }

    /**
     * Repair suggestion template tokens in the stored row. The repair is pure,
     * so the read path calls it directly instead of resolving plugin_logic.
     * When the stored row needs no repair, the options already resolved are
     * returned untouched.
     *
     * @param array<string,mixed> $options The options resolved so far.
     * @return array<string,mixed>
     */
    public function repairTemplates(array $options): array {
        $cached = ($this->readCached)();
        if (is_array($cached)) {
            $cachedSettings = self::namedSettingsOnly($cached);
            if (!ABJ_404_Solution_SettingsSuggestionPolicy::normalizeTemplateOptions($cachedSettings)) {
                return $options;
            }
        }
        $snapshot = ($this->readSnapshot)();
        $rawOptions = self::decodeSettingsSnapshot($snapshot);
        if ($rawOptions === null) {
            return $options;
        }
        // Derive the repair from the normalized form of the exact raw bytes
        // being compared: a row whose normalization could not be persisted
        // (a refused write) may hold a value the storage contract rejects, and
        // writing it back as it stands would throw out of the settings read.
        // If a concurrent/re-entrant writer wins, expose its stored value, never
        // the attempted repair based on stale cached data. The template repair
        // is pure, so it is applied in memory to what was read back: a refused
        // write still serves repaired templates.
        $repaired = self::normalizedView($rawOptions)['options'];
        if (!ABJ_404_Solution_SettingsSuggestionPolicy::normalizeTemplateOptions($repaired)) {
            return $options;
        }
        $this->persistOptionsAtSnapshot($repaired, $snapshot);
        $served = $this->servedOptions();
        if ($served === null) {
            return $options;
        }
        ABJ_404_Solution_SettingsSuggestionPolicy::normalizeTemplateOptions($served);
        return $served;
    }

    /** @param array<string,mixed> $options */
    private function snapshotMatchesOptions(array $options): bool {
        $snapshot = ($this->readSnapshot)();
        $decoded = self::decodeSettingsSnapshot($snapshot);
        if ($snapshot['status'] !== 'found' || !is_array($decoded)) {
            return false;
        }
        return array_key_exists('dest404page', $options)
            && isset($decoded['dest404page'])
            && $decoded['dest404page'] === $options['dest404page'];
    }

    /**
     * Normalize legacy settings and persist missing defaults through an explicit
     * array boundary, after the option reader has established the stored shape.
     *
     * @param array<mixed, mixed> $optionResult Raw settings array; unnamed keys are not settings.
     * @param array{status:string,value:string} $expected Exact raw authority for the array.
     * @return array<string, mixed>|null Null when the row cannot be read back.
     */
    private function normalizeStoredOptions(array $optionResult, array $expected): ?array {
        $storedOptions = $optionResult;
        $normalizedOptions = self::normalizeNamedSettings($storedOptions);
        if (!self::sameSettings($normalizedOptions, $storedOptions)) {
            $this->persistOptionsAtSnapshot($normalizedOptions, $expected);
            $expected = ($this->readSnapshot)();
            $normalizedOptions = $this->servedOptions($expected);
            if ($normalizedOptions === null) {
                return null;
            }
        }
        return $this->mergeDefaults($normalizedOptions, $expected);
    }

    /**
     * The options a request can use as they stand: the cached row is complete
     * and already in its normalized form, so no write is needed and the exact
     * uncached snapshot that guards a write is never read. Null whenever the
     * row is absent, malformed, or would be changed by normalization or by
     * filling a default; the caller then takes the guarded snapshot path.
     *
     * @return array<string,mixed>|null
     */
    private function convergedOptions(): ?array {
        $stored = ($this->readCached)();
        if (!is_array($stored)) {
            return null;
        }
        $view = self::normalizedView($stored);
        return $view['converged'] ? $view['options'] : null;
    }

    /**
     * A stored settings array as it should be used: normalized and completed from
     * the defaults. It has converged when that changes nothing, so no write is
     * needed to make the stored row match it.
     *
     * @param array<mixed, mixed> $stored Raw settings array; unnamed keys are not settings.
     * @return array{options: array<string, mixed>, converged: bool}
     */
    private static function normalizedView(array $stored): array {
        $normalized = self::normalizeNamedSettings($stored);
        $filled = self::fillDefaults($normalized);
        return array(
            'options' => $filled['options'],
            'converged' => !$filled['missing'] && self::sameSettings($normalized, $stored),
        );
    }

    /**
     * Normalize legacy settings. A damaged numeric key must not make the storage
     * contract discard otherwise valid named settings; unknown names are kept
     * for compatibility.
     *
     * @param array<mixed, mixed> $stored Raw settings array; unnamed keys are not settings.
     * @return array<string, mixed>
     */
    private static function normalizeNamedSettings(array $stored): array {
        $named = self::namedSettingsOnly($stored);
        // Capture legacy intent before storage migrations fill newer keys.
        if (!isset($named['dest404_behavior']) || $named['dest404_behavior'] === '') {
            $dest = $named['dest404page'] ?? '';
            $named['dest404_behavior'] = ABJ_404_Solution_SettingsRedirectPolicy::behaviorFromDestination(
                is_string($dest) ? $dest : ''
            );
        }
        return ABJ_404_Solution_StorageOptionContracts::normalizeForRead(
            ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS,
            $named
        );
    }

    /**
     * Fill unset options from the defaults.
     *
     * A stored '' only counts as missing when the default has content to
     * supply; where the default is '' itself the stored row is already
     * complete, and refilling it would rewrite (and lock) on every read.
     *
     * @param array<string, mixed> $options
     * @return array{options: array<string, mixed>, missing: bool}
     */
    private static function fillDefaults(array $options): array {
        $missing = false;
        foreach (ABJ_404_Solution_PluginLogicDefaults::defaults() as $key => $value) {
            if (!isset($options[$key]) || ($options[$key] === '' && $value !== '')) {
                $options[$key] = $value;
                $missing = true;
            }
        }
        return array('options' => $options, 'missing' => $missing);
    }

    /**
     * Whether two settings maps hold the same entries. Key order carries no
     * meaning, and the storage contract and the writer each put keys in their
     * own order, so a row that has already converged would otherwise compare as
     * "changed" on every read and be rewritten (taking the initialization lock)
     * on every request.
     *
     * @param array<mixed, mixed> $left
     * @param array<mixed, mixed> $right
     */
    private static function sameSettings(array $left, array $right): bool {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right)) {
                return false;
            }
            $other = $right[$key];
            if (is_array($value) && is_array($other)) {
                if (!self::sameSettings($value, $other)) { return false; }
            } elseif ($value !== $other) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fill unset options from the defaults and persist when anything was
     * added. Split out of getOptions() unchanged to keep that method under
     * the project's cyclomatic-complexity gate.
     *
     * @param array<string, mixed> $options
     * @param array{status:string,value:string} $expected
     * @return array<string, mixed>|null Null when the row cannot be read back.
     */
    private function mergeDefaults(array $options, array $expected): ?array {
        $filled = self::fillDefaults($options);
        $options = $filled['options'];

        if ($filled['missing']) {
            $this->persistOptionsAtSnapshot($options, $expected);
            return $this->servedOptions();
        }

        return $options;
    }

    /**
     * Attempt one guarded write. The outcome is deliberately not returned: every
     * caller serves the stored row read back afterwards (servedOptions()), so no
     * caller can act on the attempted value instead of the stored one. A refusal
     * is an infrastructure condition the plugin degrades past, so it is logged
     * as a warning and never thrown.
     *
     * @param array<string,mixed> $options
     * @param array{status:string,value:string} $expected
     */
    private function persistOptionsAtSnapshot(array $options, array $expected): void {
        $lock = null;
        $lockHeld = false;
        try {
            $lock = new ABJ_404_Solution_SiteResourceLock('initialization');
            if (!$lock->acquire()) {
                ($this->invalidateCache)();
                return;
            }
            $lockHeld = true;
            if (!($this->checkedWriter)($options, $expected, $lock)) {
                abj404_logPhpFallback('service-resolution-fallback', 'Settings write refused because the stored row, destination or lock ownership changed or the database rejected it; serving the stored settings normalized in memory.');
            }
        } catch (Throwable $e) {
            ($this->invalidateCache)();
            abj404_logPhpFallback('service-resolution-fallback', 'Settings write failed before confirmation: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($lockHeld && $lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * @param array{status:string,value:string} $snapshot
     * @return array<mixed,mixed>|null
     */
    private static function decodeSettingsSnapshot(array $snapshot): ?array {
        if ($snapshot['status'] !== 'found' || $snapshot['value'] === '') {
            return null;
        }
        $decoded = @unserialize($snapshot['value'], array('allowed_classes' => false));
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The settings row read back after an accepted write, as the storage
     * contract accepts it: every key a string and the value passing
     * validateCurrentValue(). Null for anything else (no row, undecodable,
     * a numeric key, a contract violation), so the caller refuses to cache it.
     *
     * @param array{status:string,value:string} $snapshot
     * @return array<string,mixed>|null
     */
    public static function confirmedSettings(array $snapshot): ?array {
        $decoded = self::decodeSettingsSnapshot($snapshot);
        if ($decoded === null) {
            return null;
        }
        $named = self::namedSettingsOnly($decoded);
        if (count($named) !== count($decoded)
            || !empty(ABJ_404_Solution_StorageOptionContracts::validateCurrentValue(
                ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS, $named
            ))) {
            return null;
        }
        return $named;
    }

    /**
     * The settings a caller may use once a guarded write attempt has finished,
     * whatever its outcome: the stored row read back. A row that has converged
     * (after an accepted write) is served exactly as stored. Any other row (after
     * a refused or vetoed write, which leaves it legacy shaped or incomplete) is
     * normalized and completed in memory with the same functions the write path
     * uses. Null when no readable settings row exists, so the caller defers
     * instead of caching a fallback.
     *
     * @param array{status:string,value:string}|null $snapshot Already-read snapshot, or null to read one.
     * @return array<string,mixed>|null
     */
    private function servedOptions(?array $snapshot = null): ?array {
        $decoded = self::decodeSettingsSnapshot($snapshot === null
            ? ($this->readSnapshot)() : $snapshot);
        return $decoded === null ? null : self::servedForm($decoded);
    }

    /**
     * A stored settings array as a request may use it: exactly as stored once it
     * has converged, otherwise normalized and completed in memory. The one
     * normalized view of a stored row. The resolver serves it (also for what a
     * version upgrade hands back, which when its stamp write is refused or
     * deferred is the stored row as it stands: legacy shaped, possibly invalid,
     * possibly lacking defaults), and the version upgrade runs its migrations
     * and its stamp on it, so a row the storage contract rejects is never
     * handed to the checked writer, which throws on it.
     *
     * @param array<mixed, mixed> $stored
     * @return array<string,mixed>
     */
    public static function servedForm(array $stored): array {
        $view = self::normalizedView($stored);
        return $view['converged'] ? self::namedSettingsOnly($stored) : $view['options'];
    }

    /**
     * Keep only the string-keyed entries of a stored settings array: numeric
     * keys are damage, not settings, and the typed consumers take named keys.
     *
     * @param array<mixed, mixed> $stored
     * @return array<string, mixed>
     */
    private static function namedSettingsOnly(array $stored): array {
        $named = array();
        foreach ($stored as $key => $value) {
            if (is_string($key)) { $named[$key] = $value; }
        }
        return $named;
    }
}
