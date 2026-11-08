<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence boundary for the resumable abj404_logsv2.min_log_id backfill:
 * the WordPress option that remembers which pass is running (release and
 * table), how far its cursor has advanced, the id ceiling it must reach and
 * whether it has completed.
 *
 * Split out of {@see ABJ_404_Solution_DatabaseUpgradeAddedColumnBackfill}, which
 * decides WHAT each batch does; this class decides what a stored pass state is
 * worth believing. A stored state is only resumable when its release and table
 * match the running ones and its cursor, ceiling and completion flag are
 * mutually consistent; anything else is reported and treated as absent so the
 * caller starts a fresh pass instead of resuming from a corrupt cursor.
 */
final class ABJ_404_Solution_MinLogIdBackfillStateStore {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging $logger Receives the reason a state was not usable.
     */
    public function __construct(ABJ_404_Solution_Logging $logger) {
        $this->logger = $logger;
    }

    /**
     * The stored pass state, or a fresh not-started state for this release when
     * nothing usable is stored.
     *
     * @param string $tableName Resolved log table name.
     * @return array{release: string, table: string, cursor: int, ceiling: int|null, complete: bool}
     */
    public function readOrFresh(string $tableName): array {
        return $this->read($tableName)
            ?? array('release' => $this->release(), 'table' => $tableName,
                'cursor' => 0, 'ceiling' => null, 'complete' => false);
    }

    /**
     * The stored pass state for this release and table, or null when none is
     * stored or the stored value cannot be trusted.
     *
     * @param string $tableName Resolved log table name.
     * @return array{release:string,table:string,cursor:int,ceiling:int|null,complete:bool}|null
     */
    public function read(string $tableName): ?array {
        $empty = array('release' => $this->release(), 'table' => $tableName,
            'cursor' => 0, 'ceiling' => null, 'complete' => false);
        if (!function_exists('get_option')) {
            return null;
        }
        $raw = ABJ_404_Solution_OptionShapeRead::arrayValue($this->optionName($tableName), null);
        if ($raw === null) {
            return null;
        }
        $complete = is_array($raw) ? ($raw['complete'] ?? null) : null;
        if (!is_array($raw) || !is_bool($complete)
                || !$this->hasValidIdentity($raw, $empty, $tableName)) {
            $this->logger->warn('Unreadable min_log_id backfill state for ' . $tableName . '; cannot resume stored pass.');
            return null;
        }
        $cursor = ABJ_404_Solution_ExactInteger::read($raw['cursor'] ?? null, 0);
        $ceiling = array_key_exists('ceiling', $raw) && $raw['ceiling'] !== null
            ? ABJ_404_Solution_ExactInteger::read($raw['ceiling'], 0) : null;
        if ($cursor === null || !$this->hasValidCursor($raw, $cursor, $ceiling)) {
            $this->logger->warn('Unreadable min_log_id backfill cursor for ' . $tableName . '; cannot resume stored pass.');
            return null;
        }
        return array('release' => $empty['release'], 'table' => $tableName,
            'cursor' => $cursor, 'ceiling' => $ceiling, 'complete' => $complete);
    }

    /**
     * Store a pass state and read it back; the pass only advances when the
     * stored value is the one that was written.
     *
     * @param string $tableName Resolved log table name.
     * @param array<string, mixed> $state
     * @return bool Whether the state is durably stored.
     */
    public function persist(string $tableName, array $state): bool {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            $this->logger->warn('Cannot persist min_log_id backfill state for ' . $tableName . '; will retry.');
            return false;
        }
        $option = $this->optionName($tableName);
        update_option($option, $state, false);
        if (ABJ_404_Solution_OptionShapeRead::arrayValue($option, null) === $state) {
            return true;
        }
        $this->logger->warn('Could not verify min_log_id backfill state for ' . $tableName . '; will retry.');
        return false;
    }

    /**
     * Forget a completed pass when the min_log_id column is physically added
     * again in the same plugin release.
     *
     * @param string $tableName Resolved log table name.
     * @return bool Whether the old pass was removed or was already absent.
     */
    public function forget(string $tableName): bool {
        if ($tableName === '' || !function_exists('delete_option') || !function_exists('get_option')) {
            $this->logger->warn('Cannot reset min_log_id backfill state for ' . $tableName . '.');
            return false;
        }
        $option = $this->optionName($tableName);
        delete_option($option);
        $removed = ABJ_404_Solution_OptionShapeRead::arrayValue($option, null) === null;
        if (!$removed) {
            $this->logger->warn('Could not reset min_log_id backfill state for ' . $tableName . '.');
        }
        return $removed;
    }

    private function release(): string {
        return defined('ABJ404_VERSION') && is_string(ABJ404_VERSION) ? ABJ404_VERSION : 'unknown';
    }

    private function optionName(string $tableName): string {
        return 'abj404_min_log_id_backfill_' . md5($this->release() . '|' . $tableName);
    }

    /**
     * @param array<mixed, mixed> $raw
     * @param array{release: string, table: string, cursor: int, ceiling: int|null, complete: bool} $empty
     */
    private function hasValidIdentity(array $raw, array $empty, string $tableName): bool {
        return ($raw['release'] ?? null) === $empty['release']
            && ($raw['table'] ?? null) === $tableName
            && is_bool($raw['complete'] ?? null);
    }

    /** @param array<string, mixed> $raw */
    private function hasValidCursor(array $raw, ?int $cursor, ?int $ceiling): bool {
        if ($cursor === null) {
            return false;
        }
        if (array_key_exists('ceiling', $raw) && $raw['ceiling'] !== null && $ceiling === null) {
            return false;
        }
        if ($ceiling !== null && $cursor > $ceiling) {
            return false;
        }
        return $raw['complete'] !== true || $ceiling !== null;
    }
}
