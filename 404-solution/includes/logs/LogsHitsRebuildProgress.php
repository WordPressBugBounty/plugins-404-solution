<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/core/ExclusiveOptionRow.php';

/**
 * Durable v1 checkpoint storage for the logs-hits rebuild coordinator.
 *
 * This repository owns only checkpoint encoding and compare-and-swap. SQL
 * work, cursor policy, and publication remain with the rebuild coordinator.
 * Cross-file coverage is exercised through the public admin/cron lifecycle in
 * tests/LogsHitsRebuildBudgetTest.php and tests/LogsHitsRebuildProgressTest.php.
 *
 * @phpstan-type State array{version:1,kind:'logs_hits_rebuild',generation:string,created_at:int,updated_at:int,status:'ready'|'running',phase:'prepare'|'direct'|'preaggregate'|'merge'|'publish'|'denormalize'|'cleanup'|'complete',min_log_id:int,max_log_id:int,next_log_id:int,merge_after_id:int,denorm_after_id:int,chunk_size:int,elapsed_time:int|float,step_seconds:int|float,cleanup_index:int,deleted_at:null}
 */
class ABJ_404_Solution_LogsHitsRebuildProgress {

    const OPTION_NAME = 'abj404_logs_hits_rebuild_progress';
    const MAX_RAW_BYTES = 65536;

    /** @var object|null */
    private $logger;

    /** @var string|null Freshly observed row value, including invalid bytes. */
    private $rawValue;

    /** @var bool */
    private $hasRead = false;

    /** @var string */
    private $optionName;
    /** @var callable|null Optional record validator for another bounded rollup. */
    private $validator;

    /**
     * @param object|null $logger
     * @param array{option_name?:string,validator?:callable(array<string,mixed>):bool} $options
     */
    public function __construct($logger = null, array $options = array()) {
        $this->logger = $logger;
        $this->optionName = $options['option_name'] ?? self::OPTION_NAME;
        $this->validator = $options['validator'] ?? null;
    }

    /** @return State|null */
    public function read(): ?array {
        $record = $this->readRecord();
        return $record !== null && $this->isValidState($record) ? $record : null;
    }

    /**
     * Read another rollup's record through its supplied validator.
     * @return array<string,mixed>|null
     */
    public function readRecord(): ?array {
        $this->refreshRawValue();
        $raw = $this->rawValue;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_RAW_BYTES) {
            $this->warnInvalid('checkpoint exceeds the maximum size');
            return null;
        }

        $decoded = $this->decodeState($raw);
        if ($decoded === null || !$this->isValidRecord($decoded)) {
            $this->warnInvalid('checkpoint is malformed');
            return null;
        }
        return $decoded;
    }

    /** @return bool */
    public function hasPending(): bool {
        $state = $this->readRecord();
        return $state !== null && $state['phase'] !== 'complete';
    }

    /**
     * Build an initial state without persisting it.
     *
     * @param array{min_log_id:int,max_log_id:int,chunk_size:int} $snapshot
     * @return State
     */
    public function start(array $snapshot): array {
        $min = $this->requiredInteger($snapshot, 'min_log_id');
        $max = $this->requiredInteger($snapshot, 'max_log_id');
        $chunk = $this->requiredInteger($snapshot, 'chunk_size');
        if ($max < $min || $max === PHP_INT_MAX || $chunk < 1 || $chunk > 100000) {
            throw new InvalidArgumentException('Invalid logs-hits rebuild snapshot bounds.');
        }

        $now = abj_clock()->now();
        $claimSeed = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        $state = array(
            'version' => 1,
            'kind' => 'logs_hits_rebuild',
            'generation' => substr(hash('sha256', $claimSeed), 0, 16),
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'ready',
            'phase' => 'prepare',
            'min_log_id' => $min,
            'max_log_id' => $max,
            'next_log_id' => $min,
            'merge_after_id' => 0,
            'denorm_after_id' => 0,
            'chunk_size' => $chunk,
            'elapsed_time' => 0.0,
            'step_seconds' => 0.0,
            'cleanup_index' => 0,
            'deleted_at' => null,
        );
        if (!$this->isValidState($state)) {
            throw new InvalidArgumentException('Clock produced an invalid logs-hits rebuild timestamp.');
        }
        return $state;
    }

    /**
     * Persist a complete state using the raw value most recently observed.
     *
     * @param State $state
     * @return void
     */
    public function write(array $state): void {
        $this->writeRecord($state);
    }

    /** Persist a validated rollup record without changing the legacy State contract.
     * @param array<string,mixed> $state
     */
    public function writeRecord(array $state): void {
        if (is_int($state['created_at'] ?? null)) {
            $state['updated_at'] = max($state['created_at'], abj_clock()->now());
        }
        if (!$this->isValidRecord($state)) {
            throw new InvalidArgumentException('Invalid logs-hits rebuild checkpoint state.');
        }
        $encoded = json_encode($state, JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_RAW_BYTES) {
            throw new InvalidArgumentException('Logs-hits rebuild checkpoint cannot be encoded safely.');
        }

        if (!$this->hasRead) {
            $this->refreshRawValue();
        }
        $current = $this->rawValue;
        if (!is_string($current)) {
            $current = '';
        }
        if ($current === $encoded) {
            return;
        }

        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        // valueOf() returns '' for both a missing row and an existing empty
        // value. Claim first, then conditionally replace only the exact bytes
        // observed. An empty saved row can recover without overwriting a new
        // generation that won the intervening claim.
        $saved = $current === ''
            && $row->claim(array('optionName' => $this->optionName, 'value' => $encoded));
        if (!$saved) {
            $saved = $row->replaceValueIfMatches(array(
                'optionName' => $this->optionName,
                'currentValue' => $current,
                'replacementValue' => $encoded,
            ));
        }
        if (!$saved) {
            throw new RuntimeException(
                'Logs-hits rebuild checkpoint CAS refused for ' . $this->optionName,
                409
            );
        }
        $this->rawValue = $encoded;
        $this->hasRead = true;
    }

    /** @return void */
    public function clear(): void {
        if (!$this->hasRead) {
            $this->refreshRawValue();
        }
        $current = $this->rawValue;
        if (!is_string($current) || $current === '') {
            return;
        }

        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        if (!$row->releaseIfValueIs(array(
            'optionName' => $this->optionName,
            'value' => $current,
        ))) {
            throw new RuntimeException(
                'Logs-hits rebuild checkpoint release refused for ' . $this->optionName,
                409
            );
        }
        $this->rawValue = '';
        $this->hasRead = true;
    }

    /** @return void */
    private function refreshRawValue(): void {
        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        $this->rawValue = $row->valueOf($this->optionName);
        $this->hasRead = true;
    }

    /** @param string $reason */
    private function warnInvalid(string $reason): void {
        if (is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn('Invalid logs-hits rebuild checkpoint: ' . $reason . '.');
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function requiredInteger(array $snapshot, string $key): int {
        if (!array_key_exists($key, $snapshot) || !is_int($snapshot[$key])) {
            throw new InvalidArgumentException('Missing integer snapshot field: ' . $key);
        }
        return $snapshot[$key];
    }

    /** @return array<string,mixed>|null */
    private function decodeState(string $raw): ?array {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        $state = array();
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $state[$key] = $value;
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @phpstan-assert-if-true State $state
     */
    private function isValidState(array $state): bool {
        return $this->hasRecordShape($state)
            && $this->hasCursorBounds($state)
            && $this->hasTimingFields($state);
    }

    /** @param array<string,mixed> $state */
    private function isValidRecord(array $state): bool {
        return $this->validator === null ? $this->isValidState($state)
            : (bool)call_user_func($this->validator, $state);
    }

    /** @param array<string,mixed> $state */
    private function hasRecordShape(array $state): bool {
        $required = array(
            'version', 'kind', 'generation', 'created_at', 'updated_at', 'status',
            'phase', 'min_log_id', 'max_log_id', 'next_log_id', 'merge_after_id',
            'denorm_after_id', 'chunk_size', 'elapsed_time', 'step_seconds',
            'cleanup_index', 'deleted_at',
        );
        foreach ($required as $key) {
            if (!array_key_exists($key, $state)) {
                return false;
            }
        }
        if ($state['version'] !== 1 || $state['kind'] !== 'logs_hits_rebuild'
            || !is_string($state['generation'])
            || !preg_match('/^[0-9a-f]{16}$/D', $state['generation'])) {
            return false;
        }

        return in_array($state['status'], array('ready', 'running'), true)
            && in_array($state['phase'], array(
                'prepare', 'direct', 'preaggregate', 'merge', 'publish',
                'denormalize', 'cleanup', 'complete',
            ), true);
    }

    /** @param array<string,mixed> $state */
    private function hasCursorBounds(array $state): bool {
        foreach (array('min_log_id', 'max_log_id', 'next_log_id', 'merge_after_id', 'denorm_after_id', 'chunk_size', 'cleanup_index') as $key) {
            if (!is_int($state[$key])) {
                return false;
            }
        }
        if ($state['min_log_id'] < 0 || $state['max_log_id'] === PHP_INT_MAX
            || $state['max_log_id'] < $state['min_log_id']
            || $state['next_log_id'] < $state['min_log_id']
            || ($state['next_log_id'] - $state['max_log_id']) > $state['chunk_size']
            || $state['merge_after_id'] < 0 || $state['denorm_after_id'] < 0
            || $state['chunk_size'] < 1 || $state['chunk_size'] > 100000
            || $state['cleanup_index'] < 0 || $state['cleanup_index'] > 2) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $state */
    private function hasTimingFields(array $state): bool {
        foreach (array('created_at', 'updated_at') as $key) {
            if (!is_int($state[$key]) || $state[$key] <= 0) {
                return false;
            }
        }
        if ($state['updated_at'] < $state['created_at']) {
            return false;
        }
        foreach (array('elapsed_time', 'step_seconds') as $key) {
            if ((!is_int($state[$key]) && !is_float($state[$key]))
                || !is_finite((float)$state[$key]) || $state[$key] < 0) {
                return false;
            }
        }
        return $state['deleted_at'] === null;
    }
}
