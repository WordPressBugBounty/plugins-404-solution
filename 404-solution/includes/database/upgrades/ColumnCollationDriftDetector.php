<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads one plugin table's live column metadata and says whether any character
 * column carries a collation other than the one it is supposed to have.
 *
 * A table whose DEFAULT collation is already right can still hold drifted
 * columns (for example `utf8mb4_bin` on one VARCHAR column while the table
 * default is `utf8mb4_unicode_520_ci`). Those mix collations inside string
 * operations (REPLACE / LOWER) and fail at query time, so the collation repair
 * has to look at the columns, not just the table.
 *
 * The expected collation of a column is the table-wide target unless the
 * permanent DDL declares an explicit collation for that column (for example
 * the ASCII generation keys); the caller supplies those declarations.
 *
 * Collaborator of ABJ_404_Solution_DatabaseUpgradeCollationDrift, which
 * constructs it fresh on every call (never cached) with its current DB core
 * and logger, so it always observes whichever dependencies are current at
 * call time (dbCore / logger can be swapped at runtime via
 * replaceDatabaseUpgradeDependencies() on the owning component).
 */
final class ABJ_404_Solution_ColumnCollationDriftDetector {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $dbCore, ABJ_404_Solution_Logging $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Detect character column collation drift on a table.
     *
     * Some environments can end up with per-column collations that differ from the table default
     * (e.g., `utf8mb4_bin` on one VARCHAR column while the table default is `utf8mb4_unicode_520_ci`).
     * This causes MySQL errors in string operations (REPLACE/LOWER) that mix collations.
     *
     * @param string $tableName Fully qualified table name (with prefix)
     * @param array{collation:string,declarations:array<string,array{collation:string,definition:string}>} $options Expected default and explicit contracts.
     * @return bool|null True if mismatch found, false if all match, null if query failed
     */
    public function tableHasMismatch($tableName, array $options) {
        $targetCollation = $options['collation'];
        $declaredColumns = $options['declarations'];
        $results = $this->dbCore->queryAndGetResults("SHOW FULL COLUMNS FROM " . $tableName);
        $lastError = isset($results['last_error']) && is_scalar($results['last_error'])
            ? (string)$results['last_error']
            : '';
        if (($results['query_succeeded'] ?? null) !== true
                || (array_key_exists('timed_out', $results) && $results['timed_out'] !== false)
                || $lastError !== '') {
            $this->logger->warn("Failed to read columns for {$tableName}: " . $lastError);
            return null;
        }
        if (!array_key_exists('rows', $results) || !is_array($results['rows'])) {
            $this->logger->warn("SHOW FULL COLUMNS returned malformed rows for {$tableName}");
            return null;
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $results['rows'];
        if (empty($rows)) {
            $this->logger->warn("SHOW FULL COLUMNS returned no rows for {$tableName}");
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $this->logger->warn("SHOW FULL COLUMNS returned a malformed row for {$tableName}");
                return null;
            }
            $normalizedRow = array_change_key_case($row, CASE_LOWER);
            if (!array_key_exists('collation', $normalizedRow)) {
                $this->logger->warn("SHOW FULL COLUMNS returned no Collation value for {$tableName}");
                return null;
            }
            $rawColCollation = $normalizedRow['collation'];
            if ($rawColCollation === null) {
                continue; // Non-character columns
            }
            if (!is_string($rawColCollation) || trim($rawColCollation) === '') {
                $this->logger->warn("SHOW FULL COLUMNS returned an invalid Collation value for {$tableName}");
                return null;
            }
            $colCollation = trim($rawColCollation);

            $expected = $this->expectedColumnCollation($row, $targetCollation, $declaredColumns);
            if ($colCollation !== $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row Server column metadata, with engine-dependent key casing.
     * @param string $default Table-wide target collation.
     * @param array<string,array{collation:string,definition:string}> $declarations Explicit schema contracts.
     */
    private function expectedColumnCollation(array $row, string $default, array $declarations): string {
        $row = array_change_key_case($row, CASE_LOWER);
        $field = is_string($row['field'] ?? null) ? strtolower($row['field']) : '';
        return $declarations[$field]['collation'] ?? $default;
    }
}
