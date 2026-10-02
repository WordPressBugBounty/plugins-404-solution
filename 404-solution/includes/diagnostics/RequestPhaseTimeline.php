<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The always-on per-request phase timeline: where THIS request spent its
 * time, in monotonic milliseconds since plugin boot.
 *
 * Report 501 is the reason this exists. A first table attempt stalled 16-25
 * s with debug_mode off and left no phase evidence at all: an unarmed
 * request writes no journal by design, and a finished request deleted its
 * census row. The timeline is the cheap record that survives both. It lives
 * in memory (no I/O of its own) and rides out on census writes that already
 * happen, as the last field of the census row (see
 * ABJ_404_Solution_SameSiteRequestRowCodec::encode for the layout).
 *
 * Stamps are milliseconds since plugin boot on the monotonic clock, so they
 * are skew-free inside PHP. Request start comes from
 * $_SERVER['REQUEST_TIME_FLOAT'] (the value the SAPI recorded, not a clock
 * read) and the client's send time is stored raw, on the client's clock;
 * skew between the two is estimated at read time, never trusted here. The
 * boot-to-epoch anchor is taken where the injected clock exists (the census
 * join), corrected for the boot-to-join elapsed time, so a slow boot does
 * not shift every stamp's epoch placement.
 *
 * The stamp vocabulary is NAME_PATTERN; the names this plugin writes are
 * boot, join, handler, auth, st:<stage>, encoded, echoed, detach, detached,
 * wp_shutdown, shutdown and leave, plus the census segment names markPhase
 * records (which overlap except response_encode and ob_drain), wp:<hook>
 * (ABJ_404_Solution_LifecycleHookStamps) and abj404:404, abj404:engines and
 * abj404:after_match on the front-end 404 path. The first
 * write of a name wins and at most MAX_STAMPS are kept, so one request
 * cannot grow its row without bound. PHP cannot observe bytes on the wire,
 * so "first byte flushed" is recorded as echoed (body written into PHP's
 * buffers) and detached (the SAPI finish call returned).
 *
 * Lifecycle: markPluginBoot() at plugin entry, anchorWallClock() and
 * describeRequest() at census join, stamps and counters along the way,
 * encode() onto every census write. Before boot the recorders are no-ops
 * (describeRequest excepted: identity is not timing) and encode() is ''.
 * No method throws.
 */
final class ABJ_404_Solution_RequestPhaseTimeline {

    /**
     * Stamp vocabulary. Letters, digits, underscore and colon only: the
     * encoded timeline rides a pipe-delimited row, so the delimiter can never
     * appear in a name, and a bounded alphabet keeps the row greppable.
     */
    const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_:]{0,47}$/';

    /** At most this many distinct stamps; further names are dropped. */
    const MAX_STAMPS = 24;

    /** Characters of the client's response part kept; see Utf8SafeRecord::clip(). */
    const MAX_PART_CHARS = 16;

    /** @var bool Whether markPluginBoot() has run. */
    private static $booted = false;

    /** @var int Monotonic nanoseconds at plugin boot. */
    private static $bootNs = 0;

    /** @var int|null Request start in epoch ms, from REQUEST_TIME_FLOAT. */
    private static $requestStartMs = null;

    /** @var int|null Plugin boot in epoch ms minus request start. */
    private static $bootOffsetMs = null;

    /** @var string Normalized ledger id of the described request. */
    private static $requestId = '';

    /** @var int How many retries preceded the described request. */
    private static $retryCount = 0;

    /** @var string Normalized id of the attempt this retry follows, or ''. */
    private static $retryParentId = '';

    /** @var string Response part, truncated, never carrying the delimiter. */
    private static $part = '';

    /** @var int|null Client send time in epoch ms, on the client's clock. */
    private static $clientSentAtMs = null;

    /** @var array<string, int> Stamp name to ms since boot. */
    private static $stamps = array();

    /** @var int Finished queries. */
    private static $queryCount = 0;

    /** @var int Finished-query milliseconds. */
    private static $queryMs = 0;

    /** @var int|null Encoded response bytes. */
    private static $responseBytes = null;

    /** @var int|null User plus system CPU ms since plugin boot. */
    private static $cpuMs = null;

    /** @var callable|null Test seam for the monotonic clock. */
    private static $monotonicSource = null;

    /**
     * Record plugin boot on the monotonic clock. Idempotent; only the first
     * call counts. The boot stamp is defined as millisecond zero rather than
     * measured, so a scripted test clock spends no tick on it.
     *
     * @return void
     */
    public static function markPluginBoot(): void {
        if (self::$booted) {
            return;
        }
        self::$bootNs = self::nowNs();
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        self::$requestStartMs = is_numeric($requestStart)
            ? (int)round((float)$requestStart * 1000) : null;
        self::$booted = true;
        self::$stamps['boot'] = 0;
    }

    /**
     * Tie the monotonic stamps to epoch time: plugin boot in epoch ms minus
     * request start. The reading is taken after boot, so the boot-to-reading
     * elapsed time is subtracted back out; without that correction a slow
     * boot would shift every stamp's epoch placement by the boot window.
     * Null when the request start or the elapsed time is unknown.
     *
     * @param int $nowMs Current epoch ms from the injected clock.
     * @return void
     */
    public static function anchorWallClock(int $nowMs): void {
        $elapsed = self::elapsedSinceBootMs();
        self::$bootOffsetMs = ($elapsed === null || self::$requestStartMs === null)
            ? null : $nowMs - $elapsed - self::$requestStartMs;
    }

    /**
     * Record entering the named moment, in ms since boot. The first write
     * wins; names outside the vocabulary, repeats, and names past the cap
     * are ignored. No-op before boot.
     *
     * @param string $name
     * @return void
     */
    public static function stamp(string $name): void {
        if (!self::$booted) {
            return;
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return;
        }
        if (isset(self::$stamps[$name])) {
            return;
        }
        if (count(self::$stamps) >= self::MAX_STAMPS) {
            return;
        }
        $elapsed = self::elapsedSinceBootMs();
        if ($elapsed === null) {
            return;
        }
        self::$stamps[$name] = $elapsed;
    }

    /**
     * The WordPress shutdown stamp, installed by the census as a shutdown
     * callback for the instrumented endpoint. A timeline that ends here is a
     * request that lived all the way into WP shutdown.
     *
     * @return void
     */
    public static function stampWpShutdown(): void {
        self::stamp('wp_shutdown');
    }

    /**
     * Describe the request this timeline belongs to. Every id is normalized
     * through the ledger; the part is clipped to valid UTF-8 and stripped of
     * the row delimiter (it is the only raw client string in the shape); the client
     * send time and retry count are read as exact integers, with an absent
     * send time staying null. Last write wins: each join describes the
     * current request.
     *
     * @param array<string, mixed> $input requestId, retryCount,
     *   retryParentId, part and clientSentAt as received.
     * @return void
     */
    public static function describeRequest(array $input): void {
        $part = isset($input['part']) && is_scalar($input['part'])
            ? (string)$input['part'] : '';
        $clientSentAt = ABJ_404_Solution_ExactInteger::readOr(
            $input['clientSentAt'] ?? null, 0, 0);
        self::$requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $input['requestId'] ?? '');
        self::$retryCount = ABJ_404_Solution_ExactInteger::readOr(
            $input['retryCount'] ?? null, 0, 0);
        // The '' fallback matches the ledger's own retry_parent_id: "this
        // request names no parent" is the ordinary case, not an unknown id.
        self::$retryParentId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $input['retryParentId'] ?? '', '');
        self::$part = str_replace('|', '', ABJ_404_Solution_Utf8SafeRecord::clip($part, self::MAX_PART_CHARS));
        self::$clientSentAtMs = $clientSentAt === 0 ? null : $clientSentAt;
    }

    /**
     * Count one finished query and its measured milliseconds. Called for
     * every query, armed or not. No-op before boot.
     *
     * @param float $ms
     * @return void
     */
    public static function noteQuery(float $ms): void {
        if (!self::$booted) {
            return;
        }
        self::$queryCount++;
        self::$queryMs += (int)round(max(0.0, $ms));
    }

    /**
     * Record the encoded response size in bytes. No-op before boot.
     *
     * @param int $bytes
     * @return void
     */
    public static function noteResponseBytes(int $bytes): void {
        if (!self::$booted) {
            return;
        }
        self::$responseBytes = max(0, $bytes);
    }

    /**
     * Record user plus system CPU milliseconds since plugin boot, from the
     * current resource usage minus the boot snapshot. Stays null when either
     * reading is unavailable (the host may disable getrusage) or the boot
     * snapshot was never recorded. No-op before boot.
     *
     * @return void
     */
    public static function noteCpuSinceBoot(): void {
        if (!self::$booted) {
            return;
        }
        $current = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::resourceUsage();
        $snapshot = ABJ_404_Solution_ErrorHandler::bootResourceSnapshot();
        $boot = isset($snapshot['usage']) && is_array($snapshot['usage'])
            ? $snapshot['usage'] : null;
        $delta = self::cpuDeltaMs($current, $boot);
        if ($delta !== null) {
            self::$cpuMs = $delta;
        }
    }

    /**
     * The timeline shape. Stable across calls: nothing in it measures the
     * moment of reading.
     *
     * @return array{v: int, rid: string, rc: int, rp: string, part: string,
     *   cs: int|null, rt: int|null, bo: int|null, t: array<string, int>,
     *   db: array{n: int, ms: int}, bytes: int|null, cpu: int|null}
     */
    public static function toArray(): array {
        return array(
            'v' => 1,
            'rid' => self::$requestId,
            'rc' => self::$retryCount,
            'rp' => self::$retryParentId,
            'part' => self::$part,
            'cs' => self::$clientSentAtMs,
            'rt' => self::$requestStartMs,
            'bo' => self::$bootOffsetMs,
            't' => self::$stamps,
            'db' => array('n' => self::$queryCount, 'ms' => self::$queryMs),
            'bytes' => self::$responseBytes,
            'cpu' => self::$cpuMs,
        );
    }

    /**
     * The timeline as one JSON record for the census row's last field. Never
     * contains the row delimiter: stamp names cannot spell one and the part
     * is stripped at describe time. '' before boot, and '' (after a durable
     * WARN naming the JSON error) in the one case the record cannot be encoded;
     * invalid UTF-8 in it never causes that, see ABJ_404_Solution_Utf8SafeRecord.
     *
     * @return string
     */
    public static function encode(): string {
        if (!self::$booted) {
            return '';
        }
        return ABJ_404_Solution_Utf8SafeRecord::encode(self::toArray(), 'request phase timeline');
    }

    /**
     * The inverse of encode(), or null when the value is not an encoded
     * timeline of an understood version. A row with no timeline decodes to
     * null, not to an empty shape.
     *
     * @param string $encoded
     * @return array<string, mixed>|null
     */
    public static function decode(string $encoded): ?array {
        if ($encoded === '') {
            return null;
        }
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            return null;
        }
        return $decoded;
    }

    /**
     * Whole milliseconds since plugin boot on the monotonic clock, or null
     * before boot.
     *
     * @return int|null
     */
    public static function elapsedSinceBootMs(): ?int {
        if (!self::$booted) {
            return null;
        }
        return max(0, (int)((self::nowNs() - self::$bootNs) / 1000000));
    }

    /**
     * Return to the state a freshly started PHP process is in: no boot, no
     * identity, no stamps, no counters, no test clock.
     *
     * @return void
     */
    public static function resetForTests(): void {
        self::$booted = false;
        self::$bootNs = 0;
        self::$requestStartMs = null;
        self::$bootOffsetMs = null;
        self::$requestId = '';
        self::$retryCount = 0;
        self::$retryParentId = '';
        self::$part = '';
        self::$clientSentAtMs = null;
        self::$stamps = array();
        self::$queryCount = 0;
        self::$queryMs = 0;
        self::$responseBytes = null;
        self::$cpuMs = null;
        self::$monotonicSource = null;
    }

    /**
     * Drive the monotonic clock from a test double returning nanoseconds.
     * A double that answers outside integers falls back to the real clock
     * for that read. Pass null to restore the real clock.
     *
     * @param callable|null $source
     * @return void
     */
    public static function setMonotonicSourceForTests(?callable $source): void {
        self::$monotonicSource = $source;
    }

    /**
     * Monotonic nanoseconds, from the test double when one is installed.
     *
     * @return int
     */
    private static function nowNs(): int {
        if (self::$monotonicSource !== null) {
            $tick = call_user_func(self::$monotonicSource);
            if (is_int($tick) || is_float($tick)) {
                return (int)$tick;
            }
        }
        return hrtime(true);
    }

    /**
     * Current minus boot user-plus-system CPU in whole ms, or null when
     * either reading is unusable.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $boot
     * @return int|null
     */
    private static function cpuDeltaMs(?array $current, ?array $boot): ?int {
        if ($current === null || $boot === null) {
            return null;
        }
        $now = ABJ_404_Solution_CpuUsage::totalMs($current);
        $then = ABJ_404_Solution_CpuUsage::totalMs($boot);
        if ($now === null || $then === null) {
            return null;
        }
        return max(0, $now - $then);
    }
}
