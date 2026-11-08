<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/ngram/NGramNetworkOptionStore.php';
require_once dirname(__DIR__) . '/ngram/NGramRebuildProgressState.php';
require_once dirname(__DIR__) . '/ngram/NGramUsageTelemetry.php';
require_once __DIR__ . '/FeedbackTransportLog.php';
require_once dirname(__DIR__) . '/core/FileSystemService.php';
require_once dirname(__DIR__) . '/core/HostFilesystemCall.php';

/**
 * Bounded, read-only site inventory and stored N-gram progress for feedback.
 *
 * This class deliberately reads WordPress APIs and options only. It does not
 * inspect plugin files, query plugin tables, calculate live backlog/coverage,
 * or call any rebuild/maintenance path. The outer environment probe owns the
 * failure boundary and publishes this result under one `site_summary` key.
 */
final class ABJ_404_Solution_FeedbackSiteSummary {

    private const MAX_RECORDS_PER_CATEGORY = 20;
    private const MAX_SLUG_LENGTH = 96;
    private const MAX_VERSION_LENGTH = 64;
    private const MAX_USAGE_LENGTH = 32;
    private const MAX_USAGE_NUMBER = 1000000000000.0;

    /** @var array<string, bool> WordPress-recognized drop-in basenames. */
    private const RECOGNIZED_DROPINS = array(
        'advanced-cache.php' => true,
        'db.php' => true,
        'db-error.php' => true,
        'install.php' => true,
        'maintenance.php' => true,
        'object-cache.php' => true,
        'php-error.php' => true,
        'sunrise.php' => true,
    );

    /** @return array<string, mixed> */
    public function collect(): array {
        try {
            return array(
                'status' => 'available',
                'scope' => 'stored_progress',
                'plugin_inventory' => array(
                    'mu_plugins' => $this->collectMuPlugins(),
                    'dropins' => $this->collectDropins(),
                ),
                'ngram' => $this->collectNGram(),
            );
        } catch (\Throwable $e) {
            $this->logFailure('summary', $e);
            return self::unavailable('summary_unavailable');
        }
    }

    /** @return array<string, mixed> */
    public static function unavailable(string $reason = 'unavailable'): array {
        return array(
            'status' => 'unavailable',
            'reason' => self::safeReason($reason),
            'scope' => 'stored_progress',
            'plugin_inventory' => array(
                'mu_plugins' => self::unavailableCategory($reason),
                'dropins' => self::unavailableCategory($reason),
            ),
            'ngram' => array(
                'status' => 'unavailable',
                'reason' => self::safeReason($reason),
                'progress' => array('status' => 'unavailable', 'reason' => self::safeReason($reason)),
                'usage' => array('status' => 'unavailable', 'reason' => self::safeReason($reason)),
                'backlog' => self::unavailableField('live_probe_not_performed'),
                'coverage' => self::unavailableField('live_probe_not_performed'),
                'backlog_interpretation' => self::inferredField('stored_progress_only'),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function collectMuPlugins(): array {
        $api = $this->loadInventoryApi('get_mu_plugins');
        if (!$api['available']) {
            return self::unavailableCategory($api['reason']);
        }
        try {
            $raw = get_mu_plugins();
        } catch (\Throwable $e) {
            $this->logFailure('get_mu_plugins', $e);
            return self::unavailableCategory('api_failed');
        }
        return $this->shapeInventory($raw, false);
    }

    /** @return array<string, mixed> */
    private function collectDropins(): array {
        $api = $this->loadInventoryApi('get_dropins');
        if (!$api['available']) {
            return self::unavailableCategory($api['reason']);
        }
        try {
            $raw = get_dropins();
        } catch (\Throwable $e) {
            $this->logFailure('get_dropins', $e);
            return self::unavailableCategory('api_failed');
        }
        return $this->shapeInventory($raw, true);
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function shapeInventory($raw, bool $dropins): array {
        $out = array(
            'status' => 'available',
            'seen' => 0,
            'kept' => 0,
            'truncated' => false,
            'invalid' => false,
            'items' => array(),
        );
        if (!is_array($raw)) {
            return self::unavailableCategory('invalid_result', true);
        }
        if (self::isList($raw)) {
            return self::unavailableCategory('invalid_result', true);
        }

        $out['seen'] = count($raw);
        $out['truncated'] = $out['seen'] > self::MAX_RECORDS_PER_CATEGORY;
        $processed = 0;
        foreach ($raw as $key => $headers) {
            if ($processed++ >= self::MAX_RECORDS_PER_CATEGORY) {
                break;
            }
            $slug = $this->safeBasename($key);
            if ($slug === '' || ($dropins && !isset(self::RECOGNIZED_DROPINS[$slug]))) {
                $out['invalid'] = true;
                continue;
            }
            if ($headers !== null && !is_array($headers)) {
                $out['invalid'] = true;
                continue;
            }
            $versionInvalid = false;
            $version = $this->safeVersion(is_array($headers) ? ($headers['Version'] ?? null) : null, $versionInvalid);
            if ($versionInvalid) {
                $out['invalid'] = true;
            }
            $item = array('slug' => $slug, 'version' => $version);
            $out['items'][] = $item;
            $out['kept']++;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function collectNGram(): array {
        try {
            $store = new ABJ_404_Solution_NGramNetworkOptionStore();
            $progress = $this->readProgress($store);
            $usage = $this->readUsage();
            return array(
                'status' => 'available',
                'progress' => $progress,
                'usage' => $usage,
                // Deliberately no COUNT(*) or coverage query in a feedback send.
                'backlog' => self::unavailableField('live_probe_not_performed'),
                'coverage' => self::unavailableField('live_probe_not_performed'),
                'backlog_interpretation' => self::inferredField('stored_progress_only'),
            );
        } catch (\Throwable $e) {
            $this->logFailure('ngram_options', $e);
            return array(
                'status' => 'unavailable',
                'reason' => 'option_read_failed',
                'progress' => array('status' => 'unavailable', 'reason' => 'option_read_failed'),
                'usage' => array('status' => 'unavailable', 'reason' => 'option_read_failed'),
                'backlog' => self::unavailableField('live_probe_not_performed'),
                'coverage' => self::unavailableField('live_probe_not_performed'),
                'backlog_interpretation' => self::inferredField('stored_progress_only'),
            );
        }
    }

    /**
     * @param ABJ_404_Solution_NGramNetworkOptionStore $store
     * @return array<string, mixed>
     */
    private function readProgress($store): array {
        $keys = array(
            'rebuild_offset' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_REBUILD_OFFSET,
            'current_site_offset' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_CURRENT_SITE_OFFSET,
            'cache_initialized' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_CACHE_INITIALIZED,
            'total_sites' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_TOTAL_SITES,
            'sites_completed' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_SITES_COMPLETED,
            'consecutive_failures' => ABJ_404_Solution_NGramRebuildProgressState::OPTION_CONSECUTIVE_FAILURES,
        );
        $raw = array();
        foreach ($keys as $name => $key) {
            $raw[$name] = $store->getOption($key, null);
        }

        $total = self::nonNegativeIntField($raw['total_sites']);
        $cursorRaw = $total['status'] === 'available' ? $raw['current_site_offset'] : $raw['rebuild_offset'];
        return array(
            'status' => 'available',
            'cursor' => self::nonNegativeIntField($cursorRaw),
            'cache_initialized' => self::boolField($raw['cache_initialized']),
            'total_sites' => $total,
            'sites_completed' => self::nonNegativeIntField($raw['sites_completed']),
            'consecutive_failures' => self::nonNegativeIntField($raw['consecutive_failures']),
        );
    }

    /** @return array<string, mixed> */
    private function readUsage(): array {
        if (!function_exists('get_option')) {
            return array('status' => 'unavailable', 'reason' => 'api_unavailable');
        }
        // NGramUsageTelemetry writes this option through get_option() even on
        // network-active installs. Keep the read on that same site-local API;
        // only rebuild progress is routed through the network-aware store.
        $raw = ABJ_404_Solution_OptionShapeRead::arrayValue(ABJ_404_Solution_NGramUsageTelemetry::OPTION_KEY, null);
        if ($raw === null) {
            return array('status' => 'unknown', 'reason' => 'not_set');
        }
        if (!is_array($raw)) {
            return array('status' => 'unavailable', 'reason' => 'invalid_result');
        }
        $usage = self::normalizeUsage($raw);
        $values = self::usageAverages($usage['values']);
        return array(
            'status' => 'available',
            'invalid' => $usage['invalid'],
            'values' => $values,
            'fields' => $usage['fields'],
        );
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array{fields: array<string, array<string, mixed>>, values: array<string, int|float>, invalid: bool}
     */
    private static function normalizeUsage(array $raw): array {
        $fields = array(
            'total_queries' => self::nonNegativeIntField($raw['total_queries'] ?? null),
            'total_entries_examined' => self::nonNegativeIntField($raw['total_entries_examined'] ?? null),
            'total_candidates_returned' => self::nonNegativeIntField($raw['total_candidates_returned'] ?? null),
            'total_duration_ms' => self::nonNegativeNumberField($raw['total_duration_ms'] ?? null),
            'avg_reduction_percent' => self::nonNegativeNumberField($raw['avg_reduction_percent'] ?? null),
            'last_reset' => self::nonNegativeIntField($raw['last_reset'] ?? null),
        );
        $values = array();
        $hasInvalid = false;
        foreach ($fields as $name => $field) {
            if ($field['status'] === 'available') {
                $value = $field['value'] ?? null;
                if (is_int($value) || is_float($value)) {
                    $values[$name] = $value;
                } else {
                    $hasInvalid = true;
                }
            } elseif ($field['status'] === 'invalid') {
                $hasInvalid = true;
            }
        }
        return array('fields' => $fields, 'values' => $values, 'invalid' => $hasInvalid);
    }

    /**
     * @param array<string, int|float> $values
     * @return array<string, int|float>
     */
    private static function usageAverages(array $values): array {
        $totalQueries = $values['total_queries'] ?? null;
        if (!is_int($totalQueries) || $totalQueries <= 0) {
            return $values;
        }
        foreach (array(
            'total_entries_examined' => array('avg_examined_per_query', 1),
            'total_candidates_returned' => array('avg_candidates_per_query', 1),
            'total_duration_ms' => array('avg_duration_ms', 2),
        ) as $source => $definition) {
            $value = $values[$source] ?? null;
            if (is_int($value) || is_float($value)) {
                $values[$definition[0]] = round($value / $totalQueries, $definition[1]);
            }
        }
        return $values;
    }

    /** @return array{available: bool, reason: string} */
    private function loadInventoryApi(string $function): array {
        if (is_callable($function)) {
            return array('available' => true, 'reason' => '');
        }
        $pluginFile = defined('ABSPATH') ? rtrim((string)ABSPATH, '/\\') . '/wp-admin/includes/plugin.php' : '';
        if ($pluginFile === '') {
            return array('available' => false, 'reason' => 'api_unavailable');
        }
        $presence = ABJ_404_Solution_HostFilesystemCall::isFile($pluginFile);
        if ($presence['warning'] !== null || !$presence['result']) {
            if ($presence['warning'] !== null) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'WordPress inventory API presence probe failed: ' . $presence['warning']);
            }
            return array('available' => false, 'reason' => 'api_unavailable');
        }
        $load = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(static function() use ($pluginFile) {
            return require_once $pluginFile;
        });
        if ($load['warning'] !== null) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'WordPress inventory API load failed: ' . $load['warning']);
            return array('available' => false, 'reason' => 'api_unavailable');
        }
        return is_callable($function)
            ? array('available' => true, 'reason' => '')
            : array('available' => false, 'reason' => 'api_unavailable');
    }

    /** @param mixed $value */
    private function safeBasename($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $basename = basename((string)$value);
        if (strlen($basename) > self::MAX_SLUG_LENGTH) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._-]+\.php$/D', $basename) === 1 ? $basename : '';
    }

    /** @param mixed $value */
    private function safeVersion($value, bool &$invalid): ?string {
        $invalid = false;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $invalid = true;
            return null;
        }
        $version = $value;
        if (strlen($version) > self::MAX_VERSION_LENGTH
                || preg_match('/^[0-9][A-Za-z0-9._+~:-]{0,63}$/D', $version) !== 1) {
            $invalid = true;
            return null;
        }
        return $version;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function nonNegativeIntField($value): array {
        if ($value === null) {
            return array('status' => 'unknown', 'reason' => 'not_set');
        }
        if (is_int($value) && $value >= 0) {
            return array('status' => 'available', 'value' => $value);
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1 && strlen($value) <= 18) {
            $int = (int)$value;
            if ($int >= 0 && (string)$int === ltrim($value, '0')) {
                return array('status' => 'available', 'value' => $int);
            }
            if ($value === '0') {
                return array('status' => 'available', 'value' => 0);
            }
        }
        return array('status' => 'invalid', 'reason' => 'invalid_nonnegative_integer');
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function nonNegativeNumberField($value): array {
        if ($value === null) {
            return array('status' => 'unknown', 'reason' => 'not_set');
        }
        if (is_int($value) || is_float($value)) {
            $number = (float)$value;
        } elseif (is_string($value) && strlen($value) <= self::MAX_USAGE_LENGTH
                && preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $value) === 1) {
            $number = (float)$value;
        } else {
            return array('status' => 'invalid', 'reason' => 'invalid_nonnegative_number');
        }
        if (!is_finite($number) || $number < 0 || $number > self::MAX_USAGE_NUMBER) {
            return array('status' => 'invalid', 'reason' => 'invalid_nonnegative_number');
        }
        return array('status' => 'available', 'value' => is_int($value) ? $value : $number);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function boolField($value): array {
        if ($value === null) {
            return array('status' => 'unknown', 'reason' => 'not_set');
        }
        if ($value === true || $value === false) {
            return array('status' => 'available', 'value' => $value);
        }
        if ($value === 1 || $value === '1') {
            return array('status' => 'available', 'value' => true);
        }
        if ($value === 0 || $value === '0') {
            return array('status' => 'available', 'value' => false);
        }
        return array('status' => 'invalid', 'reason' => 'invalid_boolean');
    }

    /** @return array<string, mixed> */
    private static function unavailableCategory(string $reason, bool $invalid = false): array {
        return array(
            'status' => 'unavailable',
            'reason' => self::safeReason($reason),
            'seen' => null,
            'kept' => 0,
            'truncated' => false,
            'invalid' => $invalid,
            'items' => array(),
        );
    }

    /** @return array<string, mixed> */
    private static function unavailableField(string $reason): array {
        return array('status' => 'unavailable', 'reason' => self::safeReason($reason));
    }

    /** @return array<string, mixed> */
    private static function inferredField(string $value): array {
        return array('status' => 'inferred', 'value' => $value);
    }

    private static function safeReason(string $reason): string {
        return preg_match('/^[a-z0-9_:-]{1,64}$/D', $reason) === 1 ? $reason : 'unavailable';
    }

    /** @param array<mixed, mixed> $value */
    private static function isList(array $value): bool {
        return $value !== array() && array_keys($value) === range(0, count($value) - 1);
    }

    private function logFailure(string $subject, \Throwable $e): void {
        ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackSiteSummary ' . $subject . ' failed: ' . $e->getMessage());
    }
}
