<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads redirects that are eligible for server-format export.
 *
 * Returns the rows as stored. Deciding where an exported redirect points is
 * not a read: ABJ_404_Solution_RedirectExportDestination (Core) does it.
 */
class ABJ_404_Solution_RedirectExportReader {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Manual and regex redirects that are eligible for export, in URL order.
     *
     * cached_url is the permalink-cache URL, joined for post redirects only
     * (the cache is keyed by wp_posts.ID, and a category or tag redirect's
     * final_dest is a term id); it is '' for every other row.
     *
     * @return array<int, array{source: string, code: int, type: int, final_dest: string, cached_url: string, is_regex: bool}>
     */
    public function getExportableRedirectRows(): array {
        $manualStatus = (int)ABJ404_STATUS_MANUAL;
        $regexStatus  = (int)ABJ404_STATUS_REGEX;

        $rows = $this->queryExportableRedirectRows($manualStatus, $regexStatus);
        if (empty($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = $this->mapExportableRedirectRow($this->exportAssocRow($row), $regexStatus);
        }

        return $result;
    }

    /**
     * @param int $manualStatus
     * @param int $regexStatus
     * @return array<int, mixed>
     */
    private function queryExportableRedirectRows(int $manualStatus, int $regexStatus): array {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $cacheTable     = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');

        // The cache is keyed by wp_posts.ID, so it is joined for post redirects
        // only; a category or tag redirect's final_dest is a term id.
        $queryResult = $this->dbCore->queryAndGetResults(
            "SELECT r.url, r.status, r.type, r.final_dest, r.code, r.disabled,
                    pc.url AS cached_url
             FROM {$redirectsTable} r
             LEFT JOIN {$cacheTable} pc ON r.final_dest = pc.id AND r.type = %d
             WHERE r.status IN (%d, %d)
               AND (r.disabled IS NULL OR r.disabled = 0)
               AND r.url IS NOT NULL AND r.url != ''
             ORDER BY r.url",
            array('query_params' => array((int)ABJ404_TYPE_POST, $manualStatus, $regexStatus))
        );

        $rows = $queryResult['rows'] ?? array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function exportAssocRow(array $row): array {
        $assoc = array();
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $assoc[$key] = $value;
            }
        }
        return $assoc;
    }

    /**
     * @param array<string, mixed> $row
     * @param int $regexStatus
     * @return array{source: string, code: int, type: int, final_dest: string, cached_url: string, is_regex: bool}
     */
    private function mapExportableRedirectRow(array $row, int $regexStatus): array {
        return array(
            'source'     => $this->exportRowString($row, 'url'),
            'code'       => $this->exportRowInt($row, 'code', 301),
            'type'       => $this->exportRowInt($row, 'type', 0),
            'final_dest' => $this->exportRowString($row, 'final_dest'),
            'cached_url' => $this->exportRowString($row, 'cached_url'),
            'is_regex'   => ($this->exportRowInt($row, 'status', 0) === $regexStatus),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param string $key
     * @param string $default
     * @return string
     */
    private function exportRowString(array $row, string $key, string $default = ''): string {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $key
     * @param int $default
     * @return int
     */
    private function exportRowInt(array $row, string $key, int $default): int {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return ABJ_404_Solution_ExactInteger::readOr($value, PHP_INT_MIN, $default);
    }
}
