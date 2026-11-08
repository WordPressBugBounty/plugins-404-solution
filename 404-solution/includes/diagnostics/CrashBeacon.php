<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/RequestKind.php';

/**
 * Immutable record of a fatal-error "crash beacon".
 *
 * When a request fatals or runs out of memory, the HTTP telemetry path never
 * runs, so the worst incidents are invisible to the feedback server by
 * construction (see the 4.3.0 OOM, docs/crash-beacon-design.md). This value
 * object captures the minimum PII-safe facts about a fatal so that a LATER
 * healthy request (possibly after a plugin update, since the on-disk file lives
 * in the uploads dir and survives the plugin-directory replacement) can phone
 * home a post-mortem `error` report.
 *
 * Two hard constraints shape this class:
 *
 *   1. It is built inside the fatal shutdown handler where memory may already be
 *      exhausted. fromLastError() therefore uses ONLY primitives (no WP APIs, no
 *      service container, no heavy normalizer class) and does a cheap inline
 *      redaction. The full canonical normalization happens later, at report
 *      time, when memory is plentiful.
 *
 *   2. The serialized form is a forward-compatibility contract: a beacon written
 *      by plugin version X is read by the recovered version Y (which may be older
 *      OR newer). The format is versioned (SCHEMA_VERSION) and read tolerantly;
 *      a reader that does not recognise the version leaves the file alone rather
 *      than destroying evidence (handled by ABJ_404_Solution_CrashBeaconStore).
 *
 * PII: the persisted message is redacted AT REST (absolute paths folded to
 * basename, non-printable bytes dropped, long digit runs folded) because the
 * uploads dir can be web-accessible or backed up. The stored "file" is always
 * plugin-relative or a bare basename, never an absolute server path.
 */
final class ABJ_404_Solution_CrashBeacon {

    /**
     * On-disk format version. Bump ONLY on a breaking shape change. Readers
     * discard older shapes and leave newer shapes untouched (see CrashBeaconStore).
     */
    const SCHEMA_VERSION = 1;

    /** Hard cap on the persisted message so a runaway error string cannot bloat
     *  the beacon file during an OOM write. Kept small deliberately (PII + memory). */
    const MESSAGE_MAX_LEN = 256;

    /** @var string */
    private $pluginVersion;
    /** @var int PHP error-level constant (E_ERROR, E_PARSE, ...). */
    private $errorType;
    /** @var string plugin-relative path, or a bare basename for foreign-scope files. */
    private $relativeFile;
    /** @var int */
    private $line;
    /** @var string redacted, truncated, ASCII-only. */
    private $message;
    /** @var int epoch seconds. */
    private $capturedAt;
    /** @var array<string, mixed>|null Original request primitives captured before fatal handling. */
    private $failureFrame;
    /** @var string|null Stable request kind captured before fatal handling. */
    private $requestKind;
    /** @var string|null Sanitized admin-ajax action, when applicable. */
    private $requestAction;

    /**
     * @param string $pluginVersion
     * @param int    $errorType
     * @param string $relativeFile
     * @param int    $line
     * @param string $message
     * @param int    $capturedAt
     */
    public function __construct(string $pluginVersion, int $errorType, string $relativeFile, int $line, string $message, int $capturedAt) {
        $this->pluginVersion = $pluginVersion;
        $this->errorType = $errorType;
        $this->relativeFile = $relativeFile;
        $this->line = $line;
        $this->message = $message;
        $this->capturedAt = $capturedAt;
        $this->failureFrame = null;
        $this->requestKind = null;
        $this->requestAction = null;
    }

    /**
     * Build from a PHP error_get_last() shape using only primitives so it is
     * safe to call inside the OOM fatal handler.
     *
     * @param array<string,mixed> $lasterror error_get_last() shape (type/file/line/message).
     * @param string $pluginVersion ABJ404_VERSION.
     * @param string $pluginRoot ABJ404_PATH (a define; no allocation) used to make the path plugin-relative.
     * @param int    $now epoch seconds (pass time() directly in the OOM path; do not load the clock service).
     * @param array<string,mixed>|null $requestContext Named request context with optional failure_frame, request_kind, request_action.
     * @return self
     */
    public static function fromLastError(array $lasterror, string $pluginVersion, string $pluginRoot, int $now, ?array $requestContext = null): self {
        $type = isset($lasterror['type']) && is_scalar($lasterror['type']) ? (int)$lasterror['type'] : 0;
        $file = isset($lasterror['file']) && is_string($lasterror['file']) ? $lasterror['file'] : '';
        $line = isset($lasterror['line']) && is_scalar($lasterror['line']) ? (int)$lasterror['line'] : 0;
        $rawMsg = isset($lasterror['message']) && is_string($lasterror['message']) ? $lasterror['message'] : '';

        $beacon = new self(
            $pluginVersion,
            $type,
            self::toPluginRelative($file, $pluginRoot),
            $line,
            self::lightRedact($rawMsg),
            $now
        );
        $beacon->applyRequestContext($requestContext);
        return $beacon;
    }

    /**
     * Strip an absolute path to a plugin-relative one (e.g.
     * "includes/core/PluginLogicOptionsResolver.php"). For a file outside the
     * plugin root (foreign-scope fatal) fall back to the bare basename. NEVER
     * returns an absolute path, so the server filesystem layout and any username
     * in the path are not disclosed.
     *
     * @param string $file
     * @param string $pluginRoot
     * @return string
     */
    private static function toPluginRelative(string $file, string $pluginRoot): string {
        if ($file === '') {
            return '';
        }
        if ($pluginRoot !== '' && strpos($file, $pluginRoot) === 0) {
            $rel = substr($file, strlen($pluginRoot));
            return is_string($rel) && $rel !== '' ? $rel : basename($file);
        }
        return basename($file);
    }

    /**
     * Cheap, dependency-free redaction safe to run during an OOM. Mirrors the
     * first regexes of the canonical normalizer
     * (ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures::normalizeErrorSignature),
     * which cannot be loaded in the fatal handler. Truncate FIRST to cap
     * allocation, then fold absolute paths to basename (PII at rest), drop
     * non-printable/invalid-UTF-8 bytes (so json_encode cannot fail on the
     * message), then fold long digit runs.
     *
     * The digit fold exempts a run immediately followed by "bytes": PHP's own
     * OOM message ("Allowed memory size of N bytes exhausted (tried to
     * allocate N bytes)") is the single most common message this captures,
     * and a byte count is never PII -- it is the memory_limit/allocation-size
     * diagnostic this whole crash-beacon feature exists to report. Folding it
     * away defeated the feature for its primary case.
     *
     * @param string $msg
     * @return string
     */
    private static function lightRedact(string $msg): string {
        if (strlen($msg) > self::MESSAGE_MAX_LEN) {
            $msg = substr($msg, 0, self::MESSAGE_MAX_LEN);
        }
        $msg = preg_replace('#/[A-Za-z0-9_\-\./]+/([A-Za-z0-9_\-]+\.php)#', '$1', $msg);
        $msg = is_string($msg) ? $msg : '';
        $msg = preg_replace('/[^\x20-\x7E]/', '', $msg);
        $msg = is_string($msg) ? $msg : '';
        $msg = preg_replace('/\b\d{4,}\b(?!\s*bytes\b)/i', 'N', $msg);
        return is_string($msg) ? trim($msg) : '';
    }

    /**
     * @return array<string,mixed> serializable form (the on-disk contract).
     */
    public function toArray(): array {
        $record = array(
            'beacon_schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => $this->pluginVersion,
            'error_type' => $this->errorType,
            'file' => $this->relativeFile,
            'line' => $this->line,
            'message' => $this->message,
            'captured_at' => $this->capturedAt,
        );
        $frame = $this->failureFrame();
        if ($frame !== null) { $record['failure_frame'] = $frame; }
        if ($this->requestKind !== null) { $record['request_kind'] = $this->requestKind; }
        if ($this->requestAction !== null) { $record['request_action'] = $this->requestAction; }
        return $record;
    }

    /**
     * Tolerant reverse of toArray(). Returns null when $data is not an array or
     * its beacon_schema_version is not the version this code understands. The
     * caller (CrashBeaconStore) distinguishes "older/corrupt" (discardable) from
     * "newer" (leave untouched) by inspecting the raw version itself, so this
     * method only needs to accept the exact known version. Missing scalar fields
     * default rather than fail.
     *
     * @param mixed $data
     * @return self|null
     */
    public static function fromArray($data): ?self {
        if (!is_array($data)) {
            return null;
        }
        $ver = isset($data['beacon_schema_version']) && is_scalar($data['beacon_schema_version'])
            ? (int)$data['beacon_schema_version'] : 0;
        if ($ver !== self::SCHEMA_VERSION) {
            return null;
        }
        $beacon = new self(
            isset($data['plugin_version']) && is_scalar($data['plugin_version']) ? (string)$data['plugin_version'] : '',
            isset($data['error_type']) && is_scalar($data['error_type']) ? (int)$data['error_type'] : 0,
            isset($data['file']) && is_scalar($data['file']) ? (string)$data['file'] : '',
            isset($data['line']) && is_scalar($data['line']) ? (int)$data['line'] : 0,
            isset($data['message']) && is_scalar($data['message']) ? (string)$data['message'] : '',
            isset($data['captured_at']) && is_scalar($data['captured_at']) ? (int)$data['captured_at'] : 0
        );
        $beacon->applyRequestContext(self::requestContextFromStoredRecord($data), true);
        return $beacon;
    }

    /**
     * Decode only the optional context fields from a stored beacon record.
     *
     * @param array<mixed, mixed> $data
     * @return array{failure_frame: mixed, request_kind: string|null, request_action: string|null}
     */
    private static function requestContextFromStoredRecord(array $data): array {
        return array(
            'failure_frame' => $data['failure_frame'] ?? null,
            'request_kind' => isset($data['request_kind']) && is_scalar($data['request_kind'])
                ? (string)$data['request_kind'] : null,
            'request_action' => isset($data['request_action']) && is_scalar($data['request_action'])
                ? (string)$data['request_action'] : null,
        );
    }

    /** @param mixed $context */
    private function applyRequestContext($context, bool $normalizeFailureFrame = false): void {
        if (!is_array($context)) {
            return;
        }
        $rawFrame = $context['failure_frame'] ?? null;
        if (!is_array($rawFrame)) {
            $this->failureFrame = null;
        } else {
            $frame = array_filter($rawFrame, static function ($key): bool {
                return is_string($key);
            }, ARRAY_FILTER_USE_KEY);
            $this->failureFrame = $normalizeFailureFrame
                ? (class_exists('ABJ_404_Solution_RequestFailureFrame')
                    ? ABJ_404_Solution_RequestFailureFrame::normalize($frame) : null)
                : $frame;
        }
        $this->requestKind = self::normalizeRequestKind($context['request_kind'] ?? null);
        $requestAction = $context['request_action'] ?? null;
        $this->requestAction = $this->requestKind === ABJ_404_Solution_RequestKind::KIND_ADMIN_AJAX
            ? ABJ_404_Solution_RequestKind::sanitizeAjaxAction($requestAction) : null;
    }

    /** @return array<string, mixed>|null */
    public function failureFrame(): ?array {
        return $this->failureFrame !== null && class_exists('ABJ_404_Solution_RequestFailureFrame', false)
            ? ABJ_404_Solution_RequestFailureFrame::normalize($this->failureFrame) : null;
    }

    /** @return string */
    public function pluginVersion(): string {
        return $this->pluginVersion;
    }

    /** @return int */
    public function errorType(): int {
        return $this->errorType;
    }

    /** @return string plugin-relative path or bare basename. */
    public function relativeFile(): string {
        return $this->relativeFile;
    }

    /** @return int */
    public function line(): int {
        return $this->line;
    }

    /** @return string */
    public function message(): string {
        return $this->message;
    }

    /** @return int */
    public function capturedAt(): int {
        return $this->capturedAt;
    }

    /** @return string|null */
    public function requestKind(): ?string {
        return $this->requestKind;
    }

    /** @return string|null */
    public function requestAction(): ?string {
        return $this->requestAction;
    }

    /** @param mixed $kind @return string|null */
    private static function normalizeRequestKind($kind): ?string {
        if (!is_string($kind)) { return null; }
        $allowed = array(
            ABJ_404_Solution_RequestKind::KIND_ADMIN_AJAX,
            ABJ_404_Solution_RequestKind::KIND_REST,
            ABJ_404_Solution_RequestKind::KIND_CRON,
            ABJ_404_Solution_RequestKind::KIND_ADMIN_PAGE,
            ABJ_404_Solution_RequestKind::KIND_FRONT_END,
        );
        return in_array($kind, $allowed, true) ? $kind : null;
    }

    /**
     * Stable grouping key for cooldown and "first crash wins": version + error
     * location, NOT the timestamp or message detail. Matches how the feedback
     * server groups error signatures.
     *
     * @return string
     */
    public function signatureKey(): string {
        return $this->pluginVersion . '|' . $this->errorType . '|' . $this->relativeFile . '|' . $this->line;
    }
}
