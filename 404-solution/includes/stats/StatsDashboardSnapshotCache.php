<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the dashboard stats snapshot cache and stale-fallback refresh policy.
 * @phpstan-type Snapshot array{version:2,state:string,generation:string,published_generation:string,refreshed_at:int,hash:string,data:array<string,mixed>,thresholds?:array<string,int>,error?:array{code:int|string,message:string}}
 * @phpstan-type Request array{version:2,generation:string,created_at:int,extra_thresholds?:list<int>}
 */
class ABJ_404_Solution_StatsDashboardSnapshotCache {

    /** @var int Legacy TTL constant retained for callers; durable publications do not expire. */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = 86400;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = 30;

    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var callable|null Schedules work without a dependency on its producer. */
    private $requestContinuation;

    /**
     * @param ABJ_404_Solution_Logging $logging
     * @param callable|null $requestContinuation
     */
    public function __construct(
        $logging,
        ?callable $requestContinuation = null
    ) {
        $this->logger = $logging;
        $this->requestContinuation = $requestContinuation;
    }

    /**
     * Durable published payload. Cold reads acknowledge work without aggregating.
     * @param bool $allowStale
     * @return Snapshot
     */
    public function getStatsDashboardSnapshot($allowStale = true): array {
        $cached = $this->published();
        try {
            if ($cached === null || !$allowStale
                || abj_clock()->now() - $cached['refreshed_at'] >= self::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS) {
                $this->refresh(false);
            } else {
                $this->armContinuation();
            }
        } catch (Throwable $e) {
            $this->logger->warnCaught('Statistics refresh could not be armed; keeping the published payload.', $e);
            if ($cached === null) {
                return array('version' => 2, 'state' => 'unavailable', 'generation' => '',
                    'published_generation' => '', 'refreshed_at' => 0, 'hash' => '', 'data' => array(),
                    'error' => array('code' => $e->getCode(), 'message' => $e->getMessage()));
            }
        }
        return $cached ?? $this->pendingPayload($this->pending());
    }

    /**
     * Request a generation; a prior payload never masquerades as its completion.
     * @param bool $force
     * @return Snapshot
     */
    public function refresh($force = false): array {
        $cached = $this->published();
        if (!$force && $this->pending() === null && $cached !== null
            && abj_clock()->now() - $cached['refreshed_at'] < self::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS) {
            return $cached;
        }
        $request = $this->pending();
        if ($request === null) {
            $request = array('version' => 2, 'generation' => substr(hash('sha256',
                ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)abj_clock()->now())), 0, 16),
                'created_at' => abj_clock()->now());
            // Concurrent forced callers join one outstanding generation; nobody can supersede an unbuilt request.
            $encoded = json_encode($request);
            $row = new ABJ_404_Solution_ExclusiveOptionRow();
            if (!is_string($encoded) || !$row->claim(array('optionName' => $this->requestKey(), 'value' => $encoded))) {
                $raw = $row->valueOf($this->requestKey());
                $observed = json_decode($raw, true);
                if (is_string($encoded) && (!is_array($observed) || !$this->validRequest($observed))) {
                    $row->replaceValueIfMatches(array('optionName' => $this->requestKey(),
                        'currentValue' => $raw, 'replacementValue' => $encoded));
                }
                $winner = $this->pending();
                if ($winner === null) { throw new RuntimeException('Could not persist requested statistics generation.', 503); }
                $request = $winner;
            }
        }
        $this->armContinuation();
        return $this->pendingPayload($request);
    }

    /**
     *
     * @return Snapshot|null
     */
    public function published(): ?array {
        $payload = $this->readRecord($this->getCacheKey());
        if ($payload === null) { return null; }
        if (($payload['version'] ?? null) !== 2 || !is_array($payload['data'] ?? null)
            || !is_string($payload['generation'] ?? null) || !is_int($payload['refreshed_at'] ?? null)) {
            $this->logger->warn('Discarding malformed statistics snapshot header; requesting a new generation.');
            return null;
        }
        $data = array();
        foreach ($payload['data'] as $key => $value) {
            if (!is_string($key)) { $this->logger->warn('Discarding statistics snapshot with a non-string data field.'); return null; }
            $data[$key] = $value;
        }
        $generation = $payload['generation'];
        $refreshedAt = $payload['refreshed_at'];
        $hash = is_string($payload['hash'] ?? null) ? $payload['hash'] : '';
        $thresholds = array();
        if (isset($payload['thresholds'])) {
            if (!is_array($payload['thresholds'])) { $this->logger->warn('Discarding statistics snapshot with malformed thresholds.'); return null; }
            $thresholds = array();
            foreach ($payload['thresholds'] as $key => $value) {
                if (!is_string($key) || !is_int($value)) { $this->logger->warn('Discarding statistics snapshot with a malformed period threshold.'); return null; }
                $thresholds[$key] = $value;
            }
            $payload['thresholds'] = $thresholds;
        }
        $metadata = $this->publicationMetadata($payload);
        if ($metadata === null) { return null; }
        return array_merge($metadata, array('version' => 2, 'state' => 'ready', 'generation' => $generation,
            'published_generation' => $generation, 'refreshed_at' => $refreshedAt,
            'hash' => $hash, 'data' => $data, 'thresholds' => $thresholds));
    }

    /**
     * Parse the consistency contract carried by every durable v2 publication.
     * @param array<string,mixed> $record
     * @return array{high_water:int,min_id:int,oldest:int,created_at:int,data_created_at:int,data_generation:string,timezone:string}|null
     */
    private function publicationMetadata(array $record): ?array {
        $numbers = array();
        foreach (array('high_water', 'min_id', 'oldest', 'created_at', 'data_created_at') as $key) {
            $value = ABJ_404_Solution_ExactInteger::read($record[$key] ?? null, 0);
            if ($value === null) { $this->logger->warn('Discarding statistics snapshot with invalid metadata: ' . $key); return null; }
            $numbers[$key] = $value;
        }
        if (!is_string($record['data_generation'] ?? null) || !is_string($record['timezone'] ?? null)) {
            $this->logger->warn('Discarding statistics snapshot with invalid generation/timezone metadata.');
            return null;
        }
        return array('high_water' => $numbers['high_water'], 'min_id' => $numbers['min_id'], 'oldest' => $numbers['oldest'],
            'created_at' => $numbers['created_at'], 'data_created_at' => $numbers['data_created_at'],
            'data_generation' => $record['data_generation'], 'timezone' => $record['timezone']);
    }

    /**
     *
     * @return Request|null
     */
    public function pending(): ?array {
        $request = $this->readRecord($this->requestKey());
        return $request !== null && $this->validRequest($request) ? $request : null;
    }

    /**
     * Atomic publication; failure leaves the previous option value intact.
     * @param array{generation:string,data:array<string,mixed>,thresholds:array<string,int>} $payload
     */
    public function publish(array $payload): void {
        $payload['version'] = 2;
        $payload['state'] = 'ready';
        $payload['published_generation'] = $payload['generation'];
        $payload['refreshed_at'] = abj_clock()->now();
        $payload['hash'] = $this->hash($payload['data']);
        $this->writeRecord($this->getCacheKey(), $payload);
        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        $raw = $row->valueOf($this->requestKey());
        $request = $this->pending();
        if ($request !== null && $request['generation'] === $payload['generation']
            && !array_diff($request['extra_thresholds'] ?? array(), array_values($payload['thresholds']))) {
            if (!$row->releaseIfValueIs(array('optionName' => $this->requestKey(), 'value' => $raw))) {
                $this->logger->warn('Statistics generation published; its pending request changed or could not be cleared.');
            }
        }
    }

    /** Site/blog-specific keys also protect long-lived service instances after switch_to_blog. */
    public function requestKey(): string { return $this->getCacheKey() . '_request'; }

    /** Preserve arbitrary legacy time thresholds without an on-request aggregate. */
    public function requestThreshold(int $timestamp): void {
        $this->refresh(true);
        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = $row->valueOf($this->requestKey());
            $request = json_decode($raw, true);
            if (!is_array($request) || !$this->validRequest($request)) { $this->refresh(true); continue; }
            $extra = $request['extra_thresholds'] ?? array();
            if (in_array($timestamp, $extra, true)) { return; }
            $request['extra_thresholds'] = array_merge($extra, array($timestamp));
            $encoded = json_encode($request);
            if (is_string($encoded) && $row->replaceValueIfMatches(array('optionName' => $this->requestKey(),
                'currentValue' => $raw, 'replacementValue' => $encoded))) { return; }
        }
        throw new RuntimeException('Could not persist statistics threshold request.', 409);
    }

    /**
     *
     * @param Request|null $request
     * @return Snapshot
     */
    private function pendingPayload(?array $request): array {
        $cached = $this->published();
        return array('version' => 2, 'state' => 'pending', 'generation' => $request['generation'] ?? '',
            'published_generation' => $cached['generation'] ?? '',
            'refreshed_at' => $cached['refreshed_at'] ?? 0, 'hash' => $cached['hash'] ?? '',
            'data' => $cached['data'] ?? array());
    }

    private function armContinuation(): void {
        if ($this->pending() === null) { return; }
        if ($this->requestContinuation !== null) { call_user_func($this->requestContinuation); }
    }

    /**
     *
     * @return array<string,mixed>|null
     */
    private function readRecord(string $key): ?array {
        $raw = (new ABJ_404_Solution_ExclusiveOptionRow())->valueOf($key);
        if ($raw === '') { return null; }
        $record = json_decode($raw, true);
        if (!is_array($record)) { $this->logger->warn('Malformed durable statistics record: ' . $key); return null; }
        $normalized = array();
        foreach ($record as $key => $value) {
            if (!is_string($key)) { $this->logger->warn('Statistics record has a non-string field: ' . $key); return null; }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /**
     * Validate persisted requests before scheduling work or merging thresholds.
     * @param array<mixed,mixed> $request
     * @phpstan-assert-if-true Request $request
     */
    private function validRequest(array $request): bool {
        if (($request['version'] ?? null) !== 2 || !is_string($request['generation'] ?? null)
            || !preg_match('/^[a-f0-9]{16}$/D', $request['generation']) || !is_int($request['created_at'] ?? null)) { return false; }
        if (!isset($request['extra_thresholds'])) { return true; }
        if (!is_array($request['extra_thresholds']) || array_values($request['extra_thresholds']) !== $request['extra_thresholds']) { return false; }
        foreach ($request['extra_thresholds'] as $threshold) {
            if (!is_int($threshold) || $threshold < 0) { return false; }
        }
        return true;
    }

    /**
     *
     * @param array<string,mixed> $record
     */
    private function writeRecord(string $key, array $record): void {
        $row = new ABJ_404_Solution_ExclusiveOptionRow();
        $current = $row->valueOf($key);
        $encoded = json_encode($record);
        if (!is_string($encoded)) { throw new UnexpectedValueException('Statistics record cannot be encoded.', 422); }
        if ($encoded === $current) { return; }
        $saved = $current === '' && $row->claim(array('optionName' => $key, 'value' => $encoded));
        if (!$saved) { $saved = $row->replaceValueIfMatches(array('optionName' => $key,
            'currentValue' => $current, 'replacementValue' => $encoded)); }
        if (!$saved) { throw new RuntimeException('Could not persist statistics record: ' . $key, 503); }
    }

    private function getCacheKey(): string {
        $blog = function_exists('get_current_blog_id') ? max(1, (int)get_current_blog_id()) : 1;
        return 'abj404_stats_dashboard_snapshot_v2_' . $blog;
    }

    /**
     *
     * @param array<string,mixed> $data
     */
    private function hash(array $data): string {
        $encoded = json_encode($data);
        if (!is_string($encoded)) { throw new UnexpectedValueException('Statistics payload cannot be encoded.', 422); }
        return md5($encoded);
    }
}
