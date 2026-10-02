<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How per-callback timing ticks are laid into a WordPress action's WP_Hook
 * registry, and how the callbacks between ticks are read back after the
 * fact. The one place that knows the registry's shape (callbacks keyed by
 * priority, then by unique id, iterated priority by priority, each priority
 * array iterated by value).
 *
 * Layout: every not-yet-running priority array becomes
 * [lead tick, cb1, tick, cb2, tick, ...]. Original entries keep their keys,
 * callables and accepted_args, so has_action, remove_action, argument
 * passing and by-reference arguments behave exactly as before; nothing is
 * wrapped. The action's head (PHP_INT_MIN) and tail (PHP_INT_MAX) callbacks
 * bound the whole run. Used by ABJ_404_Solution_RequestTimeRecorder.
 */
final class ABJ_404_Solution_HookTickLayout {

    /** Key of the tick placed before each priority's first callback. */
    const LEAD_KEY = 'abj404_rt_lead';

    /** Key prefix of every tick key (the lead's included). */
    const TICK_PREFIX = 'abj404_rt_';

    /**
     * Interleave $tickEntry into every priority array of $registry except
     * the one holding $headId (already being iterated) and any already
     * interleaved.
     *
     * @param mixed $registry the action's WP_Hook (anything else is ignored).
     * @param string $headId registry key of the head callback.
     * @param array<string, mixed> $tickEntry the entry to insert.
     * @return void
     */
    public static function interleave($registry, string $headId, array $tickEntry): void {
        if (!is_object($registry) || !isset($registry->callbacks) || !is_array($registry->callbacks)) {
            return;
        }
        foreach ($registry->callbacks as $priority => $entries) {
            if (!is_array($entries) || isset($entries[$headId]) || isset($entries[self::LEAD_KEY])) {
                continue;
            }
            $rebuilt = array(self::LEAD_KEY => $tickEntry);
            $n = 0;
            foreach ($entries as $idx => $entry) {
                $rebuilt[$idx] = $entry;
                $rebuilt[self::TICK_PREFIX . $n++] = $tickEntry;
            }
            $registry->callbacks[$priority] = $rebuilt;
        }
    }

    /**
     * Walk the registry in core's iteration order and split the real
     * entries into the groups that ran between consecutive ticks. Group 0
     * starts after the head; the tail and (when interleaved) every
     * TICK_PREFIX key end a group. On an action that was not interleaved
     * every callback lands in group 0. Null when the head is missing.
     *
     * @param array<mixed, mixed> $callbacks the registry's callbacks property.
     * @param string $headId
     * @param string $tailId
     * @param bool $interleaved
     * @return array<int, array<int, mixed>>|null groups of callables.
     */
    public static function groupsBetweenTicks(array $callbacks, string $headId, string $tailId, bool $interleaved): ?array {
        $prefixLength = strlen(self::TICK_PREFIX);
        $groups = array();
        $current = null;
        foreach ($callbacks as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $idx => $entry) {
                if ($idx === $headId) {
                    $current = array();
                    continue;
                }
                if ($current === null) {
                    continue;
                }
                $isTick = $idx === $tailId
                    || ($interleaved && is_string($idx) && strncmp($idx, self::TICK_PREFIX, $prefixLength) === 0);
                if ($isTick) {
                    $groups[] = $current;
                    $current = array();
                    continue;
                }
                $current[] = is_array($entry) && array_key_exists('function', $entry) ? $entry['function'] : $entry;
            }
        }
        if ($current === null) {
            return null;
        }
        $groups[] = $current;
        return $groups;
    }
}
