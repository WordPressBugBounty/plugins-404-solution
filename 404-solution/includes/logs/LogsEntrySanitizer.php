<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes queued log entries into the scalar storage shape accepted by
 * logsv2 INSERTs, and serializes frontend pipeline traces for storage.
 */
class ABJ_404_Solution_LogsEntrySanitizer {

    /**
     * Normalize a log entry into the strict shape expected by INSERT IGNORE.
     * Returns null when a required column is missing or sanitization rejects
     * the entry (empty requested_url, empty dest_url, or non-scalar payload).
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public function sanitizeLogEntry(array $entry): ?array {
        $required = array('timestamp', 'user_ip', 'referrer', 'dest_url', 'requested_url', 'requested_url_detail', 'username', 'min_log_id', 'engine');
        foreach ($required as $key) {
            if (!array_key_exists($key, $entry)) {
                return null;
            }
        }

        $sanitized = array();
        $tsVal = $entry['timestamp'] ?? abj_clock()->now();
        $sanitized['timestamp'] = absint(is_scalar($tsVal) ? $tsVal : abj_clock()->now());
        $sanitized['user_ip'] = $this->normalizeStorageString($entry['user_ip'], 512);
        $sanitized['referrer'] = $this->normalizeStorageString($entry['referrer'], 512);
        $sanitized['dest_url'] = $this->normalizeStorageString($entry['dest_url'], 512);
        $sanitized['requested_url'] = $this->normalizeStorageString($entry['requested_url'], 2048);
        $sanitized['requested_url_detail'] = $this->normalizeStorageString($entry['requested_url_detail'], 2048);
        if (!is_string($sanitized['requested_url']) || $sanitized['requested_url'] === ''
                || !is_string($sanitized['dest_url']) || $sanitized['dest_url'] === '') {
            return null;
        }

        if (array_key_exists('canonical_url', $entry) && is_string($entry['canonical_url'])) {
            $canonical = $entry['canonical_url'];
        } else {
            $canonical = '/' . trim($sanitized['requested_url'], '/');
        }
        $sanitized['canonical_url'] = substr($canonical, 0, 2048);
        // SQL LEFT counts characters, including on hosts without mbstring.
        // Derive from the sanitized URL, never a caller-supplied sort key.
        // Invalid UTF-8 remains NULL for the background SQL backfill rather
        // than publishing a byte-truncated key with a different ordering.
        $sanitized['url_sort_key'] = $this->urlSortKey($sanitized['requested_url']);

        $usernameVal = $entry['username'] ?? null;
        $sanitized['username'] = ($usernameVal === null || !is_scalar($usernameVal)) ? null : absint($usernameVal);
        $minLogIdVal = $entry['min_log_id'] ?? null;
        $sanitized['min_log_id'] = ($minLogIdVal === null || !is_scalar($minLogIdVal)) ? null : absint($minLogIdVal);
        $sanitized['engine'] = $this->normalizeStorageString($entry['engine'], 64);
        if (array_key_exists('pipeline_trace', $entry)) {
            $traceVal = $entry['pipeline_trace'];
            $sanitized['pipeline_trace'] = ($traceVal === null || is_string($traceVal)) ? $traceVal : null;
        } else {
            $sanitized['pipeline_trace'] = null;
        }
        return $sanitized;
    }

    /** @param mixed $value Reject composite values before storage byte limits apply. */
    private function normalizeStorageString($value, int $maxLen): ?string {
        if (!is_scalar($value) && $value !== null) { return null; }
        $str = (string)$value;
        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
            if (is_string($converted)) { $str = $converted; }
        }
        return substr($str, 0, $maxLen);
    }

    /** Match SQL LEFT's character limit without requiring the mbstring extension. */
    private function urlSortKey(string $url): ?string {
        $prefix = array();
        return preg_match('/^.{0,191}/us', $url, $prefix) === 1 ? $prefix[0] : null;
    }

    /**
     * gz + base64 the pipeline trace payload for storage.
     *
     * @param array<int, array{step: string, outcome: string, detail: string}>|null $trace
     */
    public function serializePipelineTrace(?array $trace): ?string {
        if ($trace === null || empty($trace)) {
            return null;
        }
        $json = json_encode($trace);
        if ($json === false) {
            return null;
        }
        $compressed = gzcompress($json, 6);
        if ($compressed === false) {
            return null;
        }
        return base64_encode($compressed);
    }
}
