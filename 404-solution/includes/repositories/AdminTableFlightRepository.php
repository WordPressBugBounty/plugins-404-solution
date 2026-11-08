<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomic, blog-local storage for short-lived admin response handoffs.
 * Reads bypass the object cache, including on sites without a persistent cache.
 * Expiry and content occupy one CAS record; cleanup cannot reap a new owner
 * using an older, separately stored timeout.
 * Policy (identity, ownership, expiry and admission) belongs to AdminTableFlight.
 *
 * @phpstan-type FlightRow array{value:string,record:array<string,mixed>}
 * @phpstan-type FlightRecord array<string,mixed>
 * // allow-no-test-found: tests/AdminTableFlightTest.php exercises this store through the authorized AJAX endpoint against a real database.
 */
final class ABJ_404_Solution_AdminTableFlightRepository {
    public const PREFIX = 'abj404_table_flight_';
    private const GENERATION_OPTION = self::PREFIX . 'generation';

    /** @var ABJ_404_Solution_ExclusiveOptionRow */
    private $rows;

    public function __construct() {
        $this->rows = new ABJ_404_Solution_ExclusiveOptionRow();
    }

    /** Read past the object cache so a refresh observes completed mutations. */
    public function generation(): string {
        $value = $this->rows->valueOf(self::GENERATION_OPTION);
        $this->checkStorage('read its mutation generation');
        return $value === '' ? '' : hash('sha256', $value);
    }

    /** Publish one new generation without deleting or waiting on active owners. */
    public function advanceGeneration(): void {
        $now = abj_clock()->nowFloat();
        $value = wp_json_encode(array('version' => 1, 'kind' => 'admin_table_generation',
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            'token' => ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue('mutation')));
        if (!is_string($value)) { throw $this->unavailable('encode its mutation generation'); }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $prior = $this->rows->valueOf(self::GENERATION_OPTION);
            $this->checkStorage('read its mutation generation');
            $changed = $prior === ''
                ? $this->claimEmptyRow(array('optionName' => self::GENERATION_OPTION, 'value' => $value))
                : $this->rows->replaceValueIfMatches(array('optionName' => self::GENERATION_OPTION,
                    'currentValue' => $prior, 'replacementValue' => $value));
            $this->checkStorage('advance its mutation generation');
            if ($changed) { return; }
        }
        throw $this->unavailable('advance its mutation generation after concurrent writes');
    }

    /** @return FlightRow */
    public function read(string $key): array {
        $value = $this->rows->valueOf($this->name($key));
        $this->checkStorage('read its record');
        $separator = strpos($value, '|');
        $record = $separator === false ? null : json_decode(substr($value, $separator + 1), true);
        return array('value' => $value, 'record' => is_array($record) ? $record : array());
    }

    /**
     * @param FlightRecord $record
     * @return string The exact claimed bytes, or empty when another owner won.
     */
    public function create(string $key, array $record): string {
        $value = $this->encode($record);
        if (!$this->claimEmptyRow(array('optionName' => $this->name($key), 'value' => $value))) {
            return '';
        }
        return $value;
    }

    /**
     * A direct options read returns empty for both absence and blank corrupt
     * bytes. After a refused insert, only replace an EXACTLY empty value; a
     * concurrent owner with nonempty bytes is never overwritten.
     * @param array{optionName:string,value:string} $claim
     */
    private function claimEmptyRow(array $claim): bool {
        if ($this->rows->claim($claim)) { return true; }
        $this->checkStorage('claim its record');
        $replaced = $this->rows->replaceValueIfMatches(array('optionName' => $claim['optionName'],
            'currentValue' => '', 'replacementValue' => $claim['value']));
        $this->checkStorage('replace its empty record');
        return $replaced;
    }

    /**
     * Replace only the record observed by this owner, never a successor's.
     * @param FlightRow $prior
     * @param FlightRecord $record
     * @return string The replacement bytes, or empty after ownership was lost.
     */
    public function replace(string $key, array $prior, array $record): string {
        $value = $this->encode($record);
        if (!$this->rows->replaceValueIfMatches(array(
            'optionName' => $this->name($key), 'currentValue' => $prior['value'],
            'replacementValue' => $value,
        ))) { $this->checkStorage('replace its record'); return ''; }
        return $value;
    }

    /**
     * Reap a bounded batch from the current blog during daily maintenance.
     * The expiry is tested in the DELETE itself: renewing a record atomically
     * removes it from this predicate, without a read/delete ownership race.
     * Neither malformed identifiers nor values without an expiry are evidence
     * for deletion. Only this repository's exact key namespace is eligible.
     * @return array{deleted:int,errors:int}
     */
    public function cleanupExpired(ABJ_404_Solution_DatabaseCore $dbCore, int $now): array {
        global $wpdb;
        $table = $wpdb->options;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw $this->unavailable('resolve its cleanup table');
        }
        $result = $dbCore->queryAndGetResults(
            'DELETE FROM `' . $table . '` WHERE option_name LIKE %s AND option_name REGEXP %s'
            . " AND option_value REGEXP '^[0-9]+[|]'"
            . " AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT 1000",
            array('query_params' => array($wpdb->esc_like(self::PREFIX) . '%',
                '^' . self::PREFIX . '[0-9a-f]{64}$', $now), 'timeout' => 5)
        );
        return array('deleted' => ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? 0, 0, 0),
            'errors' => empty($result['last_error']) ? 0 : 1);
    }

    private function name(string $key): string {
        if (preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw new ABJ_404_Solution_ViewQueryFailureException('Invalid table flight identity.', array(
                'code' => 'TABLE_FLIGHT_IDENTITY', 'hint' => 'Retry with the complete authorized table request.'));
        }
        return self::PREFIX . $key;
    }

    /** @param FlightRecord $record */
    private function encode(array $record): string {
        if (!is_numeric($record['expires_at'] ?? null)) {
            throw $this->unavailable('encode an invalid expiry');
        }
        $value = wp_json_encode($record);
        if (!is_string($value) || $value === '') {
            throw new ABJ_404_Solution_ViewQueryFailureException('Could not encode the table flight.', array(
                'code' => 'TABLE_FLIGHT_ENCODING', 'hint' => 'Check the JSON encoding error: ' . json_last_error_msg()));
        }
        return (int)$record['expires_at'] . '|' . $value;
    }

    /** Storage failure differs from a healthy CAS loss; never retry it blindly. */
    private function checkStorage(string $operation): void {
        global $wpdb;
        if ((string)($wpdb->last_error ?? '') !== '') { throw $this->unavailable($operation); }
    }

    private function unavailable(string $operation): ABJ_404_Solution_ViewQueryFailureException {
        global $wpdb;
        return new ABJ_404_Solution_ViewQueryFailureException('Table flight storage could not ' . $operation . '.', array(
            'code' => 'TABLE_FLIGHT_STORAGE', 'hint' => 'Check the WordPress options table.',
            'database_error' => (string)($wpdb->last_error ?? '')));
    }
}
