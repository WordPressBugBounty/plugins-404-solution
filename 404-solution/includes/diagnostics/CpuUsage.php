<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Process CPU time from a getrusage()-shaped array, in whole milliseconds.
 *
 * Every diagnostic that reports CPU (the per-action readings the request
 * recorder takes, the boot-to-now delta of the phase timeline, the time-limit
 * evidence) reads it through here, so one rule decides how microseconds
 * become milliseconds: each of user and system is truncated, never rounded,
 * before they are added. Three private copies used to disagree (one rounded),
 * which made the same request report CPU figures a millisecond apart on
 * different surfaces.
 */
final class ABJ_404_Solution_CpuUsage {

    /**
     * User plus system CPU in whole ms, or null when the reading is absent or
     * either of its four numbers is missing or not numeric.
     *
     * @param mixed $usage a getrusage() array ('ru_utime.tv_sec' style keys) or anything else.
     * @return int|null
     */
    public static function totalMs($usage): ?int {
        if (!is_array($usage)) {
            return null;
        }
        $user = self::timevalMs($usage, 'ru_utime');
        $system = self::timevalMs($usage, 'ru_stime');
        if ($user === null || $system === null) {
            return null;
        }
        return $user + $system;
    }

    /**
     * One tv_sec/tv_usec pair in whole ms (sub-millisecond part truncated), or
     * null when either half is not numeric.
     *
     * @param array<mixed> $usage
     * @param string $prefix 'ru_utime' or 'ru_stime'.
     * @return int|null
     */
    private static function timevalMs(array $usage, string $prefix): ?int {
        $sec = $usage[$prefix . '.tv_sec'] ?? null;
        $usec = $usage[$prefix . '.tv_usec'] ?? null;
        if (!is_numeric($sec) || !is_numeric($usec)) {
            return null;
        }
        return (int)$sec * 1000 + intdiv((int)$usec, 1000);
    }
}
