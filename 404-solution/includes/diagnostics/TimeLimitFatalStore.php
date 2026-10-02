<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The last few requests PHP's time limit killed inside 404 Solution, each
 * with its time breakdown, for the plugin's own admin screen.
 *
 * One non-autoloaded option holding a JSON list, newest last, capped at
 * MAX_ENTRIES; written only from the shutdown path of such a fatal, so a
 * request that does not die writes nothing. Concurrent fatal and shutdown
 * handlers append to the same option, so every read-modify-write here goes
 * through ABJ_404_Solution_OptionRowCompareAndSwap: a lost race repeats the
 * append on the fresh value instead of erasing the other request's entry. A second small option records
 * when an administrator last opened the report, which is what decides
 * whether the quiet notice on the plugin screen is still due.
 *
 * Not StrandedRequestLedger: that ledger keys entries by census request id
 * (front-end requests have none), keeps the EARLIEST entries on purpose, and
 * feeds the support payload; this record is the site owner's, newest-first.
 *
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type CallbackRow from ABJ_404_Solution_TimeLimitRecord
 */
final class ABJ_404_Solution_TimeLimitFatalStore {

    /** Option holding the records. Non-autoloaded. */
    const OPTION_NAME = 'abj404_time_limit_fatals';

    /** Option holding the epoch second the report was last viewed. */
    const SEEN_OPTION_NAME = 'abj404_time_limit_fatals_seen_at';

    /** Records kept. */
    const MAX_ENTRIES = 5;

    /** Record format version. */
    const VERSION = 1;

    /** Option holding slow callbacks found while callbacks were timed. Non-autoloaded. */
    const SLOW_OPTION_NAME = 'abj404_time_limit_slow_callbacks';

    /** Slow-callback findings kept. */
    const MAX_SLOW_ENTRIES = 10;

    /** How long the armed window stays open after a time-limit fatal. */
    const ARMED_WINDOW_S = 1800;

    /** During the armed window, callbacks are timed on 1 in this many requests. */
    const ARMED_ONE_IN = 4;

    /**
     * Open (or extend) the armed window: for ARMED_WINDOW_S, 1 in
     * ARMED_ONE_IN non-AJAX requests time each callback, so the slow one can
     * be named. Autoloaded on purpose: the recorder reads it from the
     * alloptions array every request, which costs no query only when the
     * option is autoloaded. It is deleted once it expires.
     *
     * @param int $now epoch seconds.
     * @return void
     */
    public static function arm(int $now): void {
        if (!function_exists('update_option')) {
            return;
        }
        $value = json_encode(array('until' => $now + self::ARMED_WINDOW_S, 'one_in' => self::ARMED_ONE_IN));
        if (is_string($value)) {
            update_option(ABJ_404_Solution_RequestTimeRecorder::ARMED_OPTION, $value, true);
        }
    }

    /** The armed option reads as no window, a lapsed window, or a window open now. */
    const WINDOW_ABSENT = 'absent';
    const WINDOW_EXPIRED = 'expired';
    const WINDOW_OPEN = 'open';

    /**
     * The armed window as recorded, for the recorder's per-request sampling
     * decision. Reads the option from the alloptions array WordPress has
     * already loaded (never a query: a missing option is simply absent from the
     * array), which is why arm() autoloads it. A pure read: closing a lapsed
     * window is disarmIfExpired()'s job.
     *
     * @param int $now epoch seconds.
     * @return array{state: string, one_in: int} state is one of the WINDOW_*
     *   constants; one_in is the sample size (1 when the option omits it).
     */
    public static function armedWindow(int $now): array {
        $none = array('state' => self::WINDOW_ABSENT, 'one_in' => 1);
        if (!function_exists('wp_load_alloptions')) {
            return $none;
        }
        $all = wp_load_alloptions();
        $raw = is_array($all) ? ($all[ABJ_404_Solution_RequestTimeRecorder::ARMED_OPTION] ?? null) : null;
        if (!is_string($raw) || $raw === '') {
            return $none;
        }
        $armed = json_decode($raw, true);
        $oneIn = is_array($armed) && isset($armed['one_in']) && is_int($armed['one_in']) ? max(1, $armed['one_in']) : 1;
        return array('state' => $now >= self::untilOf($raw) ? self::WINDOW_EXPIRED : self::WINDOW_OPEN, 'one_in' => $oneIn);
    }

    /**
     * The armed window's end in epoch seconds, or null when none is open.
     *
     * @param int $now epoch seconds.
     * @return int|null
     */
    public static function armedUntil(int $now): ?int {
        if (!function_exists('get_option')) {
            return null;
        }
        $raw = get_option(ABJ_404_Solution_RequestTimeRecorder::ARMED_OPTION, '');
        $until = self::untilOf(is_string($raw) ? $raw : '');
        return $until > $now ? $until : null;
    }

    /**
     * Shutdown callback the recorder registers when it finds the window
     * expired: delete the option so no later request reads it.
     *
     * The delete is conditional on the exact expired value read from the
     * database. A check followed by an unconditional delete would erase a
     * window another request opened between the two (arm() rewrites the value),
     * so a re-armed option, holding a different value, survives.
     *
     * @return void
     */
    public static function disarmIfExpired(): void {
        $now = ABJ_404_Solution_ExactInteger::readOr($_SERVER['REQUEST_TIME'] ?? null, 0, 0);
        if ($now <= 0) {
            return;
        }
        $stored = ABJ_404_Solution_OptionRowCompareAndSwap::read(ABJ_404_Solution_RequestTimeRecorder::ARMED_OPTION);
        if ($stored === null || self::untilOf($stored) > $now) {
            return;
        }
        ABJ_404_Solution_OptionRowCompareAndSwap::deleteIfValueIs(array(
            'optionName' => ABJ_404_Solution_RequestTimeRecorder::ARMED_OPTION,
            'expectedValue' => $stored,
        ));
    }

    /**
     * The window end (epoch seconds) an armed-option value records, or 0 when
     * it is empty or malformed.
     *
     * @param string $raw
     * @return int
     */
    private static function untilOf(string $raw): int {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) && isset($decoded['until']) && is_int($decoded['until']) ? $decoded['until'] : 0;
    }

    /**
     * Keep slow callbacks one request found (each over the slow threshold),
     * newest last, capped at MAX_SLOW_ENTRIES.
     *
     * @param list<CallbackRow> $callbacks
     * @param int $at epoch seconds.
     * @return void
     */
    public static function recordSlowCallbacks(array $callbacks, int $at): void {
        if ($callbacks === array()) {
            return;
        }
        ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => self::SLOW_OPTION_NAME,
            'compute' => static function (?string $current) use ($callbacks, $at): ?string {
                $entries = self::slowCallbacksFromRaw($current ?? '');
                foreach ($callbacks as $callback) {
                    $callback['at'] = $at;
                    $entries[] = $callback;
                }
                return self::nullIfEmpty(ABJ_404_Solution_Utf8SafeRecord::encode(
                    array_values(array_slice($entries, -self::MAX_SLOW_ENTRIES)), 'time-limit slow callbacks'));
            },
        ));
    }

    /**
     * Kept slow callbacks, oldest first; malformed data reads as empty.
     *
     * @return list<CallbackRow>
     */
    public static function readSlowCallbacks(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $raw = get_option(self::SLOW_OPTION_NAME, '');
        return self::slowCallbacksFromRaw(is_string($raw) ? $raw : '');
    }

    /**
     * @param string $raw the stored JSON.
     * @return list<CallbackRow>
     */
    private static function slowCallbacksFromRaw(string $raw): array {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return array();
        }
        $entries = array();
        foreach (ABJ_404_Solution_TimeLimitRecord::callbackList($decoded) as $entry) {
            if ($entry['at'] !== null) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * @param string $encoded Utf8SafeRecord::encode()'s answer.
     * @return string|null null (leave the row alone) when nothing could be encoded.
     */
    private static function nullIfEmpty(string $encoded): ?string {
        return $encoded === '' ? null : $encoded;
    }

    /**
     * Append one record and trim to the newest MAX_ENTRIES.
     *
     * @param Record $breakdown
     * @param string $path request path, query string already removed.
     * @param int $at epoch seconds.
     * @return void
     */
    public static function record(array $breakdown, string $path, int $at): void {
        // The request path is a client string: cut on a character boundary so
        // one multibyte path cannot make the whole list unencodable.
        $entry = array('v' => self::VERSION, 'at' => $at,
            'path' => ABJ_404_Solution_Utf8SafeRecord::clip($path, 200), 'breakdown' => $breakdown);
        ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => self::OPTION_NAME,
            'compute' => static function (?string $current) use ($entry): ?string {
                $entries = self::entriesFromRaw($current ?? '');
                $entries[] = $entry;
                return self::nullIfEmpty(ABJ_404_Solution_Utf8SafeRecord::encode(
                    array_values(array_slice($entries, -self::MAX_ENTRIES)), 'time-limit fatal records'));
            },
        ));
    }

    /**
     * Every kept record, oldest first. Malformed data reads as empty.
     *
     * @return list<array{at: int, path: string, breakdown: Record}>
     */
    public static function read(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $raw = get_option(self::OPTION_NAME, '');
        return self::entriesFromRaw(is_string($raw) ? $raw : '');
    }

    /**
     * @param string $raw the stored JSON.
     * @return list<array{at: int, path: string, breakdown: Record}>
     */
    private static function entriesFromRaw(string $raw): array {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return array();
        }
        $entries = array();
        foreach ($decoded as $entry) {
            $record = is_array($entry) ? ABJ_404_Solution_TimeLimitRecord::normalize($entry['breakdown'] ?? null) : null;
            if ($record !== null && is_int($entry['at'] ?? null)) {
                $entries[] = array('at' => $entry['at'], 'path' => ABJ_404_Solution_TimeLimitRecord::stringValue($entry['path'] ?? ''),
                    'breakdown' => $record);
            }
        }
        return $entries;
    }

    /**
     * Whether a record exists that is newer than the last view of the report.
     *
     * @return bool
     */
    public static function hasUnseen(): bool {
        $entries = self::read();
        if ($entries === array()) {
            return false;
        }
        $seen = function_exists('get_option') ? get_option(self::SEEN_OPTION_NAME, 0) : 0;
        return $entries[count($entries) - 1]['at'] > ABJ_404_Solution_ExactInteger::readOr($seen, 0, 0);
    }

    /**
     * Record that the report was viewed now.
     *
     * @param int $at epoch seconds.
     * @return void
     */
    public static function markSeen(int $at): void {
        if (function_exists('update_option')) {
            update_option(self::SEEN_OPTION_NAME, $at, false);
        }
    }
}
