<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the intersection of two live table column sets for schema-tolerant
 * data copying. Uncertain or empty metadata never authorizes a copy.
 */
final class ABJ_404_Solution_DatabaseColumnIntersection {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /**
     * Bind the database connection used for live column metadata.
     *
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $dbCore) {
        $this->dbCore = $dbCore;
    }

	/**
	 * Get the list of column names that exist in both tables.
	 * Used by table-copy migrations to build column-matched INSERTs
	 * that survive schema drift between plugin versions.
	 *
	 * @param string $tableA
	 * @param string $tableB
	 * @return array{columns: array<int, string>, uncertain: bool}
	 */
	public function between(string $tableA, string $tableB): array {
		$colsA = $this->getTableColumns($tableA);
		$colsB = $this->getTableColumns($tableB);

		if ($colsA['uncertain'] || $colsB['uncertain']) {
			return array('columns' => array(), 'uncertain' => true);
		}

		return array(
			'columns' => array_values(array_intersect($colsA['columns'], $colsB['columns'])),
			'uncertain' => false,
		);
	}

	/**
	 * Get column names for a table via SHOW COLUMNS.
	 *
	 * @param string $tableName
	 * @return array{columns: array<int, string>, uncertain: bool}
	 */
	private function getTableColumns(string $tableName): array {
		$result = $this->dbCore->queryAndGetResults(
			"SHOW COLUMNS FROM `{$tableName}`",
			['ignore_errors' => ["doesn't exist", "not found"]]
		);

		if (!$this->queryResultSucceeded($result) || !is_array($result['rows'] ?? null) || empty($result['rows'])) {
			return array('columns' => array(), 'uncertain' => true);
		}

		$columns = [];
		$malformed = false;
		$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				$malformed = true;
				continue;
			}
			// SHOW COLUMNS returns 'Field' key - case-insensitive lookup.
			$colName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'field' && is_scalar($value)) {
					$colName = strtolower((string)$value);
					break;
				}
			}
			if ($colName !== null) {
				$columns[] = $colName;
			} else {
				$malformed = true;
			}
		}

		return array('columns' => $columns, 'uncertain' => $malformed || empty($columns));
	}

	/** @param array<string,mixed> $result */
	private function queryResultSucceeded(array $result): bool {
		return ($result['query_succeeded'] ?? null) === true
			&& is_string($result['last_error'] ?? null)
			&& $result['last_error'] === ''
			&& (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
	}

}
