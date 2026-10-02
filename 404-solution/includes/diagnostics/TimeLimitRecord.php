<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The persisted format of a time-limit breakdown record (version 1), and the
 * one way untrusted data enters it. ABJ_404_Solution_TimeLimitBreakdown
 * builds records; the store reads them back through normalize(); every
 * renderer takes the typed shape defined here.
 *
 * Forward-compatibility contract: fields are only ever added; unknown fields
 * are dropped on read; a record of another version reads as null rather
 * than being guessed at.
 *
 * @phpstan-type Owner array{kind: string, key: string}
 * @phpstan-type Row array{kind: string, key: string, label: string, ms: int}
 * @phpstan-type Phase array{name: string, start_ms: int, ms: int, cpu_ms: int|null}
 * @phpstan-type CallbackRow array{hook: string, kind: string, key: string, label: string, callback: string, ms: int, in_flight: bool, at: int|null}
 * @phpstan-type DiedIn array{hook: string|null, kind: string, key: string, label: string, callback: string|null}
 * @phpstan-type Record array{v: int, kind: string, mode: string, limit_s: int|null, total_ms: int,
 *   wall_ms: int|null, startup_ms: int|null, cpu_ms: int|null, abj404_ms: int, rows: list<Row>,
 *   phases: list<Phase>, slowest: list<CallbackRow>, died_in: DiedIn, recent_slow: list<CallbackRow>}
 */
final class ABJ_404_Solution_TimeLimitRecord {

    /** Record version. Additive changes only; bump on any redefinition. */
    const VERSION = 1;

    /**
     * A record as read back from storage, or null when it is not one this
     * version understands.
     *
     * @param mixed $raw
     * @return Record|null
     */
    public static function normalize($raw): ?array {
        if (!is_array($raw) || ($raw['v'] ?? null) !== self::VERSION || !is_array($raw['rows'] ?? null)) {
            return null;
        }
        return array(
            'v' => self::VERSION,
            'kind' => self::stringValue($raw['kind'] ?? 'time_limit'),
            'mode' => self::stringValue($raw['mode'] ?? ''),
            'limit_s' => self::nullableInt($raw['limit_s'] ?? null),
            'total_ms' => self::intValue($raw['total_ms'] ?? 0),
            'wall_ms' => self::nullableInt($raw['wall_ms'] ?? null),
            'startup_ms' => self::nullableInt($raw['startup_ms'] ?? null),
            'cpu_ms' => self::nullableInt($raw['cpu_ms'] ?? null),
            'abj404_ms' => self::intValue($raw['abj404_ms'] ?? 0),
            'rows' => self::rows($raw['rows']),
            'phases' => self::phases($raw['phases'] ?? null),
            'slowest' => self::callbackList($raw['slowest'] ?? null),
            'died_in' => self::diedIn($raw['died_in'] ?? null),
            'recent_slow' => self::callbackList($raw['recent_slow'] ?? null),
        );
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return list<Row>
     */
    private static function rows(array $raw): array {
        $rows = array();
        foreach ($raw as $row) {
            if (is_array($row) && is_string($row['kind'] ?? null) && is_int($row['ms'] ?? null)) {
                $rows[] = array('kind' => $row['kind'], 'key' => self::stringValue($row['key'] ?? ''),
                    'label' => self::stringValue($row['label'] ?? ''), 'ms' => $row['ms']);
            }
        }
        return $rows;
    }

    /**
     * @param mixed $raw
     * @return list<Phase>
     */
    private static function phases($raw): array {
        $phases = array();
        foreach (is_array($raw) ? $raw : array() as $phase) {
            if (is_array($phase) && is_string($phase['name'] ?? null) && is_int($phase['ms'] ?? null)) {
                $phases[] = array('name' => $phase['name'], 'start_ms' => self::intValue($phase['start_ms'] ?? 0),
                    'ms' => $phase['ms'], 'cpu_ms' => self::nullableInt($phase['cpu_ms'] ?? null));
            }
        }
        return $phases;
    }

    /**
     * @param mixed $raw
     * @return DiedIn
     */
    private static function diedIn($raw): array {
        $died = is_array($raw) ? $raw : array();
        return array('hook' => is_string($died['hook'] ?? null) ? $died['hook'] : null,
            'kind' => is_string($died['kind'] ?? null) ? $died['kind'] : 'other', 'key' => self::stringValue($died['key'] ?? ''),
            'label' => self::stringValue($died['label'] ?? ''),
            'callback' => is_string($died['callback'] ?? null) ? $died['callback'] : null);
    }

    /**
     * Callback rows in any stored shape, validated; extra fields dropped.
     *
     * @param mixed $raw
     * @return list<CallbackRow>
     */
    public static function callbackList($raw): array {
        $list = array();
        foreach (is_array($raw) ? $raw : array() as $row) {
            if (!is_array($row) || !is_int($row['ms'] ?? null)) {
                continue;
            }
            $list[] = array('hook' => self::stringValue($row['hook'] ?? ''), 'kind' => self::stringValue($row['kind'] ?? 'other'),
                'key' => self::stringValue($row['key'] ?? ''), 'label' => self::stringValue($row['label'] ?? ''),
                'callback' => self::stringValue($row['callback'] ?? ''), 'ms' => $row['ms'],
                'in_flight' => !empty($row['in_flight']), 'at' => self::nullableInt($row['at'] ?? null));
        }
        return $list;
    }

    /**
     * An owner {kind, key} from untrusted data, or null.
     *
     * @param mixed $owner
     * @return Owner|null
     */
    public static function owner($owner): ?array {
        if (!is_array($owner) || !is_string($owner['kind'] ?? null) || !is_string($owner['key'] ?? null)) {
            return null;
        }
        return array('kind' => $owner['kind'], 'key' => $owner['key']);
    }

    /**
     * The one spelling of an owner as a map key (label maps, owner buckets).
     *
     * @param Owner $owner
     * @return string
     */
    public static function ownerKey(array $owner): string {
        return $owner['kind'] . ':' . $owner['key'];
    }

    /**
     * @param mixed $value
     * @return string '' unless $value is a string.
     */
    public static function stringValue($value): string {
        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $value
     * @return int 0 unless $value spells a whole number.
     */
    public static function intValue($value): int {
        return is_int($value) ? $value : ABJ_404_Solution_ExactInteger::readOr($value, PHP_INT_MIN, 0);
    }

    /**
     * @param mixed $value
     * @return int|null null unless $value is an int.
     */
    public static function nullableInt($value): ?int {
        return is_int($value) ? $value : null;
    }
}
