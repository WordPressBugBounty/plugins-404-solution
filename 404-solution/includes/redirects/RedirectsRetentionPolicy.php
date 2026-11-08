<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes retention-window settings and purge-type selection for redirect maintenance.
 * @phpstan-type LogRetentionPlan array{cap_raw:mixed,bytes_per_row:float|int,target_keep:int,source_rows:int,source_bytes:int}
 */
class ABJ_404_Solution_RedirectsRetentionPolicy {

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param array<string, mixed> $options
     * @param string $optionKey
     * @return int Non-negative retention days; invalid values disable retention.
     */
    public function daysFromOptions(array $options, string $optionKey): int {
        $rawDays = $options[$optionKey] ?? 0;
        return ABJ_404_Solution_ExactInteger::readOr($rawDays, 0, 0);
    }

    /**
     * @param int $days
     * @param int $now
     * @return int|null Unix cutoff timestamp, or null when retention is disabled.
     */
    public function cutoffForDays(int $days, int $now): ?int {
        if ($days <= 0) {
            return null;
        }

        return max(0, $now - ($days * self::SECONDS_PER_DAY));
    }

    /**
     * Keep one stable average-row-size estimate for a pass. InnoDB allocation
     * does not shrink after each DELETE, so recomputing bytes/current-count
     * would progressively delete past the original retention target.
     * A changed cap is applied to that same estimate before the next slice.
     *
     * The exact row count is a full COUNT scan, so it is read only when the
     * plan needs it: to compare against a saved plan, or once the table is
     * over its cap. A table under its cap (or of unknown size) pays nothing.
     * @param array<string,mixed> $options
     * @param mixed $previous
     * @param int $bytes Log table size; -1 when the size could not be read.
     * @param callable(): int $countRows Exact log row count; -1 when unknown.
     * @return LogRetentionPlan|null
     */
    public function sizeRetentionPlan(array $options, $previous, int $bytes, callable $countRows): ?array {
        $raw = $options['maximum_log_disk_usage'] ?? 100;
        $cap = ABJ_404_Solution_ExactInteger::read($raw, 1);
        if ($cap === null || $cap > intdiv(PHP_INT_MAX, 1024 * 1000)) { return null; }
        $average = null;
        $count = 0;
        if ($previous !== null) {
            if (!self::storedSizePlanIsValid($previous)) {
                throw new UnexpectedValueException('Saved log-retention plan is invalid; preserving logs.');
            }
            // Reuse across daily passes while allocation is unchanged. Otherwise
            // deleting yesterday's surplus makes today's average spuriously grow.
            $count = $countRows();
            if ($count <= $previous['source_rows'] && $bytes >= $previous['source_bytes']) {
                if ($raw === $previous['cap_raw']) { return $previous; }
                $average = (float)$previous['bytes_per_row'];
            }
        }
        $maximum = $cap * 1024 * 1000;
        if ($average === null) {
            if ($bytes <= $maximum) { return null; }
            $count = $previous === null ? $countRows() : $count;
            if ($count <= 0) { return null; }
            $average = $bytes / $count;
        }
        $keep = ceil($maximum / $average);
        if (!is_finite($keep) || $keep > PHP_INT_MAX) { return null; }
        return ['cap_raw' => $raw, 'bytes_per_row' => $average, 'target_keep' => max(1, (int)$keep),
            'source_rows' => $previous !== null && $average === (float)$previous['bytes_per_row'] ? $previous['source_rows'] : $count,
            'source_bytes' => $previous !== null && $average === (float)$previous['bytes_per_row'] ? $previous['source_bytes'] : $bytes];
    }

    /**
     * Reject incomplete or inconsistent destructive state before any phase runs.
     * @param mixed $plan
     * @phpstan-assert-if-true =LogRetentionPlan|null $plan
     */
    public static function storedSizePlanIsValid($plan): bool {
        if ($plan === null) { return true; }
        if (!is_array($plan) || !self::validSizePlanSource($plan)) { return false; }
        $cap = ABJ_404_Solution_ExactInteger::read($plan['cap_raw'], 1);
        return $cap !== null && $cap <= intdiv(PHP_INT_MAX, 1024 * 1000)
            && ceil($cap * 1024 * 1000 / $plan['bytes_per_row']) === (float)$plan['target_keep'];
    }

    /**
     * @param array<array-key,mixed> $plan
     * @phpstan-assert-if-true =LogRetentionPlan $plan
     */
    private static function validSizePlanSource(array $plan): bool {
        return array_key_exists('cap_raw', $plan)
            && (is_int($plan['bytes_per_row'] ?? null) || is_float($plan['bytes_per_row'] ?? null))
            && is_finite((float)$plan['bytes_per_row']) && (float)$plan['bytes_per_row'] > 0
            && is_int($plan['target_keep'] ?? null) && $plan['target_keep'] >= 1
            && is_int($plan['source_rows'] ?? null) && $plan['source_rows'] >= 1
            && is_int($plan['source_bytes'] ?? null) && $plan['source_bytes'] >= 1;
    }

    /**
     * Pure purge-type and status selection for redirect purges.
     *
     * Decides invalid_purge_type, no_valid_types, logs_only, or noop ready for
     * mutation, with the exact redirect_types list the mutation will use.
     *
     * @param array{types: array<int, int|string>, purgeType: string} $request
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function purgeSelection(array $request): array {
        $types = isset($request['types']) && is_array($request['types']) ? $request['types'] : array();
        $purgeType = isset($request['purgeType']) && is_scalar($request['purgeType']) ? (string)$request['purgeType'] : '';
        $result = array(
            'status' => 'noop',
            'rows_affected' => 0,
            'redirect_types' => array(),
        );

        if ($purgeType != 'abj404_logs' && $purgeType != 'abj404_redirects') {
            $result['status'] = 'invalid_purge_type';
            return $result;
        }

        $redirectTypes = array();
        foreach ($types as $aType) {
            // A malformed selection must not fabricate status 0 through absint.
            // Keep the existing signed-integer handling for valid selections.
            if (ABJ_404_Solution_ExactInteger::read($aType, -PHP_INT_MAX) === null) {
                continue;
            }
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_404_DISPLAYED)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $result['status'] = 'no_valid_types';
            return $result;
        }

        array_push($redirectTypes, 0);

        $redirectTypes = array_map('absint', $redirectTypes);
        $result['redirect_types'] = $redirectTypes;

        if ($purgeType == 'abj404_logs') {
            $result['status'] = 'logs_only';
            return $result;
        }

        return $result;
    }
}
