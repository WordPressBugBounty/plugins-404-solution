<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nonblocking single-flight admission for an authorized admin response part.
 * Followers return immediately and resume through a versioned token exchange.
 * Only overlapping requests in the same mutation generation reuse a result.
 * A fresh initial request rebuilds after completion; a mutation also separates
 * a new refresh from any older request still rendering its snapshot.
 * There is no waiter, frontend lock or dependency on persistent object caching.
 */
final class ABJ_404_Solution_AdminTableFlight {
    private const LEASE_SECONDS = 60; // Beyond the 46s LSAPI ceiling, with finite crash recovery.

    /** @var ABJ_404_Solution_AdminTableFlightRepository */
    private $store;

    /** @var array<string,true> Owners whose shutdown still owes a terminal record. */
    private $activeOwners = array();

    /** @var bool A bulk mutation owes one generation at its outer boundary. */
    private static $deferredMutation = false;

    public function __construct() {
        $this->store = new ABJ_404_Solution_AdminTableFlightRepository();
    }

    /** Separate subsequent reads from snapshots admitted before a mutation. */
    public static function invalidate(): void {
        if (ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress) {
            self::$deferredMutation = true;
            return;
        }
        (new ABJ_404_Solution_AdminTableFlightRepository())->advanceGeneration();
        self::$deferredMutation = false;
    }

    /** Publish only when the outermost deferred mutation scope has closed. */
    public static function flushDeferredMutation(): void {
        if (self::$deferredMutation && !ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress) {
            self::invalidate();
        }
    }

    /**
     * The boundary supplies authorized scope and EFFECTIVE renderer options.
     * Nonces and correlation IDs are deliberately absent from this identity.
     * Admission may return a pending token or a completed replay without
     * invoking the builder; only the request that claims ownership builds.
     * @param array<string,mixed> $identity
     * @param array{token:string,started_at:float} $continuation
     * @param callable():array<string,mixed> $build
     * @return array<string,mixed>
     */
    public function admitOrReplayResponse(array $identity, array $continuation, callable $build): array {
        $identity['generation'] = $this->store->generation();
        $encoded = wp_json_encode($identity);
        if (!is_string($encoded)) { throw $this->failure('TABLE_FLIGHT_IDENTITY', 'Could not encode the authorized table identity.'); }
        $key = hash('sha256', $encoded);
        // A bounded CAS race. Unknown storage failures remain fatal; a concrete
        // write refusal must not make an authorized bounded read unavailable.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $prior = $this->store->read($key);
            $record = $prior['record'];
            if ($this->isLive($record)) {
                if ($record['state'] === 'pending') { return $this->pending($record); }
                $completedAt = $record['completed_at'] ?? null;
                if ($continuation['token'] === $record['token']
                    || (is_numeric($completedAt) && $continuation['started_at'] <= (float)$completedAt)) {
                    return $this->completed($record);
                }
            }
            $owner = $this->newOwner();
            try {
                $claimed = $prior['value'] === '' ? $this->store->create($key, $owner)
                    : $this->store->replace($key, $prior, $owner);
            } catch (ABJ_404_Solution_ViewQueryFailureException $error) {
                $cause = $this->coordinationWriteRefusalCause($error);
                if ($cause === null) { throw $error; }
                abj_service('logging')->warnCaught('Table flight coordination write refused; building the bounded response. Database error: '
                    . $cause, $error);
                return $build();
            }
            if ($claimed !== '') { return $this->buildOwned($key, array('value' => $claimed, 'record' => $owner), $build); }
        }
        throw $this->failure('TABLE_FLIGHT_STORAGE', 'Table flight admission is unavailable. Check the WordPress options table.');
    }

    /** @param array<string,mixed> $record */
    private function isLive(array $record): bool {
        return ($record['version'] ?? null) === 1 && ($record['kind'] ?? null) === 'admin_table_flight'
            && is_string($record['token'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $record['token']) === 1
            && is_numeric($record['expires_at'] ?? null) && (float)$record['expires_at'] > abj_clock()->nowFloat()
            && in_array($record['state'] ?? null, array('pending', 'complete', 'failed'), true);
    }

    /** @return array<string,mixed> */
    private function newOwner(): array {
        $now = abj_clock()->nowFloat();
        return array('version' => 1, 'kind' => 'admin_table_flight', 'created_at' => $now,
            'updated_at' => $now, 'expires_at' => (int)ceil($now) + self::LEASE_SECONDS, 'deleted_at' => null,
            'state' => 'pending', 'token' => hash('sha256', ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue('table')));
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function pending(array $record): array {
        return array('tableFlight' => array('version' => 1, 'state' => 'pending',
            'token' => $record['token'], 'retryAfterMs' => 2000));
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function completed(array $record): array {
        if ($record['state'] === 'failed') {
            $error = is_array($record['error'] ?? null) ? $record['error'] : array();
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'owner terminated';
            throw $this->failure('TABLE_FLIGHT_OWNER_FAILED', 'Shared table build failed: '
                . $message, array('owner_error' => $error));
        }
        if (!is_array($record['data'] ?? null)) {
            throw $this->failure('TABLE_FLIGHT_RESULT', 'The completed table flight has no response.');
        }
        $data = array();
        foreach ($record['data'] as $key => $value) {
            if (!is_string($key)) {
                throw $this->failure('TABLE_FLIGHT_RESULT', 'The table flight response has an invalid field name.');
            }
            $data[$key] = $value;
        }
        return $data;
    }

    /**
     * @param array{value:string,record:array<string,mixed>} $owner
     * @param callable():array<string,mixed> $build
     * @return array<string,mixed>
     */
    private function buildOwned(string $key, array $owner, callable $build): array {
        $ownerId = $owner['value'];
        $this->activeOwners[$ownerId] = true;
        register_shutdown_function(function () use ($key, $owner, $ownerId): void {
            if (!isset($this->activeOwners[$ownerId])) { return; }
            $error = error_get_last();
            $this->publishFailure($key, $owner, array('class' => 'owner_terminated',
                'code' => (int)($error['type'] ?? 0), 'message' => (string)($error['message'] ?? 'Table build owner terminated.')));
        });
        try {
            $data = $build();
            $completed = array_merge($owner['record'], array('state' => 'complete', 'data' => $data,
                'updated_at' => abj_clock()->nowFloat(), 'completed_at' => abj_clock()->nowFloat()));
            // A successor can replace expired ownership. Its record must never
            // be overwritten by a slow former owner publishing late results.
            try {
                $this->store->replace($key, $owner, $completed);
            } catch (ABJ_404_Solution_ViewQueryFailureException $error) {
                $cause = $this->coordinationWriteRefusalCause($error);
                if ($cause === null) { throw $error; }
                abj_service('logging')->warnCaught('Table flight completion write refused; returning the bounded response. Database error: '
                    . $cause, $error);
            }
            return $data;
        } catch (Throwable $error) {
            $this->publishFailure($key, $owner, array('class' => get_class($error),
                'code' => $error->getCode(), 'message' => $error->getMessage(),
                'diagnostics' => $error instanceof ABJ_404_Solution_ViewQueryFailureException
                    ? $error->getDiagnostics() : array()));
            throw $error;
        } finally {
            unset($this->activeOwners[$ownerId]);
        }
    }

    /**
     * @param array{value:string,record:array<string,mixed>} $owner
     * @param array{class:string,code:int|string,message:string,diagnostics?:array<string,mixed>} $error
     */
    private function publishFailure(string $key, array $owner, array $error): void {
        try {
            $this->store->replace($key, $owner, array_merge($owner['record'], array('state' => 'failed',
                'error' => $error, 'updated_at' => abj_clock()->nowFloat(), 'completed_at' => abj_clock()->nowFloat())));
        } catch (Throwable $storageError) {
            // Preserve the original build error. The finite lease also bounds
            // recovery when even recording the failure is impossible.
            abj_service('logging')->warnCaught('Could not publish the table flight owner failure: '
                . $error['message'], $storageError);
        }
    }

    /** Only failed coordination writes enter this recovery; reads stay strict. */
    private function coordinationWriteRefusalCause(ABJ_404_Solution_ViewQueryFailureException $error): ?string {
        $diagnostics = $error->getDiagnostics();
        if (($diagnostics['code'] ?? '') !== 'TABLE_FLIGHT_STORAGE') { return null; }
        $databaseError = $diagnostics['database_error'] ?? '';
        if (!is_string($databaseError)) { return null; }
        $hostState = abj_service('db_core')->errorClassifier()->taxonomy()->hostState();
        return $hostState->isReadOnlyError($databaseError) || $hostState->isAccessDeniedError($databaseError)
            ? $databaseError : null;
    }

    /** @param array<string,mixed> $context */
    private function failure(string $code, string $message, array $context = array()): ABJ_404_Solution_ViewQueryFailureException {
        return new ABJ_404_Solution_ViewQueryFailureException($message, array_merge($context,
            array('code' => $code, 'hint' => 'Retry the table request; no PHP worker waits for another owner.')));
    }
}
