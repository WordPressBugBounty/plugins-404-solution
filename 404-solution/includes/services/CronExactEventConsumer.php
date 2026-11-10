<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/DatabaseDriverCall.php';

/**
 * Consumes one exact WordPress cron event and verifies the durable removal.
 *
 * The fallback needs a stricter operation than idempotent unscheduling: a
 * concurrent cron worker removing the event must prevent the fallback batch.
 */
class ABJ_404_Solution_CronExactEventConsumer {

    /** @var ABJ_404_Solution_ScheduledEventInspector */
    private $inspector;

    /** @var ABJ_404_Solution_CronWriteOutcome */
    private $outcome;

    public function __construct(
        ABJ_404_Solution_ScheduledEventInspector $inspector,
        ABJ_404_Solution_CronWriteOutcome $outcome
    ) {
        $this->inspector = $inspector;
        $this->outcome = $outcome;
    }

    /**
     * @param array{timestamp:int,hook:string,args:array<int,mixed>} $request
     * @return bool True only when this call verifies the exact event is gone.
     */
    public function consume(array $request): bool {
        $timestamp = $request['timestamp'];
        $hook = $request['hook'];
        $args = $request['args'];
        try {
            $this->inspector->refreshCronStoreReads();
            $before = $this->inspector->currentEvent($hook, $args);
        } catch (Throwable $e) {
            return $this->reportReadFailure($hook, $timestamp, $e->getMessage());
        }
        if ($before === null || (int)$before['timestamp'] !== (int)$timestamp) {
            return false;
        }
        if (!function_exists('wp_unschedule_event')) {
            $this->outcome->reportUnavailablePrimitive(array(
                'verb' => 'consume', 'hook' => $hook, 'primitive' => 'wp_unschedule_event',
            ));
            return false;
        }

        try {
            $write = $this->consumeNativeSnapshot($request);
        } catch (Throwable $error) {
            return $this->reportReadFailure($hook, $timestamp,
                get_class($error) . ' ' . $error->getCode() . ': ' . $error->getMessage());
        }
        if ($write['status'] === 'failed') { return false; }
        $result = $write['nativeResult'];
        if ($this->outcome->reportsFailure($result)) {
            // Preserve the platform error context, but do not treat an
            // idempotent removal as authority to run the fallback batch.
            $this->outcome->resolveRemoval(array(
                'writeResult' => $result, 'hook' => $hook, 'args' => $args,
                'timestamp' => $timestamp,
            ));
            return false;
        }

        try {
            $this->inspector->refreshCronStoreReads();
            $after = $this->inspector->currentEvent($hook, $args);
        } catch (Throwable $e) {
            return $this->reportReadFailure($hook, $timestamp, $e->getMessage());
        }
        if ($after !== null && (int)$after['timestamp'] === (int)$timestamp) {
            $this->outcome->reportRemovalNotVerified($hook, $timestamp);
            return false;
        }
        if ($write['status'] !== 'confirmed') {
            return $this->reportReadFailure($hook, $timestamp,
                '[ABJ404_CRON_CONSUME_UNVERIFIED] Native cron removal supplied no guarded write witness.');
        }
        return true;
    }

    /**
     * Preserve native WordPress cron filters/actions while making its SQL write
     * conditional on the exact cron row which authorized this consumption.
     * @param array{timestamp:int,hook:string,args:array<int,mixed>} $request
     * @return array{status:string,nativeResult:mixed}
     */
    private function consumeNativeSnapshot(array $request): array {
        global $wpdb;
        $owner = $wpdb;
        $table = is_object($owner) ? (string)($owner->options ?? '') : '';
        $blog = get_current_blog_id();
        if (!is_object($owner) || $table === '') {
            $this->reportReadFailure($request['hook'], $request['timestamp'], 'The cron options connection or table is unavailable.');
            return array('status' => 'failed', 'nativeResult' => false);
        }
        $database = ABJ_404_Solution_DatabaseDriverCall::getCheckedVar($owner, 'SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            $this->reportReadFailure($request['hook'], $request['timestamp'], 'The selected database for the cron option could not be established.');
            return array('status' => 'failed', 'nativeResult' => false);
        }
        $snapshot = self::readNativeCronSnapshot($owner, $table);
        if ($snapshot['status'] === 'failed') {
            $this->reportReadFailure($request['hook'], $request['timestamp'], $snapshot['error']);
            return array('status' => 'failed', 'nativeResult' => false);
        }
        if ($snapshot['status'] !== 'found' || !$this->snapshotHasEvent($snapshot['value'], $request)) {
            return array('status' => 'failed', 'nativeResult' => false);
        }
        $phase = 'pending';
        $enter = static function ($value, $option, $oldValue) use ($snapshot, &$phase) {
            if ($option !== 'cron' || $phase === 'confirmed') { return $value; }
            if ($phase !== 'pending' || serialize($oldValue) !== $snapshot['value']) {
                $phase = 'refused';
                return $oldValue;
            }
            $phase = 'entered';
            return $value;
        };
        // The stored value is compared through the shared guard, which does not depend on
        // the connection charset matching the options table's (a hex byte literal does).
        $snapshotLiteral = ABJ_404_Solution_NativeOptionSql::literal($owner, $snapshot['value']);
        if ($snapshotLiteral === '') {
            $this->reportReadFailure($request['hook'], $request['timestamp'], 'The cron option value could not be expressed as a native SQL literal.');
            return array('status' => 'failed', 'nativeResult' => false);
        }
        $predicate = ABJ_404_Solution_StoredValueMatch::equals('option_value', $snapshotLiteral)
            . ' AND BINARY DATABASE() = 0x' . strtoupper(bin2hex($database));
        $guard = static function ($query) use ($owner, $table, $blog, $predicate, &$phase) {
            global $wpdb;
            if ($phase === 'confirmed' || !is_string($query) || preg_match('/^\s*(UPDATE|INSERT|REPLACE|DELETE)\b/i', $query) !== 1) { return $query; }
            $currentTable = is_object($wpdb) ? (string)($wpdb->options ?? '') : '';
            $tables = array_unique(array_filter(array($table, $currentTable)));
            $targetsOptions = false;
            foreach ($tables as $candidate) {
                if (preg_match('/^\s*(?:UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+`?'
                    . preg_quote($candidate, '/') . '`?(?=\s|\()/i', $query) === 1) { $targetsOptions = true; }
            }
            if (!$targetsOptions || preg_match('/(?:`?option_name`?\s*=\s*\x27cron\x27|\bVALUES\s*\(\s*\x27cron\x27)/i', $query) !== 1) { return $query; }
            $nativeUpdate = '/^\s*UPDATE\s+`?' . preg_quote($table, '/')
                . '`?\s+SET\s+[\s\S]+\s+WHERE\s+`?option_name`?\s*=\s*\x27cron\x27\s*;?\s*$/i';
            if ($phase !== 'entered' || $wpdb !== $owner || $currentTable !== $table
                || get_current_blog_id() !== $blog || preg_match($nativeUpdate, $query) !== 1) {
                $phase = 'refused';
                return 'SELECT 1 FROM DUAL WHERE 0';
            }
            $phase = 'guarded';
            return rtrim(rtrim($query), ';') . ' AND ' . $predicate;
        };
        $confirm = static function () use (&$phase): void {
            // This native action fires only after update_option's SQL changed
            // the row. wp_unschedule_event can itself be short-circuited by a
            // provider returning true even when its attempted SQL changed none.
            if ($phase === 'guarded') { $phase = 'confirmed'; }
        };
        add_filter('pre_update_option', $enter, PHP_INT_MAX, 3);
        add_filter('query', $guard, PHP_INT_MAX, 1);
        add_action('update_option_cron', $confirm, PHP_INT_MIN, 0);
        try {
            $result = wp_unschedule_event($request['timestamp'], $request['hook'], array_values($request['args']), true);
        } finally {
            remove_filter('pre_update_option', $enter, PHP_INT_MAX);
            remove_filter('query', $guard, PHP_INT_MAX);
            remove_action('update_option_cron', $confirm, PHP_INT_MIN);
            $this->inspector->refreshCronStoreReads();
        }
        // Require both the guarded statement and its native acknowledgement;
        // global rows_affected may already reflect an unrelated post-write query.
        return array('status' => $result === true && $phase === 'confirmed' ? 'confirmed' : 'unverified', 'nativeResult' => $result);
    }

    /**
     * Read on the same native connection as the guarded WordPress operation.
     * The explicit query result distinguishes failure with a blank driver error
     * from a successful zero-row read. Cron infrastructure must not re-enter
     * the plugin query facade or its recovery/scheduling dependencies.
     * @param object $owner
     * @return array{status:string,value:string,error:string}
     */
    private static function readNativeCronSnapshot($owner, string $table): array {
        // DAO-bypass-approved: cron reads must use the captured native owner without re-entering plugin recovery or scheduling dependencies.
        $count = ABJ_404_Solution_DatabaseDriverCall::query($owner,
            'SELECT option_value FROM `' . str_replace('`', '``', $table)
            . "` WHERE option_name = 'cron' LIMIT 1");
        $error = $owner->last_error ?? '';
        $rows = $owner->last_result ?? null;
        if (!is_int($count) || $count < 0 || !is_string($error) || $error !== '' || !is_array($rows)) {
            $detail = is_string($error) && $error !== '' ? $error : 'The native query did not confirm a readable cron row.';
            return array('status' => 'failed', 'value' => '', 'error' => '[ABJ404_CRON_NATIVE_READ_FAILED] ' . $detail);
        }
        if ($count === 0 && $rows === array()) {
            return array('status' => 'absent', 'value' => '', 'error' => '');
        }
        $row = $rows[0] ?? null;
        if (is_object($row)) { $row = get_object_vars($row); }
        $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : array();
        if ($count !== 1 || count($rows) !== 1 || !array_key_exists('option_value', $row) || !is_scalar($row['option_value'])) {
            return array('status' => 'failed', 'value' => '', 'error' => '[ABJ404_CRON_NATIVE_READ_FAILED] The native cron row was malformed.');
        }
        return array('status' => 'found', 'value' => (string)$row['option_value'], 'error' => '');
    }

    /** @param array{timestamp:int,hook:string,args:array<int,mixed>} $request */
    private function snapshotHasEvent(string $stored, array $request): bool {
        $cron = @unserialize($stored, array('allowed_classes' => false));
        if (!is_array($cron)) { return false; }
        $bucket = $cron[$request['timestamp']] ?? null;
        if (!is_array($bucket)) { return false; }
        $events = $bucket[$request['hook']] ?? null;
        return is_array($events) && array_key_exists(md5(serialize(array_values($request['args']))), $events);
    }

    private function reportReadFailure(string $hook, int $timestamp, string $detail): bool {
        $this->outcome->reportRemovalReadFailure($hook, $timestamp, $detail);
        return false;
    }
}
