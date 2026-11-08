<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists bounded AJAX failure counts and advances them after successful heartbeats.
 *
 * The pending and active aggregates let requests continue recording while the
 * HTTP send is in flight. Compare-and-swap protects writes across concurrent
 * requests, and an unsuccessful send leaves its pending window intact.
 *
 * @phpstan-import-type HeartbeatAggregate from ABJ_404_Solution_HeartbeatFailureCounts
 * @phpstan-import-type HeartbeatWirePayload from ABJ_404_Solution_HeartbeatFailureCounts
 */
final class ABJ_404_Solution_HeartbeatWindowAggregate {

    /** The non-autoloaded option holding the active and pending counts. */
    public const OPTION_NAME = 'abj404_heartbeat_failure_counts';

    private const SCHEMA_VERSION = 2;

    /**
     * Record one timeout or stranded request. Unknown action and phase names
     * are reduced to fixed buckets before persistence.
     *
     * @param array<string, mixed> $event Event kind/action and stranded phase/age_ms.
     * @return bool Whether the event was durably added.
     */
    public static function record(array $event): bool {
        $normalized = ABJ_404_Solution_HeartbeatFailureCounts::normalizeEvent($event);
        if ($normalized === null) {
            return false;
        }
        $decodeError = '';
        try {
            $now = self::now();
            $initialStart = self::initialWindowStart($now);
            $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
                'optionName' => self::OPTION_NAME,
                'compute' => static function (?string $current) use ($normalized, $now, $initialStart, &$decodeError): ?string {
                    $state = self::decodeState($current, $now, $initialStart, $decodeError);
                    if ($state === null) {
                        return null;
                    }
                    $activeCounts = self::stateCounts($state, 'active');
                    ABJ_404_Solution_HeartbeatFailureCounts::addEvent($activeCounts, $normalized);
                    $state['active'] = $activeCounts;
                    self::markUpdated($state, $now);
                    return self::encodeState($state);
                },
            ));
            if ($decodeError !== '') {
                self::logFailure('record refused malformed stored state', new UnexpectedValueException($decodeError));
                return false;
            }
            if ($result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED) {
                return true;
            }
            self::logFailure('record could not persist event after compare-and-swap returned ' . $result,
                new RuntimeException('heartbeat failure aggregate write was not stored'));
            return false;
        } catch (Throwable $e) {
            self::logFailure('record failed', $e);
            return false;
        }
    }

    /**
     * Fold active counts into the pending snapshot and return its wire payload
     * with a private token. Call completeHeartbeat() only after send success.
     *
     * A fresh token is created even for an empty window, so a successful
     * zero-count heartbeat advances the persisted window start as well.
     *
     * @return array{payload: array{ajax_failure_counts: HeartbeatWirePayload}, token: int}
     */
    public static function beginHeartbeat(): array {
        $snapshot = null;
        $decodeError = '';
        try {
            $now = self::now();
            $initialStart = self::initialWindowStart($now);
            $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
                'optionName' => self::OPTION_NAME,
                'compute' => static function (?string $current) use (&$snapshot, &$decodeError, $now, $initialStart): ?string {
                    $state = self::decodeState($current, $now, $initialStart, $decodeError);
                    if ($state === null) {
                        return null;
                    }
                    $pendingCounts = ABJ_404_Solution_HeartbeatFailureCounts::merge(
                        self::stateCounts($state, 'pending'), self::stateCounts($state, 'active'));
                    $state['pending'] = $pendingCounts;
                    $state['active'] = ABJ_404_Solution_HeartbeatFailureCounts::empty();
                    $token = self::nextToken(ABJ_404_Solution_ExactInteger::readOr(
                        $state['next_token'] ?? null, 0, 0));
                    $state['next_token'] = $token;
                    $state['pending_token'] = $token;
                    self::markUpdated($state, $now);
                    $snapshot = array(
                        'payload' => array('ajax_failure_counts' =>
                            ABJ_404_Solution_HeartbeatFailureCounts::toWire(
                                $pendingCounts, $now,
                                ABJ_404_Solution_ExactInteger::readOr($state['window_started_at'] ?? null, 0, 0))),
                        'token' => $token,
                    );
                    return self::encodeState($state);
                },
            ));
            if ($decodeError !== '') {
                self::logFailure('heartbeat snapshot refused malformed stored state',
                    new UnexpectedValueException($decodeError));
                return self::emptySnapshot($now);
            }
            if ($result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED) {
                return is_array($snapshot) ? $snapshot : self::emptySnapshot($now);
            }
            self::logFailure('heartbeat snapshot unavailable', new RuntimeException(
                'options compare-and-swap returned ' . $result));
            return self::emptySnapshot($now);
        } catch (Throwable $e) {
            self::logFailure('heartbeat snapshot failed', $e);
            return self::emptySnapshot(self::now());
        }
    }

    /**
     * Advance the window and clear exactly the pending snapshot that was sent.
     * A concurrent heartbeat generation keeps its counts for the next report.
     *
     * @param int $token Token returned by beginHeartbeat().
     * @return bool Whether the matching pending window was advanced.
     */
    public static function completeHeartbeat(int $token): bool {
        if ($token < 1) {
            return false;
        }
        $decodeError = '';
        try {
            $now = self::now();
            $initialStart = self::initialWindowStart($now);
            $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
                'optionName' => self::OPTION_NAME,
                'compute' => static function (?string $current) use ($token, $now, $initialStart, &$decodeError): ?string {
                    $state = self::decodeState($current, $now, $initialStart, $decodeError);
                    if ($state === null || $state['pending_token'] !== $token) {
                        return null;
                    }
                    $state['pending'] = ABJ_404_Solution_HeartbeatFailureCounts::empty();
                    $state['pending_token'] = 0;
                    $state['window_started_at'] = $now;
                    self::markUpdated($state, $now);
                    return self::encodeState($state);
                },
            ));
            if ($decodeError !== '') {
                self::logFailure('heartbeat completion refused malformed stored state',
                    new UnexpectedValueException($decodeError));
                return false;
            }
            return $result === ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED;
        } catch (Throwable $e) {
            self::logFailure('heartbeat completion failed', $e);
            return false;
        }
    }

    /** @return array{payload: array{ajax_failure_counts: HeartbeatWirePayload}, token: int} */
    private static function emptySnapshot(int $now): array {
        return array(
            'payload' => array('ajax_failure_counts' =>
                ABJ_404_Solution_HeartbeatFailureCounts::toWire(
                    ABJ_404_Solution_HeartbeatFailureCounts::empty(), $now, 0)),
            'token' => 0,
        );
    }

    /**
     * @param string|null $raw
     * @param int $now
     * @param int $initialStart
     * @param string $error
     * @return array<mixed, mixed>|null
     */
    private static function decodeState(?string $raw, int $now, int $initialStart, string &$error): ?array {
        if ($raw === null) {
            return self::emptyState($now, $initialStart);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $error = 'stored option is not valid JSON';
            return null;
        }
        if (!self::hasSupportedSchemaVersion($decoded, $error)) {
            return null;
        }
        $fields = self::readStateFields($decoded, $error);
        if ($fields === null) {
            return null;
        }
        // Keep unrecognized fields so a newer compatible writer's additions
        // survive mutations by this version.
        foreach ($fields as $key => $value) {
            $decoded[$key] = $value;
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function emptyState(int $now, int $initialStart): array {
        return array(
            'created_at' => $now,
            'updated_at' => $now,
            'version' => 0,
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'heartbeat_failure_counts',
            'deleted_at' => null,
            'next_token' => 0,
            'pending_token' => 0,
            'window_started_at' => $initialStart,
            'pending' => ABJ_404_Solution_HeartbeatFailureCounts::empty(),
            'active' => ABJ_404_Solution_HeartbeatFailureCounts::empty(),
        );
    }

    /** @param array<mixed, mixed> $decoded */
    private static function hasSupportedSchemaVersion(array $decoded, string &$error): bool {
        $version = ABJ_404_Solution_ExactInteger::read($decoded['schema_version'] ?? null, 1);
        if ($version !== self::SCHEMA_VERSION) {
            $error = 'stored schema_version is ' . ($version === null ? 'unreadable' : $version)
                . ', expected ' . self::SCHEMA_VERSION;
            return false;
        }
        return true;
    }

    /**
     * @param array<mixed, mixed> $decoded
     * @return array<string, mixed>|null
     */
    private static function readStateFields(array $decoded, string &$error): ?array {
        $metadata = self::readRecordMetadata($decoded);
        if ($metadata === null) {
            $error = 'stored metadata, state fields, or count maps are malformed';
            return null;
        }
        $nextToken = ABJ_404_Solution_ExactInteger::read($decoded['next_token'] ?? null, 0);
        $pendingToken = ABJ_404_Solution_ExactInteger::read($decoded['pending_token'] ?? null, 0);
        $windowStart = ABJ_404_Solution_ExactInteger::read($decoded['window_started_at'] ?? null, 1);
        $pending = $decoded['pending'] ?? null;
        $active = $decoded['active'] ?? null;
        if ($nextToken === null || $pendingToken === null || $windowStart === null
                || !is_array($pending) || !ABJ_404_Solution_HeartbeatFailureCounts::isValid($pending)
                || !is_array($active) || !ABJ_404_Solution_HeartbeatFailureCounts::isValid($active)) {
            $error = 'stored metadata, state fields, or count maps are malformed';
            return null;
        }
        return array(
            'created_at' => $metadata['created_at'],
            'updated_at' => $metadata['updated_at'],
            'version' => $metadata['version'],
            'next_token' => $nextToken,
            'pending_token' => $pendingToken,
            'window_started_at' => $windowStart,
            'pending' => $pending,
            'active' => $active,
        );
    }

    /**
     * @param array<mixed, mixed> $decoded
     * @return array{created_at: int, updated_at: int, version: int}|null
     */
    private static function readRecordMetadata(array $decoded): ?array {
        $createdAt = ABJ_404_Solution_ExactInteger::read($decoded['created_at'] ?? null, 1);
        $updatedAt = ABJ_404_Solution_ExactInteger::read($decoded['updated_at'] ?? null, 1);
        $versionNumber = ABJ_404_Solution_ExactInteger::read($decoded['version'] ?? null, 1);
        if ($createdAt === null || $updatedAt === null || $versionNumber === null
                || ($decoded['type'] ?? null) !== 'heartbeat_failure_counts'
                || !array_key_exists('deleted_at', $decoded) || $decoded['deleted_at'] !== null) {
            return null;
        }
        return array('created_at' => $createdAt, 'updated_at' => $updatedAt, 'version' => $versionNumber);
    }

    /**
     * @param array<mixed, mixed> $state
     * @param 'pending'|'active' $key
     * @return HeartbeatAggregate
     */
    private static function stateCounts(array $state, string $key): array {
        return ABJ_404_Solution_HeartbeatFailureCounts::normalize($state[$key] ?? null);
    }

    /** @param array<mixed, mixed> $state */
    private static function markUpdated(array &$state, int $now): void {
        $state['updated_at'] = $now;
        $version = ABJ_404_Solution_ExactInteger::readOr($state['version'] ?? null, 0, 0);
        $state['version'] = $version >= PHP_INT_MAX ? PHP_INT_MAX : $version + 1;
    }

    /** @param array<mixed, mixed> $state */
    private static function encodeState(array $state): ?string {
        $encoded = json_encode($state);
        return is_string($encoded) ? $encoded : null;
    }

    private static function initialWindowStart(int $now): int {
        if (function_exists('get_option')) {
            $installedAt = ABJ_404_Solution_ExactInteger::read(ABJ_404_Solution_OptionShapeRead::scalar('abj404_installed_time', null), 1);
            if ($installedAt !== null) {
                return $installedAt;
            }
        }
        return $now;
    }

    private static function now(): int {
        $clockNow = ABJ_404_Solution_ExactInteger::read(abj_clock()->now(), 1);
        if ($clockNow === null) {
            throw new UnexpectedValueException('injected clock returned an invalid Unix timestamp');
        }
        return $clockNow;
    }

    private static function nextToken(int $current): int {
        return $current >= PHP_INT_MAX ? 1 : $current + 1;
    }

    private static function logFailure(string $context, Throwable $e): void {
        $message = $context . ' (' . get_class($e) . ' ' . $e->getCode() . '): ' . $e->getMessage();
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('heartbeat-failure-aggregate', $message);
            return;
        }
        ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog(
            '404 Solution heartbeat-failure-aggregate: ' . $message);
    }
}
