<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded, original-request evidence, independent of expensive debug tracing.
 * This recorder does no storage, host discovery, SQL, or outbound I/O. Its
 * primitive snapshot rides the existing census writes and fatal beacon.
 */
final class ABJ_404_Solution_RequestFailureFrame {
    const VERSION = 1;
    const MAX_QUERY_CHECKPOINTS = 3;
    const STAGES = array('boot', 'auth', 'handler', 'table_redirects', 'table_captured',
        'table_logs', 'redirect_status_counts', 'captured_status_counts', 'paginationLinks',
        'detectOnlySignature', 'table_cache_rows', 'table_cache_count', 'high_impact_count',
        'response_encode', 'ob_drain', 'detach', 'shutdown', 'unknown');
    const TERMINALS = array('unknown', 'in_progress', 'complete', 'error', 'sql_timeout', 'sql_failure');
    const CLEANUP = array('never_reached', 'started', 'complete', 'failed');
    /** @var array<string, mixed>|null */
    private static $frame = null;
    /** @var int */
    private static $queryCount = 0;

    /**
     * Capture once before the existing initial census write.
     *
     * @param array<string, mixed> $input
     */
    public static function start(array $input): void {
        if (self::$frame !== null || ($input['action'] ?? '') !== 'ajaxUpdatePaginationLinks') { return; }
        self::$frame = array(
            'v' => self::VERSION,
            'request_id' => ABJ_404_Solution_AjaxRequestLedger::normalizeId($input['requestId'] ?? '', ''),
            'observed_by' => 'original_request', 'runtime' => self::runtimeIdentity(),
            'endpoint' => self::endpoint($input), 'last_stage' => 'boot',
            'terminal_state' => 'in_progress', 'cleanup' => 'never_reached',
            'trace_status' => 'unarmed', 'authorized' => false, 'storage_status' => 'available',
            'query' => null, 'query_availability' => 'never_reached', 'truncated' => false,
        );
    }

    /**
     * The collector uses the same primitive vocabulary, never an original-request role.
     *
     * @return array<string, mixed>
     */
    public static function runtimeIdentity(): array {
        $config = array();
        foreach (array('memory_limit', 'max_execution_time', 'max_input_time', 'output_buffering',
                'zlib.output_compression', 'opcache.enable', 'opcache.validate_timestamps', 'display_errors') as $key) {
            $raw = function_exists('ini_get') ? ini_get($key) : false;
            $config[$key] = self::iniValue($key, $raw);
        }
        return array('php_version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'config' => $config,
            'source' => array('plugin_version' => defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : null,
                'diagnostic_build_id' => defined('ABJ404_DIAGNOSTIC_BUILD_ID')
                    ? (string)ABJ404_DIAGNOSTIC_BUILD_ID : ABJ_404_Solution_AjaxCheckpointLogger::DIAGNOSTIC_BUILD_ID),
            'pid_status' => ABJ_404_Solution_PhpRuntimeCapabilityAdapter::processId() === null
                ? 'unavailable' : 'available');
    }

    /** @param array<string, mixed> $context */
    public static function authorize(array $context): void {
        if (self::$frame === null) { return; }
        self::$frame['authorized'] = true;
        self::$frame['last_stage'] = 'auth';
        self::$frame['trace_status'] = ABJ_404_Solution_AjaxDiagnosticRequestPolicy::diagnosticRequestId($context) === ''
            ? 'unarmed' : 'armed';
    }

    /** Enter a server-owned phase before its work executes. */
    public static function stage(string $stage): void {
        if (self::$frame !== null) { self::$frame['last_stage'] = self::enum($stage, self::STAGES, 'unknown'); }
    }

    /** Server completion says nothing about delivery or browser parsing. */
    public static function finish(string $status): void {
        if (self::$frame !== null) {
            self::$frame['terminal_state'] = self::enum($status, self::TERMINALS, 'unknown');
        }
    }

    public static function cleanup(string $status): void {
        if (self::$frame !== null) { self::$frame['cleanup'] = self::enum($status, self::CLEANUP, 'failed'); }
    }

    public static function response(int $status): void {
        if (self::$frame !== null) {
            self::$frame['server_observation'] = array('observed_by' => 'server',
                'status' => max(0, min(599, $status)), 'body_class' => 'json', 'parser' => 'not_observed');
        }
    }

    public static function storageFailed(): void {
        if (self::$frame !== null) {
            self::$frame['storage_status'] = 'write_failed';
            self::snapshot();
        }
    }

    /**
     * Observe only a database-owned safe identity in an authorized table stage.
     *
     * @param array<string, mixed> $identity
     * @return array{sql_id: string, timeout_seconds: int|null, state: string, deadline_mode: string}|null
     */
    public static function beginQuery(array $identity): ?array {
        $activeQuery = is_array(self::$frame['query'] ?? null) ? self::$frame['query'] : array();
        if (self::$frame === null || self::$frame['authorized'] !== true
                || in_array($activeQuery['state'] ?? '', array('sql_failure', 'sql_timeout'), true)) { return null; }
        $query = self::query($identity);
        if ($query === null || $query['timeout_seconds'] === null) { return null; }
        self::$frame['query'] = $query;
        self::$frame['query_availability'] = 'available';
        self::$queryCount++;
        if (self::$queryCount > self::MAX_QUERY_CHECKPOINTS) { self::$frame['truncated'] = true; }
        return $query;
    }

    public static function shouldCheckpointQuery(): bool { return self::$queryCount <= self::MAX_QUERY_CHECKPOINTS; }

    public static function acceptsQuery(): bool {
        $activeQuery = is_array(self::$frame['query'] ?? null) ? self::$frame['query'] : array();
        return self::$frame !== null && self::$frame['authorized'] === true
            && !in_array($activeQuery['state'] ?? '', array('sql_failure', 'sql_timeout'), true);
    }

    /**
     * Preserve the first driver failure before recovery/metadata can replace it.
     *
     * @param array{sql_id: string, timeout_seconds: int|null, state: string, deadline_mode: string}|null $query
     * @param array<string, mixed> $result
     */
    public static function endQuery(?array $query, array $result, bool $threw = false): bool {
        if ($query === null || self::$frame === null) { return false; }
        $activeQuery = is_array(self::$frame['query'] ?? null) ? self::$frame['query'] : array();
        if (in_array($activeQuery['state'] ?? '', array('sql_failure', 'sql_timeout'), true)) {
            return false;
        }
        $failed = $threw || !empty($result['timed_out'])
            || (is_string($result['last_error'] ?? null) && $result['last_error'] !== '');
        $query['state'] = $failed ? (!empty($result['timed_out']) ? 'sql_timeout' : 'sql_failure') : 'complete';
        self::$frame['query'] = $query;
        return $failed;
    }

    /**
     * Normalized primitive snapshot; reading never captures collector state.
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array {
        if (self::$frame === null) { return null; }
        $frame = self::$frame;
        $frame['elapsed_ms'] = ABJ_404_Solution_RequestPhaseTimeline::elapsedSinceBootMs();
        $snapshot = self::normalize($frame);
        // The fatal handler reads primitives prepared while memory is healthy;
        // it never calls the runtime collector after an OOM or config change.
        $GLOBALS['abj404_request_failure_frame'] = $snapshot;
        return $snapshot;
    }

    /**
     * Shared persisted-boundary allowlist. Unknown keys are ignored, never forwarded.
     *
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    public static function normalize($raw): ?array {
        if (!is_array($raw) || ($raw['v'] ?? null) !== self::VERSION
                || ($raw['observed_by'] ?? '') !== 'original_request') { return null; }
        $rid = ABJ_404_Solution_AjaxRequestLedger::normalizeId($raw['request_id'] ?? '', '');
        if ($rid === '') { return null; }
        $runtime = is_array($raw['runtime'] ?? null) ? $raw['runtime'] : array();
        return self::normalizeFrame($raw, $rid, self::normalizeRuntime($runtime));
    }

    /**
     * Normalize runtime values separately from the request lifecycle fields.
     *
     * @param array<mixed, mixed> $runtime
     * @return array<string, mixed>
     */
    private static function normalizeRuntime(array $runtime): array {
        $source = is_array($runtime['source'] ?? null) ? $runtime['source'] : array();
        $ini = is_array($runtime['config'] ?? null) ? $runtime['config'] : array();
        $config = array();
        foreach (array('memory_limit', 'max_execution_time', 'max_input_time', 'output_buffering',
                'zlib.output_compression', 'opcache.enable', 'opcache.validate_timestamps', 'display_errors') as $key) {
            $config[$key] = is_bool($ini[$key] ?? null)
                ? $ini[$key] : self::integer($ini[$key] ?? null, -1, 1099511627776);
        }
        return array(
            'php_version' => self::version($runtime['php_version'] ?? null),
            'sapi' => self::enum($runtime['sapi'] ?? null, array('apache2handler', 'cgi-fcgi', 'fpm-fcgi',
                'litespeed', 'cli', 'cli-server', 'phpdbg', 'embed'), 'unavailable'),
            'config' => $config,
            'source' => array(
                'plugin_version' => self::version($source['plugin_version'] ?? null),
                'diagnostic_build_id' => is_string($source['diagnostic_build_id'] ?? null)
                    && preg_match('/^[a-f0-9]{40}$/D', $source['diagnostic_build_id']) === 1
                    ? $source['diagnostic_build_id'] : null,
            ),
            'pid_status' => self::enum($runtime['pid_status'] ?? null,
                array('available', 'unavailable'), 'unavailable'),
        );
    }

    /**
     * @param array<mixed, mixed> $raw
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private static function normalizeFrame(array $raw, string $rid, array $runtime): array {
        $runtimeSource = is_array($runtime['source'] ?? null) ? $runtime['source'] : array();
        $frame = array(
            'v' => self::VERSION,
            'request_id' => $rid,
            'observed_by' => 'original_request',
            'runtime' => $runtime,
            'endpoint' => self::endpoint(is_array($raw['endpoint'] ?? null) ? $raw['endpoint'] : array()),
            'last_stage' => self::enum($raw['last_stage'] ?? null, self::STAGES, 'unknown'),
            'terminal_state' => self::enum($raw['terminal_state'] ?? null, self::TERMINALS, 'unknown'),
            'cleanup' => self::enum($raw['cleanup'] ?? null, self::CLEANUP, 'never_reached'),
            'authorized' => ($raw['authorized'] ?? false) === true,
            'trace_status' => self::enum($raw['trace_status'] ?? null, array('armed', 'unarmed'), 'unarmed'),
            'storage_status' => self::enum($raw['storage_status'] ?? null,
                array('available', 'write_failed'), 'write_failed'),
            'elapsed_ms' => self::integer($raw['elapsed_ms'] ?? null, 0, 86400000),
            'query' => self::query($raw['query'] ?? null),
            'query_availability' => self::enum($raw['query_availability'] ?? null,
                array('available', 'never_reached', 'truncated'), 'never_reached'),
            'truncated' => ($raw['truncated'] ?? false) === true,
            'availability' => array(
                'proxy_budget' => 'unavailable', 'fpm_budget' => 'unavailable',
                'lve_budget' => 'unavailable',
                'runtime' => $runtime['php_version'] === null ? 'unavailable' : 'available',
                'source' => ($runtimeSource['diagnostic_build_id'] ?? null) === null ? 'unavailable' : 'available',
            ),
        );
        return array_replace($frame, self::normalizeServerObservation($raw['server_observation'] ?? null));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private static function normalizeServerObservation($raw): array {
        if (!is_array($raw)) {
            return array();
        }
        return array('server_observation' => array(
            'observed_by' => 'server',
            'status' => self::integer($raw['status'] ?? null, 0, 599),
            'body_class' => 'json',
            'parser' => 'not_observed',
        ));
    }

    /**
     * Allowlisted client observations, with no body, URL, cookie, token, or error text.
     *
     * @param array<mixed, mixed> $raw
     * @return array<string, mixed>|null
     */
    public static function clientObservation(array $raw): ?array {
        $outcome = self::enum($raw['outcome'] ?? null, array('timeout', 'error', 'abort', 'parsererror', 'network'), 'unknown');
        $jq = self::enum($raw['jq'] ?? null, array('timeout', 'error', 'abort', 'parsererror'), 'unknown');
        if ($outcome === 'unknown' && $jq !== 'parsererror') { return null; }
        $body = is_array($raw['bodyShape'] ?? null) ? $raw['bodyShape'] : array();
        return array('observed_by' => 'client', 'outcome' => $outcome, 'jq' => $jq,
            'status' => self::integer($raw['status'] ?? null, 0, 599),
            'elapsed_ms' => self::integer($raw['durationMs'] ?? $raw['elapsed_ms'] ?? null, 0, 86400000),
            'body_class' => self::enum($body['kind'] ?? $body['classification'] ?? $raw['body_class'] ?? null,
                array('html', 'empty', 'text', 'json', 'non_json', 'json_object', 'json_array'), 'unknown'),
            'parser' => $jq === 'parsererror' || $outcome === 'parsererror' ? 'failed' : 'not_observed');
    }

    /** Clear per-request memory for persistent interpreters and test lifecycles. */
    public static function reset(): void {
        self::$frame = null; self::$queryCount = 0;
        unset($GLOBALS['abj404_request_failure_frame']);
    }

    /**
     * @param mixed $value
     * @param array<int, string> $values
     */
    private static function enum($value, array $values, string $fallback): string {
        return is_string($value) && in_array($value, $values, true) ? $value : $fallback;
    }

    /** @param mixed $value */
    private static function integer($value, int $min, int $max): ?int {
        $number = ABJ_404_Solution_ExactInteger::read($value, $min);
        return $number !== null && $number <= $max ? $number : null;
    }

    /** @param mixed $value */
    private static function version($value): ?string {
        return is_string($value) && preg_match('/^[0-9][0-9A-Za-z.+_-]{0,31}$/D', $value) === 1 ? $value : null;
    }

    /**
     * @param mixed $value
     * @return int|bool|null
     */
    private static function iniValue(string $key, $value) {
        if (!is_string($value)) { return null; }
        if (in_array($key, array('zlib.output_compression', 'opcache.enable', 'opcache.validate_timestamps', 'display_errors'), true)) {
            return array('1' => true, 'on' => true, 'yes' => true, '' => false, '0' => false, 'off' => false, 'no' => false)[strtolower($value)] ?? null;
        }
        if ($key === 'memory_limit' && preg_match('/^(-1|[0-9]{1,12})([KMG]?)$/iD', $value, $match) === 1) {
            return (int)min(1099511627776, (int)$match[1] * (array('K' => 1024, 'M' => 1048576, 'G' => 1073741824)[strtoupper($match[2])] ?? 1));
        }
        return self::integer($value, -1, 86400000);
    }

    /**
     * Accept both boundary input and the canonical endpoint shape.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function endpoint(array $input): array {
        $text = $input['filterText'] ?? null;
        return array('category' => 'plugin_admin_ajax', 'action' => 'ajaxUpdatePaginationLinks',
            'subpage' => self::enum($input['subpage'] ?? null, array('abj404_captured', 'abj404_redirects', 'abj404_logs'), 'unknown'),
            'part' => self::enum($input['part'] ?? null, array('all', 'table', 'counts', 'pagination', 'health'), 'unknown'),
            'page' => self::enum($input['page'] ?? null, array('abj404_solution'), 'unknown'),
            'paged' => self::integer($input['paged'] ?? null, 1, 1000000),
            'rows_per_page' => self::integer($input['rowsPerPage'] ?? $input['rows_per_page'] ?? null, 0, 10000),
            'filter' => self::integer($input['filter'] ?? null, 0, 99),
            'filter_text_bytes' => is_scalar($text) ? min(1048576, strlen((string)$text))
                : self::integer($input['filter_text_bytes'] ?? null, 0, 1048576));
    }

    /**
     * @param mixed $raw
     * @return array{sql_id: string, timeout_seconds: int|null, state: string, deadline_mode: string}|null
     */
    private static function query($raw): ?array {
        if (!is_array($raw) || !is_string($raw['sql_id'] ?? null)
                || preg_match('/^[a-f0-9]{8,64}$/D', $raw['sql_id']) !== 1) { return null; }
        return array('sql_id' => $raw['sql_id'], 'timeout_seconds' => self::integer($raw['timeout_seconds'] ?? null, 1, 86400),
            'state' => self::enum($raw['state'] ?? null, array('in_progress', 'complete', 'sql_failure', 'sql_timeout'), 'in_progress'),
            'deadline_mode' => self::enum($raw['deadline_mode'] ?? null, array('applied_wrapper', 'requested_hint', 'unwrapped'), 'unwrapped'));
    }
}
