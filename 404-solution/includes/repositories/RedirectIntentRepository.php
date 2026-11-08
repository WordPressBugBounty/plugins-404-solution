<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../database/DatabaseTransactionConnection.php';
require_once __DIR__ . '/../database/DatabaseMutationFailedException.php';
require_once __DIR__ . '/../redirects/RedirectSpec.php';

/**
 * Persists redirect intents only inside a caller-owned database transaction.
 *
 * This repository deliberately does not create or repair its table, reconnect
 * the shared handle, write options, or enqueue follow-up work. The upgrade
 * subsystem owns schema installation; the delivery/executor layer owns the
 * transaction boundary and whether an intent is acted upon.
 *
 * @phpstan-import-type NativeResult from ABJ_404_Solution_DatabaseTransactionConnection
 */
final class ABJ_404_Solution_RedirectIntentRepository {

    private const VERSION = 1;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var wpdb|null Current operation's pinned WordPress-compatible handle. */
    private $wpdb = null;

    /** @var ABJ_404_Solution_DatabaseTransactionConnection|null */
    private $connection = null;

    /** @var string|null Current operation's resolved table identifier. */
    private $table = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core) {
        $this->core = $core;
    }

    /**
     * Insert one durable intent, deduplicated by its canonical payload hash.
     *
     * @return string Lowercase SHA-256 intent key.
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    public function enqueue(ABJ_404_Solution_RedirectSpec $spec): string {
        $this->bindOperation();
        $this->requireActiveTransaction();
        $this->requireInnoDbTable();
        $payload = array('version' => self::VERSION, 'spec' => $spec->toArray());
        try {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                'The redirect intent could not be encoded as valid JSON.',
                $exception
            );
        }
        $key = hash('sha256', $encoded);
        $sql = $this->prepare(
            'INSERT INTO `' . $this->table . '` '
                . '(`intent_key`, `version`, `spec_json`, `created_at`, `updated_at`) '
                . 'VALUES (%s, %d, %s, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE `intent_key` = `intent_key`',
            array($key, self::VERSION, $encoded)
        );
        $this->executeStatement($sql);
        return $key;
    }

    /**
     * Return active version-one keys in deterministic creation order.
     *
     * A missing table is a normal shutdown/read condition; schema creation is
     * intentionally not attempted here.
     *
     * @param int $limit
     * @return array<int, string>
     */
    public function pendingKeys(int $limit = 10): array {
        $this->bindOperation();
        if ($limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('Redirect intent limit must be between 1 and 10.');
        }
        if (!$this->tableStatus($present, $engine)) {
            return array();
        }
        $sql = 'SELECT `intent_key` FROM `' . $this->table . '` '
            . 'WHERE `version` = 1 AND `deleted_at` IS NULL '
            . 'ORDER BY `created_at` ASC, `intent_key` ASC LIMIT ' . $limit;
        $result = $this->executeStatement($sql);
        $keys = array();
        foreach ($result['rows'] as $row) {
            if (!is_array($row) || !array_key_exists('intent_key', $row)
                    || !is_string($row['intent_key'])
                    || !preg_match('/^[a-f0-9]{64}$/', $row['intent_key'])) {
                throw $this->corruptRecord('The pending redirect-intent result has an invalid intent_key.');
            }
            $keys[] = $row['intent_key'];
        }
        return $keys;
    }

    /**
     * Read and decode one active intent while retaining the caller's lock.
     *
     * @param string $key
     * @return array{version:int,spec:ABJ_404_Solution_RedirectSpec}|null
     */
    public function readForUpdate(string $key): ?array {
        $this->bindOperation();
        $this->requireActiveTransaction();
        $this->validateKey($key);
        $this->requireInnoDbTable();
        $sql = $this->prepare(
            'SELECT `version`, `spec_json` FROM `' . $this->table . '` '
                . 'WHERE `intent_key` = %s AND `version` = 1 '
                . 'AND `deleted_at` IS NULL FOR UPDATE',
            array($key)
        );
        $result = $this->executeStatement($sql);
        if (count($result['rows']) === 0) {
            return null;
        }
        $row = $result['rows'][0];
        if (!is_array($row) || !isset($row['version'], $row['spec_json'])
                || (int)$row['version'] !== self::VERSION || !is_string($row['spec_json'])) {
            throw $this->corruptRecord('The redirect intent row has an invalid shape.');
        }
        try {
            $decoded = json_decode($row['spec_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw $this->corruptRecord('The redirect intent row contains invalid JSON.', $exception);
        }
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION
                || !isset($decoded['spec']) || !is_array($decoded['spec'])) {
            throw $this->corruptRecord('The redirect intent JSON has an invalid versioned shape.');
        }
        $specFields = array();
        foreach ($decoded['spec'] as $field => $value) {
            if (!is_string($field)) {
                throw $this->corruptRecord('The redirect intent spec contains a non-string field name.');
            }
            $specFields[$field] = $value;
        }
        try {
            $spec = ABJ_404_Solution_RedirectSpec::fromArray($specFields);
        } catch (Throwable $exception) {
            throw $this->corruptRecord('The redirect intent spec failed validation.', $exception);
        }
        return array('version' => self::VERSION, 'spec' => $spec);
    }

    /**
     * Delete one active intent on the same caller-owned transaction.
     *
     * @param string $key
     * @return void
     */
    public function delete(string $key): void {
        $this->bindOperation();
        $this->requireActiveTransaction();
        $this->validateKey($key);
        $this->requireInnoDbTable();
        $sql = $this->prepare(
            'DELETE FROM `' . $this->table . '` '
                . 'WHERE `intent_key` = %s AND `version` = 1 AND `deleted_at` IS NULL',
            array($key)
        );
        $result = $this->executeStatement($sql);
        if ((int)$result['rows_affected'] !== 1) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                'The active redirect intent row was not deleted.'
            );
        }
    }

    /** @return void */
    private function requireActiveTransaction(): void {
        try {
            if (!$this->requireBoundConnection()->hasActiveTransaction()) {
                throw new ABJ_404_Solution_DatabaseMutationFailedException(
                    ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                    'Redirect intents require an active caller-owned transaction.');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof ABJ_404_Solution_DatabaseMutationFailedException) {
                throw $exception;
            }
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'Redirect intents require an active caller-owned transaction.',
                $exception
            );
        }
    }

    /**
     * @param bool &$present
     * @param string|null &$engine
     * @param-out bool $present
     * @param-out string|null $engine
     */
    private function tableStatus(&$present, &$engine): bool {
        $sql = $this->prepare('SHOW TABLE STATUS WHERE `Name` = %s', array($this->table));
        $result = $this->executeStatement($sql);
        $present = count($result['rows']) > 0;
        $engine = null;
        if (!$present) {
            return false;
        }
        $row = $result['rows'][0];
        if (is_array($row)) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            if (isset($normalized['engine']) && is_scalar($normalized['engine'])) {
                $engine = (string)$normalized['engine'];
            }
        }
        if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The redirect-intent table is present but is not InnoDB.'
            );
        }
        return true;
    }

    /** @return void */
    private function requireInnoDbTable(): void {
        $present = false;
        $engine = null;
        if (!$this->tableStatus($present, $engine)) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The redirect-intent table does not exist; schema installation is owned by upgrades.'
            );
        }
    }

    /**
     * Pin the current WordPress handle, resolved table name, and transaction
     * adapter at the start of each public operation. Construction is inert so
     * factories may be created before wpdb or blog context is ready.
     *
     * @return void
     */
    private function bindOperation(): void {
        global $wpdb;
        if (!is_object($wpdb) || !$this->isWordPressEnvelope($wpdb)) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'A current WordPress database handle is required for redirect intents.'
            );
        }
        $table = $this->core->doTableNameReplacements('{wp_abj404_redirect_intents}');
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The redirect-intent table name is not a safe identifier.'
            );
        }
        try {
            $connection = new ABJ_404_Solution_DatabaseTransactionConnection($wpdb);
        } catch (Throwable $exception) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The redirect-intent transaction adapter could not be initialized.',
                $exception
            );
        }
        $this->wpdb = $wpdb;
        $this->connection = $connection;
        $this->table = $table;
    }

    /**
     * @param string $sql
     * @param array<int,mixed> $params
     * @return string
     */
    private function prepare(string $sql, array $params): string {
        $wpdb = $this->wpdb;
        if (!is_object($wpdb) || !is_callable(array($wpdb, 'prepare'))) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The pinned WordPress database handle is unavailable.'
            );
        }
        try {
            $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $params));
        } catch (Throwable $exception) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                'The redirect-intent SQL could not be prepared.',
                $exception
            );
        }
        if (!is_string($prepared) || $prepared === '') {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                'The redirect-intent SQL preparation returned no statement.'
            );
        }
        return $prepared;
    }

    /**
     * Validate the structural WordPress database envelope without requiring a
     * concrete wpdb class, because compatible drop-ins are supported.
     *
     * @phpstan-assert-if-true wpdb $wpdb
     */
    private function isWordPressEnvelope(object $wpdb): bool {
        return is_callable(array($wpdb, 'prepare'))
            && property_exists($wpdb, 'last_query')
            && property_exists($wpdb, 'last_error')
            && property_exists($wpdb, 'last_result')
            && property_exists($wpdb, 'rows_affected')
            && property_exists($wpdb, 'insert_id');
    }

    /**
     * @return NativeResult
     */
    private function executeStatement(string $sql): array {
        try {
            $result = $this->requireBoundConnection()->execute($sql);
        } catch (Throwable $exception) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Redirect-intent SQL failed.',
                $exception
            );
        }
        if (!$result['query_succeeded'] || trim($result['last_error']) !== '') {
            $message = $result['last_error'];
            $cause = $result['cause'];
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                $message !== '' ? $message : 'Redirect-intent SQL returned an unsuccessful result.',
                $cause
            );
        }
        return $result;
    }

    /** @param string $key */
    private function validateKey(string $key): void {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new InvalidArgumentException('Redirect intent key must be 64 lowercase hexadecimal characters.');
        }
    }

    /**
     * @return ABJ_404_Solution_DatabaseTransactionConnection
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    private function requireBoundConnection(): ABJ_404_Solution_DatabaseTransactionConnection {
        if (!$this->connection instanceof ABJ_404_Solution_DatabaseTransactionConnection) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'The redirect-intent transaction adapter is not bound.'
            );
        }
        return $this->connection;
    }

    /**
     * @param string $message
     * @param Throwable|null $cause
     * @return ABJ_404_Solution_DatabaseMutationFailedException
     */
    private function corruptRecord(string $message, ?Throwable $cause = null): ABJ_404_Solution_DatabaseMutationFailedException {
        return new ABJ_404_Solution_DatabaseMutationFailedException(
            ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
            $message,
            $cause
        );
    }
}
