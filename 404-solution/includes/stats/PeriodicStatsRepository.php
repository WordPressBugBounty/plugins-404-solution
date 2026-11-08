<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads periodic counters directly from the log table.
 *
 * This repository owns the raw periodic SQL, coherent multi-period batch
 * normalization, and the previous per-period recovery path. Dashboard/cache
 * assembly remains in ABJ_404_Solution_StatsReadRepository.
 */
class ABJ_404_Solution_PeriodicStatsRepository {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_Logging $logging
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $dbCore, $logging) {
        $this->dbCore = $dbCore;
        $this->logger = $logging;
    }

    /**
     * Read one periodic summary and retain its public payload shape.
     *
     * @param int $sinceTimestamp
     * @param string $notFoundDest
     * @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int}
     */
    public function getSummary($sinceTimestamp, $notFoundDest = '404') {
        return $this->readPeriodicStatsAggregate($sinceTimestamp, $notFoundDest)['stats'];
    }

    /** @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int} */
    private function zeroPeriodicStats(): array {
        return array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );
    }

    /**
     * Keep the earliest timestamp and counters in the same database snapshot.
     * @param int $sinceTimestamp
     * @param string $notFoundDest
     * @return array{stats:array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int},earliest:?int,earliestKnown:bool}
     */
    private function readPeriodicStatsAggregate($sinceTimestamp, $notFoundDest): array {
        $sinceTimestamp = absint($sinceTimestamp);
        $notFoundDest = sanitize_text_field((string)$notFoundDest);
        if ($notFoundDest === '') {
            $notFoundDest = '404';
        }

        $zero = $this->zeroPeriodicStats();

        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $sql = "SELECT
                COUNT(CASE WHEN dest_url = %s THEN 1 END) AS disp404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN requested_url END) AS distinct404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN user_ip END) AS visitors404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN referrer END) AS refer404,
                COUNT(CASE WHEN dest_url <> %s THEN 1 END) AS redirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN requested_url END) AS distinctredirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN user_ip END) AS distinctvisitors,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN referrer END) AS distinctrefer,
                MIN(timestamp) AS earliest_timestamp
            FROM {$logsTable}
            WHERE timestamp >= %d";

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array(
                $notFoundDest, $notFoundDest, $notFoundDest, $notFoundDest,
                $notFoundDest, $notFoundDest, $notFoundDest, $notFoundDest,
                $sinceTimestamp,
            ),
        ));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array('stats' => $zero, 'earliest' => null, 'earliestKnown' => false);
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return array('stats' => $zero, 'earliest' => null, 'earliestKnown' => false);
        }
        $row = $rows[0];

        foreach ($zero as $key => $unused) {
            $zero[$key] = array_key_exists($key, $row) ? $this->toInt($row[$key], 0) : 0;
        }

        return array(
            'stats' => $zero,
            'earliest' => isset($row['earliest_timestamp']) ? $this->toInt($row['earliest_timestamp'], 0) : null,
            'earliestKnown' => array_key_exists('earliest_timestamp', $row),
        );
    }

    /**
     * Read one atomic batch and use individual-period recovery when it fails
     * or returns malformed data.
     * @param array<string,int> $thresholds
     * @param string $notFoundDest
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}
     */
    public function readSummariesWithRecovery(array $thresholds, $notFoundDest = '404') {
        try {
            $batch = $this->readPeriodicStatsBatch($thresholds, $notFoundDest);
            if ($batch !== null) {
                return $batch;
            }
        } catch (Throwable $e) {
            $this->logger->warn('PeriodicStatsRepository: periodic batch failed; using individual-period recovery (code '
                . (string)$e->getCode() . '): ' . $e->getMessage());
        }

        return $this->readPeriodicStatsForThresholdsLegacy($thresholds, $notFoundDest);
    }

    /**
     * Preserve the previous per-period reads as explicit recovery for a batch
     * timeout, query error, or malformed result.
     *
     * @param array<string,int> $thresholds
     * @param string $notFoundDest
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}
     */
    private function readPeriodicStatsForThresholdsLegacy(array $thresholds, $notFoundDest): array {
        $all = $this->readPeriodicStatsAggregate($thresholds['all'], $notFoundDest);
        $periods = array();
        $periodStatsByTimestamp = array();
        foreach ($thresholds as $periodKey => $timestamp) {
            if ($periodKey === 'all' || ($all['earliestKnown']
                    && ($all['earliest'] === null || $all['earliest'] >= $timestamp))) {
                $periods[$periodKey] = $all['stats'];
            } else {
                if (!array_key_exists($timestamp, $periodStatsByTimestamp)) {
                    $periodStatsByTimestamp[$timestamp] = $this->getSummary($timestamp, $notFoundDest);
                }
                $periods[$periodKey] = $periodStatsByTimestamp[$timestamp];
            }
        }
        return array(
            'today' => $periods['today'],
            'month' => $periods['month'],
            'year' => $periods['year'],
            'all' => $periods['all'],
        );
    }

    /**
     * Read all four periodic summaries from one statement so a dashboard
     * snapshot cannot mix rows from different moments.
     *
     * @param array<string,int> $thresholds
     * @param string $notFoundDest
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}|null
     */
    private function readPeriodicStatsBatch(array $thresholds, $notFoundDest): ?array {
        $notFoundDest = sanitize_text_field((string)$notFoundDest);
        if ($notFoundDest === '') {
            $notFoundDest = '404';
        }

        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $queryParams = array();
        $parts = array();
        $periods = array('all', 'today', 'month', 'year');

        $hitsColumns = array();
        foreach ($periods as $periodKey) {
            $hitsColumns[] = 'COUNT(CASE WHEN dest_url = %s AND timestamp >= %d THEN 1 END) AS ' . $periodKey . '_404';
            $queryParams[] = $notFoundDest;
            $queryParams[] = $thresholds[$periodKey];
            $hitsColumns[] = 'COUNT(CASE WHEN dest_url <> %s AND timestamp >= %d THEN 1 END) AS ' . $periodKey . '_redirected';
            $queryParams[] = $notFoundDest;
            $queryParams[] = $thresholds[$periodKey];
        }
        $parts[] = "SELECT 'hits' AS dimension_id,\n" . implode(",\n", $hitsColumns) . "\nFROM {$logsTable}";

        $dimensions = array(
            'requested_url' => 'requested_url',
            'user_ip' => 'user_ip',
            'referrer' => 'referrer',
        );
        foreach ($dimensions as $dimensionId => $column) {
            $outerColumns = array();
            foreach ($periods as $periodKey) {
                $outerColumns[] = 'COUNT(CASE WHEN latest_404 >= %d THEN 1 END) AS ' . $periodKey . '_404';
                $queryParams[] = $thresholds[$periodKey];
                $outerColumns[] = 'COUNT(CASE WHEN latest_redirect >= %d THEN 1 END) AS ' . $periodKey . '_redirected';
                $queryParams[] = $thresholds[$periodKey];
            }
            $queryParams[] = $notFoundDest;
            $queryParams[] = $notFoundDest;
            $queryParams[] = $thresholds['all'];
            // Forcing a million-row sort for a few referring URLs adds avoidable
            // work; keep the bulk-sort hint for high-cardinality URL/IP groups.
            $sortHint = $dimensionId === 'referrer' ? '' : 'SQL_BIG_RESULT ';
            $parts[] = "SELECT '{$dimensionId}' AS dimension_id,\n" . implode(",\n", $outerColumns) . "\n"
                . "FROM (SELECT {$sortHint}{$column},\n"
                . "MAX(CASE WHEN dest_url = %s THEN timestamp END) AS latest_404,\n"
                . "MAX(CASE WHEN dest_url <> %s THEN timestamp END) AS latest_redirect\n"
                . "FROM {$logsTable}\n"
                . "WHERE timestamp >= %d AND {$column} IS NOT NULL\n"
                . "GROUP BY {$column}\n"
                . "ORDER BY NULL) AS latest";
        }

        $result = $this->dbCore->queryAndGetResults(implode("\nUNION ALL\n", $parts), array(
            'query_params' => $queryParams,
        ));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return null;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $normalized = $this->normalizePeriodicBatchRows($rows);
        if ($normalized === null) {
            $this->logger->warn('PeriodicStatsRepository: periodic batch returned malformed rows; using individual-period recovery.');
        }
        return $normalized;
    }

    /**
     * @param array<int,mixed> $rows
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}|null
     */
    private function normalizePeriodicBatchRows(array $rows): ?array {
        $countKeys = array();
        foreach (array('all', 'today', 'month', 'year') as $periodKey) {
            $countKeys[] = $periodKey . '_404';
            $countKeys[] = $periodKey . '_redirected';
        }
        $knownDimensions = array('hits', 'requested_url', 'user_ip', 'referrer');
        $byDimension = array();
        if (count($rows) !== count($knownDimensions)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['dimension_id'] ?? null)
                    || !in_array($row['dimension_id'], $knownDimensions, true)
                    || array_key_exists($row['dimension_id'], $byDimension)) {
                return null;
            }
            $normalizedRow = array();
            foreach ($countKeys as $countKey) {
                if (!array_key_exists($countKey, $row)) {
                    return null;
                }
                $count = $this->nonNegativeBatchCount($row[$countKey]);
                if ($count === null) {
                    return null;
                }
                $normalizedRow[$countKey] = $count;
            }
            $byDimension[$row['dimension_id']] = $normalizedRow;
        }
        if (!isset($byDimension['hits'], $byDimension['requested_url'], $byDimension['user_ip'], $byDimension['referrer'])) {
            return null;
        }

        $periods = array();
        foreach (array('all', 'today', 'month', 'year') as $periodKey) {
            $periods[$periodKey] = array(
                'disp404' => $byDimension['hits'][$periodKey . '_404'],
                'distinct404' => $byDimension['requested_url'][$periodKey . '_404'],
                'visitors404' => $byDimension['user_ip'][$periodKey . '_404'],
                'refer404' => $byDimension['referrer'][$periodKey . '_404'],
                'redirected' => $byDimension['hits'][$periodKey . '_redirected'],
                'distinctredirected' => $byDimension['requested_url'][$periodKey . '_redirected'],
                'distinctvisitors' => $byDimension['user_ip'][$periodKey . '_redirected'],
                'distinctrefer' => $byDimension['referrer'][$periodKey . '_redirected'],
            );
        }
        return array(
            'today' => $periods['today'],
            'month' => $periods['month'],
            'year' => $periods['year'],
            'all' => $periods['all'],
        );
    }

    /** @param mixed $value @return int|null */
    private function nonNegativeBatchCount($value): ?int {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        return ABJ_404_Solution_ExactInteger::read($value, 0);
    }


    /** @param mixed $value @param int $default @return int */
    private function toInt($value, int $default): int {
        if ($value === null) {
            return $default;
        }
        if (is_scalar($value)) {
            return intval($value);
        }
        return $default;
    }
}
