<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../database/OptionRowCompareAndSwap.php';
require_once __DIR__ . '/SiteResourceLock.php';
require_once __DIR__ . '/../services/OptionCacheInvalidator.php';
require_once __DIR__ . '/../settings/StorageOptionContracts.php';
require_once __DIR__ . '/Dest404PageFence.php';
require_once __DIR__ . '/NativeOptionSql.php';
require_once __DIR__ . '/SettingsWriteTarget.php';

/**
 * Performs the one settings-row write which must retain WordPress's native
 * option hooks while also making the row/lock/state check part of the SQL.
 *
 * This is deliberately specific to abj404_settings. OptionRowCompareAndSwap
 * remains the right primitive for bookkeeping rows, but replacing
 * update_option() for the settings row would silently remove native filters,
 * actions and cache semantics.
 */
final class ABJ_404_Solution_SettingsOptionWriter {

    public const OPTION_NAME = 'abj404_settings';

    /** @var array<string,self> One active settings writer per site/request. */
    private static $activeWriters = array();

    private const PENDING = 'pending';
    private const ENTERED = 'entered';
    private const FILTERED = 'filtered';
    private const ADDING = 'adding';
    private const GUARDED = 'guarded';
    private const REFUSED = 'refused';

    /** @var string Native option-write phase; refusal is terminal for this write. */
    private $phase = self::PENDING;
    /** @var mixed */
    private $finalValue = null;
    /** @var array{status:string,value:string} */
    private $expected = array('status' => 'failed', 'value' => '');
    /** @var ABJ_404_Solution_SiteResourceLock|null */
    private $lock;
    /** @var string */
    private $expectedLiteral = '';
    /** @var string */
    private $optionLiteral = '';
    /** @var string */
    private $activeKey = '';
    /** @var ABJ_404_Solution_SettingsWriteTarget The site and connection captured when write() began. */
    private $target;

    public function __construct() {
        $this->target = ABJ_404_Solution_SettingsWriteTarget::unbound();
    }

    /**
     * @return array{status: 'found'|'absent'|'failed', value:string}
     */
    public static function readSnapshot(): array {
        $result = ABJ_404_Solution_OptionRowCompareAndSwap::readResult(self::OPTION_NAME);
        if (!isset($result['status']) || !is_string($result['status'])) {
            return array('status' => 'failed', 'value' => '');
        }
        if ($result['status'] === 'found' && isset($result['value']) && is_string($result['value'])) {
            return array('status' => 'found', 'value' => $result['value']);
        }
        if ($result['status'] === 'absent') {
            return array('status' => 'absent', 'value' => '');
        }
        return array('status' => 'failed', 'value' => '');
    }

    /**
     * Write the prepared settings value if the supplied raw snapshot and lock
     * still authorize it. The return value is false for every uncertain path.
     *
     * @param array<string,mixed> $options
     * @param array{status:string,value:string} $expected
     */
    public function write(array $options, array $expected, ABJ_404_Solution_SiteResourceLock $lock): bool {
        $this->target = ABJ_404_Solution_SettingsWriteTarget::capture();
        $this->activeKey = self::activeKey();
        if (isset(self::$activeWriters[$this->activeKey])) {
            self::$activeWriters[$this->activeKey]->abort();
            self::$activeWriters[$this->activeKey]->invalidateTargetCache();
            return false;
        }
        self::$activeWriters[$this->activeKey] = $this;
        $this->phase = self::PENDING;
        $this->finalValue = null;
        $this->expected = array(
            'status' => isset($expected['status']) && is_string($expected['status'])
                ? $expected['status'] : 'failed',
            'value' => isset($expected['value']) && is_string($expected['value'])
                ? $expected['value'] : '',
        );
        $this->lock = $lock;

        try {
            if (!$this->target->isCurrent()
                || ABJ_404_Solution_SettingsWriteTarget::safeTable($this->target->optionsTable()) === ''
                || !$lock->isForCurrentSite() || !$lock->isOwned()
                || !$this->expectedIsUsable() || !$this->expectedIsCurrent()) {
                return false;
            }
            $targetWpdb = $this->target->wpdb();
            if ($targetWpdb === null) {
                return false;
            }

            $this->optionLiteral = ABJ_404_Solution_NativeOptionSql::literal($targetWpdb, self::OPTION_NAME);
            $this->expectedLiteral = ABJ_404_Solution_NativeOptionSql::literal($targetWpdb, $this->expected['value']);
            if ($this->optionLiteral === '' || ($this->expected['status'] === 'found' && $this->expectedLiteral === '')) {
                return false;
            }

            $this->installFilters();
            try {
                // The direct raw read above is authoritative. Clearing the WP
                // option cache makes native update_option() obtain that same old
                // value before its filters run.
                ABJ_404_Solution_OptionCacheInvalidator::site(self::OPTION_NAME);
                $result = update_option(self::OPTION_NAME, $options, false);
                // WordPress returns false for a failed/zero-row native query.
                // For true, the query filter witness below proves that this
                // writer actually guarded the intended native statement; a
                // post-hook query must not be mistaken for rows_affected.
                $confirmed = $this->confirmWrite($result);
            } catch (Throwable $e) {
                $this->abort();
                abj404_logPhpFallback('settings-option-write',
                    'Native settings write aborted: ' . $e->getMessage());
                throw $e;
            } finally {
                $this->removeFilters();
            }
        } finally {
            if (isset(self::$activeWriters[$this->activeKey])
                && self::$activeWriters[$this->activeKey] === $this) {
                unset(self::$activeWriters[$this->activeKey]);
            }
        }

        if (!$confirmed) {
            $this->invalidateTargetCache();
            return false;
        }
        return true;
    }

    /** @return bool */
    private function expectedIsCurrent(): bool {
        $current = self::readSnapshot();
        return $current['status'] === $this->expected['status']
            && $current['value'] === $this->expected['value'];
    }

    private function expectedIsUsable(): bool {
        if ($this->expected['status'] === 'absent') {
            return $this->expected['value'] === '';
        }
        return $this->expected['status'] === 'found';
    }

    /**
     * @param mixed $result
     */
    private function confirmWrite($result): bool {
        $lock = $this->lock;
        if ($lock === null || !in_array($this->phase, array(self::FILTERED, self::GUARDED), true)
            || !$this->target->isCurrent() || !$lock->isForCurrentSite() || !$lock->isOwned()) {
            return false;
        }
        $current = self::readSnapshot();
        if ($current['status'] !== 'found') {
            return false;
        }
        $serialized = serialize($this->finalValue);
        if ($current['value'] !== $serialized) {
            return false;
        }
        if ($result === true) {
            // update_option()/add_option() only return true after their native
            // query succeeds. The query witness proves our guard wrapped that
            // exact statement; do not inspect the mutable global row counter,
            // which native post-write hooks may overwrite.
            return $this->phase === self::GUARDED;
        }
        if ($result === false) {
            // The only accepted false is WordPress's genuine no-op: the
            // expected existing bytes already equal the final bytes.
            return $this->expected['status'] === 'found'
                && $this->expected['value'] === $serialized
                && $current['value'] === $this->expected['value'];
        }
        return false;
    }

    /** Mark the active writer so its outer native call fails closed. */
    public function abort(): void {
        $this->phase = self::REFUSED;
    }

    private static function activeKey(): string {
        global $wpdb;
        $wpdbId = is_object($wpdb) ? spl_object_id($wpdb) : 0;
        return (string)$wpdbId . ':' . self::OPTION_NAME;
    }

    private function installFilters(): void {
        add_filter('pre_update_option_' . self::OPTION_NAME,
            array($this, 'enterOptionWrite'), -PHP_INT_MAX, 3);
        add_filter('pre_update_option', array($this, 'captureGenericFinalOptionValue'), PHP_INT_MAX, 3);
        add_filter('query', array($this, 'guardNativeQuery'), PHP_INT_MAX, 1);
        add_action('add_option', array($this, 'observeNativeAdd'), -PHP_INT_MAX, 2);
    }

    private function removeFilters(): void {
        remove_filter('pre_update_option_' . self::OPTION_NAME,
            array($this, 'enterOptionWrite'), -PHP_INT_MAX);
        remove_filter('pre_update_option', array($this, 'captureGenericFinalOptionValue'), PHP_INT_MAX);
        remove_filter('query', array($this, 'guardNativeQuery'), PHP_INT_MAX);
        remove_action('add_option', array($this, 'observeNativeAdd'), -PHP_INT_MAX);
    }

    /**
     * @param mixed $value
     * @param mixed $oldValue
     * @param mixed $option
     * @return mixed
     */
    public function enterOptionWrite($value, $oldValue, $option) {
        if ($this->phase === self::PENDING) {
            $this->phase = self::ENTERED;
            return $value;
        }
        $this->abort();
        throw new RuntimeException('Re-entrant settings option update refused.');
    }

    /**
     * @param mixed $value
     * @param mixed $option
     * @param mixed $oldValue
     * @return mixed
     */
    public function captureGenericFinalOptionValue($value, $option, $oldValue) {
        if ($option === self::OPTION_NAME && $this->phase === self::ENTERED) {
            $this->finalValue = $value;
            $this->phase = self::FILTERED;
        }
        return $value;
    }

    /**
     * Native add_option() exposes this action after sanitization and before
     * its INSERT. It is the real re-entry boundary (there is no
     * pre_add_option_{name} filter in supported WordPress).
     *
     * @param mixed $option
     * @param mixed $value
     */
    public function observeNativeAdd($option, $value): void {
        if ($option !== self::OPTION_NAME) {
            return;
        }
        if ($this->phase === self::FILTERED && $this->expected['status'] === 'absent') {
            $this->finalValue = $value;
            $this->phase = self::ADDING;
            return;
        }
        $this->abort();
        throw new RuntimeException('Re-entrant settings option add refused.');
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function guardNativeQuery($query) {
        if (!is_string($query)) {
            return $query;
        }
        // Read-only SQL must remain transparent even while a native callback
        // temporarily visits another site. Only mutation statements can cross
        // this writer's authorization boundary.
        if (!preg_match('/^\s*(UPDATE|INSERT)\b/i', $query)) {
            return $query;
        }
        if (!$this->target->isCurrent()) {
            global $wpdb;
            $currentTable = is_object($wpdb) && isset($wpdb->options)
                ? ABJ_404_Solution_SettingsWriteTarget::safeTable((string) $wpdb->options) : '';
            $targetTable = ABJ_404_Solution_SettingsWriteTarget::safeTable($this->target->optionsTable());
            $bareTable = '';
            if ($currentTable !== '' && ABJ_404_Solution_NativeOptionSql::usesTable($query, trim($currentTable, '`'))) {
                $bareTable = trim($currentTable, '`');
            } elseif ($targetTable !== '' && ABJ_404_Solution_NativeOptionSql::usesTable($query, trim($targetTable, '`'))) {
                $bareTable = trim($targetTable, '`');
            }
            if ($bareTable !== ''
                && (new ABJ_404_Solution_NativeOptionSql($bareTable, $this->optionLiteral))->writesOption($query)) {
                return $this->refuseStatement();
            }
            return $query;
        }
        $table = ABJ_404_Solution_SettingsWriteTarget::safeTable($this->target->optionsTable());
        if ($table === '' || !preg_match('/^\\s*(UPDATE|INSERT)\\b/i', $query)) {
            return $query;
        }
        $bareTable = trim($table, '`');
        if (!ABJ_404_Solution_NativeOptionSql::usesTable($query, $bareTable)) {
            return $query;
        }
        $sql = new ABJ_404_Solution_NativeOptionSql($bareTable, $this->optionLiteral);

        if (ABJ_404_Solution_NativeOptionSql::isUpdate($query)) {
            $targetWpdb = $this->target->wpdb();
            if ($targetWpdb === null) {
                return $this->refuseStatement();
            }
            return $this->guardNativeUpdate($query, $targetWpdb, $sql);
        }

        return $this->guardNativeInsert($query, $sql);
    }

    /** @param object $wpdb */
    private function guardNativeUpdate(string $query, $wpdb, ABJ_404_Solution_NativeOptionSql $sql): string {
        if (!$sql->updatesOption($query)) {
            return $query;
        }
        if ($this->phase !== self::FILTERED || !$this->finalValueIsAuthorized()) {
            return $this->refuseStatement();
        }
        $valueLiteral = ABJ_404_Solution_NativeOptionSql::literal($wpdb, serialize($this->finalValue));
        if ($valueLiteral === '' || !$sql->isExactUpdate($query, $valueLiteral)) {
            return $this->refuseStatement();
        }
        $lock = $this->lock;
        if ($lock === null) {
            return $this->refuseStatement();
        }
        $predicate = 'BINARY option_value = BINARY ' . $this->expectedLiteral
            . ' AND ' . $lock->ownershipPredicate()
            . $this->destinationFence()->fencePredicate($this->finalValue, $this->expected);
        $this->phase = self::GUARDED;
        return ABJ_404_Solution_NativeOptionSql::withWherePredicate($query, $predicate);
    }

    private function guardNativeInsert(string $query, ABJ_404_Solution_NativeOptionSql $sql): string {
        $wpdb = $this->target->wpdb();
        $valueLiteral = $wpdb !== null
            ? ABJ_404_Solution_NativeOptionSql::literal($wpdb, serialize($this->finalValue)) : '';
        $targetInsert = $sql->isExactInsert($query, $valueLiteral);
        $ownInsert = $targetInsert || $sql->insertsOption($query);
        if (!$ownInsert) {
            return $query;
        }
        if (!$targetInsert) {
            // This is an abj404_settings INSERT, but not the exact native
            // shape we know how to guard. Refuse it without touching foreign
            // options-table SQL or manufacturing an invalid INSERT predicate.
            return $this->refuseStatement();
        }
        if ($this->expected['status'] !== 'absent' || $this->phase !== self::ADDING
            || !$this->finalValueIsAuthorized() || !ABJ_404_Solution_NativeOptionSql::isInsert($query)) {
            return $this->refuseStatement();
        }
        // INSERT ... SELECT preserves native add_option hooks/cache behavior,
        // but makes creation contingent on the same named-lock ownership.
        $rewritten = $sql->insertContingentOn($query, $valueLiteral,
            $this->lock === null ? '1 = 0' : $this->lock->ownershipPredicate(),
            $this->destinationFence()->fencePredicate($this->finalValue, $this->expected));
        if ($rewritten === null) {
            return $this->refuseStatement();
        }
        $this->phase = self::GUARDED;
        return $rewritten;
    }

    /** Abort this write and return a statement that touches nothing. */
    private function refuseStatement(): string {
        $this->abort();
        return 'SELECT 1 FROM DUAL WHERE 0';
    }

    private function destinationFence(): ABJ_404_Solution_Dest404PageFence {
        return new ABJ_404_Solution_Dest404PageFence($this->target);
    }

    private function finalValueIsAuthorized(): bool {
        $value = $this->finalStringKeyedValue();
        return $value !== null
            && empty(ABJ_404_Solution_StorageOptionContracts::validateCurrentValue(
                ABJ_404_Solution_StorageOptionContracts::OPTION_SETTINGS, $value
            ))
            && $this->destinationFence()->isFreshlyPublishable($value, $this->expected);
    }

    /** @return array<string,mixed>|null */
    private function finalStringKeyedValue(): ?array {
        if (!is_array($this->finalValue)) {
            return null;
        }
        $value = array();
        foreach ($this->finalValue as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $value[$key] = $item;
        }
        return $value;
    }

    private function invalidateTargetCache(): void {
        if ($this->target->isCurrent()) {
            ABJ_404_Solution_OptionCacheInvalidator::site(self::OPTION_NAME);
        }
    }
}
