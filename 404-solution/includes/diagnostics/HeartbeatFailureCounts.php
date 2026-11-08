<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fixed-vocabulary heartbeat failure totals and their privacy-safe wire shape.
 *
 * Counts are grouped by the one currently instrumented AJAX action; unknown
 * action and phase values collapse into stable buckets and are never retained.
 *
 * @phpstan-type HeartbeatPhaseCounts array{boot: int, handler: int, response_encode: int, ob_drain: int, detach: int, shutdown: int, unrecorded: int}
 * @phpstan-type HeartbeatActionCounts array{client_timeouts: int, stranded_by_phase: HeartbeatPhaseCounts, max_age_ms: int}
 * @phpstan-type HeartbeatAggregate array{actions: array{ajaxUpdatePaginationLinks: HeartbeatActionCounts, ajaxRefreshHealthBar: HeartbeatActionCounts, other: HeartbeatActionCounts}}
 * @phpstan-type HeartbeatEvent array{kind: 'client_timeout'|'stranded', action: 'ajaxUpdatePaginationLinks'|'ajaxRefreshHealthBar'|'other', phase: 'boot'|'handler'|'response_encode'|'ob_drain'|'detach'|'shutdown'|'unrecorded'|'', age_ms: int}
 * @phpstan-type HeartbeatWireAction array{action: 'ajaxUpdatePaginationLinks'|'ajaxRefreshHealthBar'|'other', client_timeouts: int, stranded_by_phase: HeartbeatPhaseCounts, max_age_ms: int}
 * @phpstan-type HeartbeatWirePayload array{schema_version: 1, window_started_at: int, window_ended_at: int, total_events: int, actions: array{0: HeartbeatWireAction, 1: HeartbeatWireAction, 2: HeartbeatWireAction}}
 */
final class ABJ_404_Solution_HeartbeatFailureCounts {

    private const MAX_WIRE_INTEGER = 2147483647;
    private const ACTIONS = array('ajaxUpdatePaginationLinks', 'ajaxRefreshHealthBar', 'other');
    private const PHASES = array(
        'boot', 'handler', 'response_encode', 'ob_drain', 'detach', 'shutdown', 'unrecorded',
    );

    /**
     * Validate an input event and replace untrusted names with fixed buckets.
     *
     * @param array<string, mixed> $event
     * @return HeartbeatEvent|null
     */
    public static function normalizeEvent(array $event): ?array {
        $kind = $event['kind'] ?? null;
        $action = $event['action'] ?? null;
        if (!is_string($kind) || !in_array($kind, array('client_timeout', 'stranded'), true)
                || !is_string($action)) {
            return null;
        }
        $action = in_array($action, self::ACTIONS, true) ? $action : 'other';
        if ($kind === 'client_timeout') {
            return array('kind' => $kind, 'action' => $action, 'phase' => '', 'age_ms' => 0);
        }
        $ageMs = ABJ_404_Solution_ExactInteger::read($event['age_ms'] ?? null, 0);
        $phase = $event['phase'] ?? null;
        if ($ageMs === null || !is_string($phase)) {
            return null;
        }
        return array(
            'kind' => $kind,
            'action' => $action,
            'phase' => in_array($phase, self::PHASES, true) ? $phase : 'unrecorded',
            'age_ms' => min($ageMs, self::MAX_WIRE_INTEGER),
        );
    }

    /** @return HeartbeatAggregate */
    public static function empty(): array {
        $actions = array();
        foreach (self::ACTIONS as $action) {
            $phases = array_fill_keys(self::PHASES, 0);
            $actions[$action] = array(
                'client_timeouts' => 0,
                'stranded_by_phase' => $phases,
                'max_age_ms' => 0,
            );
        }
        return array('actions' => $actions);
    }

    /**
     * @param HeartbeatAggregate $aggregate
     * @param-out HeartbeatAggregate $aggregate
     * @param HeartbeatEvent $event An event returned by normalizeEvent().
     */
    public static function addEvent(array &$aggregate, array $event): void {
        $action = $event['action'];
        if ($event['kind'] === 'client_timeout') {
            $aggregate['actions'][$action]['client_timeouts'] = self::increment(
                $aggregate['actions'][$action]['client_timeouts']);
            return;
        }
        $phaseCounts = $aggregate['actions'][$action]['stranded_by_phase'];
        self::incrementPhase($phaseCounts, $event['phase']);
        $aggregate['actions'][$action]['stranded_by_phase'] = $phaseCounts;
        $aggregate['actions'][$action]['max_age_ms'] = max(
            $aggregate['actions'][$action]['max_age_ms'], $event['age_ms']);
    }

    /**
     * @param HeartbeatPhaseCounts $counts
     * @param string $phase
     */
    private static function incrementPhase(array &$counts, string $phase): void {
        switch ($phase) {
            case 'boot':
                $counts['boot'] = self::increment($counts['boot']);
                break;
            case 'handler':
                $counts['handler'] = self::increment($counts['handler']);
                break;
            case 'response_encode':
                $counts['response_encode'] = self::increment($counts['response_encode']);
                break;
            case 'ob_drain':
                $counts['ob_drain'] = self::increment($counts['ob_drain']);
                break;
            case 'detach':
                $counts['detach'] = self::increment($counts['detach']);
                break;
            case 'shutdown':
                $counts['shutdown'] = self::increment($counts['shutdown']);
                break;
            default:
                $counts['unrecorded'] = self::increment($counts['unrecorded']);
                break;
        }
    }

    /**
     * Normalize persisted aggregate data to the fixed action and phase maps.
     *
     * @param mixed $aggregate
     * @return HeartbeatAggregate
     */
    public static function normalize($aggregate): array {
        $normalized = self::empty();
        if (!is_array($aggregate)) {
            return $normalized;
        }
        $storedActions = isset($aggregate['actions']) && is_array($aggregate['actions'])
            ? $aggregate['actions'] : array();
        foreach (self::ACTIONS as $action) {
            $stored = isset($storedActions[$action]) && is_array($storedActions[$action])
                ? $storedActions[$action] : array();
            $normalized['actions'][$action]['client_timeouts'] = min(self::MAX_WIRE_INTEGER,
                ABJ_404_Solution_ExactInteger::readOr($stored['client_timeouts'] ?? null, 0, 0));
            $normalized['actions'][$action]['max_age_ms'] = min(self::MAX_WIRE_INTEGER,
                ABJ_404_Solution_ExactInteger::readOr($stored['max_age_ms'] ?? null, 0, 0));
            $storedPhases = isset($stored['stranded_by_phase']) && is_array($stored['stranded_by_phase'])
                ? $stored['stranded_by_phase'] : array();
            foreach (self::PHASES as $phase) {
                $normalized['actions'][$action]['stranded_by_phase'][$phase] = min(self::MAX_WIRE_INTEGER,
                    ABJ_404_Solution_ExactInteger::readOr($storedPhases[$phase] ?? null, 0, 0));
            }
        }
        return $normalized;
    }

    /**
     * @param HeartbeatAggregate $left
     * @param HeartbeatAggregate $right
     * @return HeartbeatAggregate
     */
    public static function merge(array $left, array $right): array {
        $left = self::normalize($left);
        $right = self::normalize($right);
        foreach (self::ACTIONS as $action) {
            $left['actions'][$action]['client_timeouts'] = self::addCounts(
                $left['actions'][$action]['client_timeouts'], $right['actions'][$action]['client_timeouts']);
            $left['actions'][$action]['max_age_ms'] = max(
                $left['actions'][$action]['max_age_ms'], $right['actions'][$action]['max_age_ms']);
            foreach (self::PHASES as $phase) {
                $left['actions'][$action]['stranded_by_phase'][$phase] = self::addCounts(
                    $left['actions'][$action]['stranded_by_phase'][$phase],
                    $right['actions'][$action]['stranded_by_phase'][$phase]);
            }
        }
        return $left;
    }

    /** @param HeartbeatAggregate $aggregate */
    public static function hasEvents(array $aggregate): bool {
        foreach (self::ACTIONS as $action) {
            $counts = $aggregate['actions'][$action];
            if ($counts['client_timeouts'] > 0 || $counts['max_age_ms'] > 0) {
                return true;
            }
            foreach (self::PHASES as $phase) {
                if ($counts['stranded_by_phase'][$phase] > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param HeartbeatAggregate $aggregate
     * @return HeartbeatWirePayload
     */
    public static function toWire(array $aggregate, int $now, int $windowStartedAt): array {
        $aggregate = self::normalize($aggregate);
        $actions = array();
        $totalEvents = 0;
        foreach (self::ACTIONS as $action) {
            $counts = $aggregate['actions'][$action];
            $strandedCount = 0;
            foreach ($counts['stranded_by_phase'] as $phaseCount) {
                $strandedCount = self::addCounts($strandedCount, $phaseCount);
            }
            $totalEvents = self::addCounts($totalEvents,
                self::addCounts($counts['client_timeouts'], $strandedCount));
            $actions[] = array(
                'action' => $action,
                'client_timeouts' => $counts['client_timeouts'],
                'stranded_by_phase' => $counts['stranded_by_phase'],
                'max_age_ms' => $counts['max_age_ms'],
            );
        }
        return array(
            'schema_version' => 1,
            'window_started_at' => $windowStartedAt,
            'window_ended_at' => $now,
            'total_events' => $totalEvents,
            'actions' => $actions,
        );
    }

    /**
     * Validate the exact persisted aggregate shape before any rewrite.
     *
     * @param array<mixed, mixed> $aggregate
     */
    public static function isValid(array $aggregate): bool {
        $actions = $aggregate['actions'] ?? null;
        if (!is_array($actions) || count($actions) !== count(self::ACTIONS)) {
            return false;
        }
        foreach (self::ACTIONS as $action) {
            $entry = $actions[$action] ?? null;
            if (!is_array($entry) || count($entry) !== 3) {
                return false;
            }
            if (!self::validCount($entry['client_timeouts'] ?? null)
                    || !self::validCount($entry['max_age_ms'] ?? null)) {
                return false;
            }
            $phases = $entry['stranded_by_phase'] ?? null;
            if (!is_array($phases) || count($phases) !== count(self::PHASES)) {
                return false;
            }
            foreach (self::PHASES as $phase) {
                if (!self::validCount($phases[$phase] ?? null)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function validCount($value): bool {
        return is_int($value) && $value >= 0 && $value <= self::MAX_WIRE_INTEGER;
    }

    private static function increment(int $value): int {
        return $value >= self::MAX_WIRE_INTEGER ? self::MAX_WIRE_INTEGER : $value + 1;
    }

    private static function addCounts(int $left, int $right): int {
        return $right > self::MAX_WIRE_INTEGER - $left ? self::MAX_WIRE_INTEGER : $left + $right;
    }
}
