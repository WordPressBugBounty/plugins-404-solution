<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseUpgradeSchemaDiff extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** Lists every getTableDifferences() result carries; the sink applies nothing without all of them. */
    private const REQUIRED_DIFFERENCE_KEYS = array('dropTheseColumns', 'updateTheseColumns',
	'createTheseColumns', 'goalTableMatchesColumnDDL', 'existingTableMatchesColumnDDL',
	'goalTableMatches', 'goalTableMatchesColumnNames');

    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return void
     */
    function verifyColumns($tableName, $createTableStatementGoal) {
	$tableName = is_scalar($tableName) ? (string)$tableName : '';
	$updatesWereNeeded = false;

	// find the differences
	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
	$tableDifferences = is_array($tableDifferences) ? $tableDifferences : [];
	$updateCols = is_array($tableDifferences['updateTheseColumns']) ? $tableDifferences['updateTheseColumns'] : [];
	$createCols = is_array($tableDifferences['createTheseColumns']) ? $tableDifferences['createTheseColumns'] : [];
	if (count($updateCols) > 0 ||
		count($createCols) > 0) {
		$updatesWereNeeded = true;
	}
	// make the changes
	$this->updateATableBasedOnDifferences($tableName, $tableDifferences);

	// Data migrations can outlive the DDL request that created their column.
	// Keep their retry path reachable on every schema verification.
	if (strpos($tableName, 'abj404_logsv2') !== false &&
		in_array('min_log_id',
			ABJ_404_Solution_CreateTableColumnParser::columnNames($createTableStatementGoal),
			true)) {
		$this->upgrades()->addedColumnBackfillUpgrade()->runPendingBackfills($tableName);
	}

	// verify that there are now no changes that need to be made.
	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
	$tableDifferences = is_array($tableDifferences) ? $tableDifferences : [];
	$updateCols = is_array($tableDifferences['updateTheseColumns']) ? $tableDifferences['updateTheseColumns'] : [];
	$createCols = is_array($tableDifferences['createTheseColumns']) ? $tableDifferences['createTheseColumns'] : [];

	if (count($updateCols) > 0 ||
		count($createCols) > 0) {

		if (count($createCols) === 0 && $this->isOlderBuildThanRecordedSchema()) {
			// An older build deliberately leaves existing columns as the newer
			// build shaped them, so their remaining differences are expected.
			// Every MISSING column is still created, so a missing column that
			// persists is a real failure and takes the warning branch below.
			$this->logger->infoMessage("The " . $tableName . " table still differs from this " .
				"older build's schema in " . count($updateCols) . " existing column(s); they " .
				"were left unchanged on purpose because a newer plugin version shaped them.");
			return;
		}

		// Persistent post-update diff is usually a benign DDL-normalizer mismatch
		// (parser misreads a comment, column landed in a slightly-different form).
		// Plugin keeps functioning, so log at warn (stays in debug log without
		// crossing the email-threshold reporter). Defensive coding philosophy #8.
		$this->logger->warn("There are still differences after updating the " .
			$tableName . " table. " . print_r($tableDifferences, true));

	} else if ($updatesWereNeeded) {
		$this->logger->infoMessage("No more differences found after updating the " .
			$tableName . " table columns. All is well.");
	}
    }

    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return array<string, mixed>
     */
    function getTableDifferences($tableName, $createTableStatementGoal) {

	// get the current create table statement
	$existingTableSQL = $this->dbCore->tableNameResolver()->getCreateTableDDL($tableName);

	$existingTableSQL = strtolower($this->removeCommentsFromColumns($existingTableSQL));
	$createTableStatementGoal = strtolower(
		$this->removeCommentsFromColumns($createTableStatementGoal));

	// remove the "COLLATE xxx" from the columns.
	$removeCollatePattern = '/collate[= ]\w+ ?/';
	$existingTableSQL = preg_replace($removeCollatePattern, "", $existingTableSQL) ?? '';
	$createTableStatementGoal = preg_replace($removeCollatePattern, "", $createTableStatementGoal) ?? '';

	// remove the int size format from columns because it doesn't matter.
	$removeIntSizePattern = '/( \w*?int)(\(\d+\))/m';
	$existingTableSQL = preg_replace($removeIntSizePattern, "$1", $existingTableSQL) ?? '';
	$createTableStatementGoal = preg_replace($removeIntSizePattern, "$1", $createTableStatementGoal) ?? '';

	// MySQL's SHOW CREATE TABLE omits "DEFAULT NULL" for TEXT/BLOB columns
	// (it's implicit). Normalize both sides so this doesn't flag as a mismatch.
	$removeTextDefaultNull = '/(text|blob|mediumtext|longtext|tinytext|mediumblob|longblob|tinyblob)\s+default\s+null/';
	$existingTableSQL = preg_replace($removeTextDefaultNull, "$1", $existingTableSQL) ?? $existingTableSQL;
	$createTableStatementGoal = preg_replace($removeTextDefaultNull, "$1", $createTableStatementGoal) ?? $createTableStatementGoal;

	// Split each statement into its column definitions. The parser rejects
	// index and constraint declarations by the keyword they start with, so an
	// index's trailing USING BTREE can never be read as a column named `using`
	// (report 286: that misread had the upgrade issue
	// `alter table wp_abj404_redirects add using btree` on every run).
	$existingTableMatches = $this->columnMatchGroups($existingTableSQL);
	$goalTableMatches = $this->columnMatchGroups($createTableStatementGoal);

	// get the matches.
	$goalTableMatchesColumnNames = $goalTableMatches[2];
	$existingTableMatchesColumnNames = $existingTableMatches[2];

	// Safety guard: if the goal DDL produced zero column names the parser could
	// not read it (e.g. malformed or unparseable DDL). In that case never drop
	// any existing columns — an empty goal list would otherwise flag every real
	// column as "extra" and wipe the table.
	if (empty($goalTableMatchesColumnNames) && !empty($existingTableMatchesColumnNames)) {
		$this->logger->errorMessage("Goal DDL for " . $tableName .
			" produced no column matches -- the DDL may be malformed or unparseable. " .
			"Skipping column comparison to prevent data loss.");
		return array("updateTheseColumns" => [],
			"dropTheseColumns" => [],
			"keepTheseUnknownColumns" => [],
			"createTheseColumns" => [],
			"goalTableMatchesColumnDDL" => [],
			"existingTableMatchesColumnDDL" => [],
			"goalTableMatches" => $goalTableMatches,
			"goalTableMatchesColumnNames" => []
		);
	}

	// A live column this build's DDL does not declare is dropped only when this
	// plugin deliberately retired it. Any other one is unknown -- a newer plugin
	// version or the site owner may have added it -- so it is reported under
	// keepTheseUnknownColumns and left alone.
	$extraColumns = ABJ_404_Solution_RetiredColumns::partition($tableName,
		array_diff($existingTableMatchesColumnNames, $goalTableMatchesColumnNames));
	$dropTheseColumns = $extraColumns['retired'];
	$keepTheseUnknownColumns = $extraColumns['unknown'];

	// see if some columns need to be created.
	$createTheseColumns = array_diff($goalTableMatchesColumnNames,
		$existingTableMatchesColumnNames);

	// get the ddl for each column
	$goalTableMatchesColumnDDL = $goalTableMatches[1];
	$existingTableMatchesColumnDDL = $existingTableMatches[1];

	// normalize minor differences between mysql versions (strip backticks so DDL
	// files using either quoting style compare equal to SHOW CREATE TABLE output)
	$goalTableMatchesColumnDDL = array_map([$this, 'normalizeColumnDDL'], $goalTableMatchesColumnDDL);
	$existingTableMatchesColumnDDL = array_map([$this, 'normalizeColumnDDL'], $existingTableMatchesColumnDDL);

	// see if anything needs to be updated or created.
	$updateTheseColumns = array_diff($goalTableMatchesColumnDDL,
		$existingTableMatchesColumnDDL);

	// wrap the results
	$results = array("updateTheseColumns" => $updateTheseColumns,
			"dropTheseColumns" => $dropTheseColumns,
			"keepTheseUnknownColumns" => $keepTheseUnknownColumns,
			"createTheseColumns" => $createTheseColumns,
			"goalTableMatchesColumnDDL" => $goalTableMatchesColumnDDL,
			"existingTableMatchesColumnDDL" => $existingTableMatchesColumnDDL,
			"goalTableMatches" => $goalTableMatches,
			"goalTableMatchesColumnNames" => $goalTableMatchesColumnNames
	);
	return $results;
    }

    /**
     * The column definitions of one CREATE TABLE statement, in the positional
     * layout the rest of this class and its tests read:
     *
     *   [0] the whole entry, [1] the same entry (name + type), [2] the column
     *   name on its own, [3] the type on its own.
     *
     * Kept because updateATableBasedOnDifferences() locates a column by index
     * across [1] and [2], so the two lists have to stay positionally aligned;
     * the parser guarantees that by construction.
     *
     * @param string $createTableSql
     * @return array<int, array<int, string>>
     */
    private function columnMatchGroups($createTableSql) {
	$groups = array(array(), array(), array(), array());
	foreach (ABJ_404_Solution_CreateTableColumnParser::fromCreateTableSql($createTableSql)
		as $column) {
		$groups[0][] = $column['definition'];
		$groups[1][] = $column['definition'];
		$groups[2][] = $column['name'];
		$groups[3][] = $column['type'];
	}
	return $groups;
    }

    /**
     * Apply a getTableDifferences() result. A result missing any required list
     * is refused whole (logged at warn): without createTheseColumns, for one,
     * every goal column would look existing and be sent down the CHANGE path.
     *
     * @param string $tableName
     * @param array<string, mixed> $tableDifferences
     * @return void
     */
    function updateATableBasedOnDifferences($tableName, $tableDifferences) {
	$tableName = is_scalar($tableName) ? (string)$tableName : '';
	$tableDifferences = is_array($tableDifferences) ? $tableDifferences : [];
	$missingKeys = array_values(array_filter(self::REQUIRED_DIFFERENCE_KEYS,
		function ($key) use ($tableDifferences) { return !is_array($tableDifferences[$key] ?? null); }));
	if (count($missingKeys) > 0) {
		$this->logger->warn("Schema differences for " . $tableName . " lack " . implode(', ', $missingKeys) .
			", so none of them were applied.");
		return;
	}

	$updateTheseColumns = $this->differenceList($tableDifferences, 'updateTheseColumns');
	$createTheseColumns = $this->differenceList($tableDifferences, 'createTheseColumns');
	$goalTableMatchesColumnDDL = $this->differenceList($tableDifferences, 'goalTableMatchesColumnDDL');
	$existingTableMatchesColumnDDL = $this->differenceList($tableDifferences, 'existingTableMatchesColumnDDL');
	/** @var array<int, array<int, mixed>> $goalTableMatches */
	$goalTableMatches = is_array($tableDifferences['goalTableMatches'] ?? null) ? $tableDifferences['goalTableMatches'] : [];
	$goalTableMatchesColumnNames = $this->differenceList($tableDifferences, 'goalTableMatchesColumnNames');

	// Only columns this plugin deliberately retired may be dropped. The caller's
	// dropTheseColumns list is re-filtered here rather than trusted, so no caller
	// can turn an unknown column into a drop. Anything not retired is unknown (a
	// newer plugin version or the site owner may own it) and is kept.
	$dropColumns = ABJ_404_Solution_RetiredColumns::partition($tableName,
		$this->differenceList($tableDifferences, 'dropTheseColumns'));
	$this->logKeptUnknownColumns($tableName, array_merge(
		$this->differenceList($tableDifferences, 'keepTheseUnknownColumns'),
		$dropColumns['unknown']));

	// An older build must not reshape a schema a newer build already shaped: it
	// would drop or rewrite columns the newer build relies on. Missing columns are
	// still created below, so the older build can still run.
	$isOlderBuild = $this->isOlderBuildThanRecordedSchema();
	$skippedByOlderBuildGuard = [];
	if ($isOlderBuild) {
		$skippedByOlderBuildGuard = array_map(
			function ($colName) { return "drop " . $colName; }, $dropColumns['retired']);
	} else {
		$this->dropRetiredColumns($tableName, $dropColumns['retired'],
			count($existingTableMatchesColumnDDL));
	}

	// say why we're doing what we're doing.
	if (count($updateTheseColumns) > 0 && !$isOlderBuild) {
		$this->logger->infoMessage($this->getUpgradeRuntimeId() . ": On " . $tableName .
			" I'm updating various columns because we want: \n`" .
			print_r($goalTableMatchesColumnDDL, true) . "\n but we have: \n" .
			print_r($existingTableMatchesColumnDDL, true));
	}

	// create missing columns
	// Normalize $goalMatchesSub using the same normalizeColumnDDL() that
	// getTableDifferences() uses, so array_search() can find the right index.
	$goalMatchesSub = is_array($goalTableMatches[1] ?? null) ? $this->stringValues($goalTableMatches[1]) : [];
	$goalMatchesSub = array_map([$this, 'normalizeColumnDDL'], $goalMatchesSub);
	foreach ($updateTheseColumns as $colDDL) {
		$colDDL = (string)$colDDL;
		// find the colum name.
		$matchIndex = array_search($colDDL, $goalMatchesSub);
		if ($matchIndex === false) {
			$this->logger->warn("Could not match column DDL to goal schema, skipping: " . $colDDL);
			continue;
		}
		$colName = is_scalar($goalTableMatchesColumnNames[$matchIndex] ?? null)
			? (string)$goalTableMatchesColumnNames[$matchIndex]
			: '';

		// if the column exists then update it. otherwise create it.
		if (!in_array($colName, $createTheseColumns) && $isOlderBuild) {
			// an older build leaves an existing column as the newer build shaped it.
			$skippedByOlderBuildGuard[] = "change " . $colName;
			continue;
		} else if (!in_array($colName, $createTheseColumns)) {
			// update the existing column.
			// ALTER TABLE `mywp_abj404_redirects` CHANGE `status` `status` BIGINT(19) NOT NULL;
			$updateColStatement = "alter table " . $tableName . " change " . $colName .
			" " . $colDDL;
			$this->dbCore->queryAndGetResults($updateColStatement);
			$this->logger->infoMessage("I updated a column: " . $updateColStatement);

		} else {
			// create the column.
			$createColStatement = "alter table " . $tableName . " add " . $colDDL;
			$this->dbCore->queryAndGetResults($createColStatement);
			$this->logger->infoMessage("I added a column: " . $createColStatement);
		}

		$this->runAddedColumnBackfill(array(
			'tableName' => $tableName,
			'colName' => $colName,
		));
	}

	if (count($skippedByOlderBuildGuard) > 0) {
		$this->logger->infoMessage("This plugin version (" . ABJ404_VERSION . ") is older than " .
			"the newest version that shaped this database (" .
			(ABJ_404_Solution_SchemaHighWaterMark::read() ?? 'an unreadable record') .
			"), so it left the existing columns of " . $tableName . " unchanged. Skipped: " .
			implode(', ', $skippedByOlderBuildGuard));
	}
    }

    /**
     * One list-valued entry of a getTableDifferences() result as strings, with
     * non-scalar members dropped. updateATableBasedOnDifferences() has already
     * refused a result missing a required list, so only the optional
     * keepTheseUnknownColumns can be absent here; absent reads as empty.
     *
     * @param array<string, mixed> $tableDifferences
     * @param string $key
     * @return array<int, string>
     */
    private function differenceList(array $tableDifferences, $key) {
	$list = $tableDifferences[$key] ?? null;
	return is_array($list) ? $this->stringValues($list) : [];
    }

    /**
     * Report each unknown column once per call. Info level: an unknown column is
     * expected after a downgrade or when the site owner added one, not a fault.
     *
     * @param string $tableName
     * @param array<int, string> $unknownColumns
     * @return void
     */
    private function logKeptUnknownColumns($tableName, array $unknownColumns) {
	foreach (array_unique($unknownColumns) as $unknownColumn) {
		$this->logger->infoMessage("Column " . $unknownColumn . " on table " . $tableName .
			" is not defined by this plugin version (newer version or manual addition); " .
			"kept because only retired columns are dropped.");
	}
    }

    /**
     * Drop the retired columns of a table -- but never ALL of its columns (MySQL
     * error: "You can't delete all columns with ALTER TABLE; use DROP TABLE
     * instead"). That happens when a table is completely restructured and every
     * existing column name differs from the goal schema.
     *
     * @param string $tableName
     * @param array<int, string> $retiredColumns
     * @param int $existingColumnCount
     * @return void
     */
    private function dropRetiredColumns($tableName, array $retiredColumns, $existingColumnCount) {
	if (count($retiredColumns) > 0 && count($retiredColumns) >= $existingColumnCount) {
		$this->logger->warn("Skipping column drops on " . $tableName .
			" because it would remove all " . $existingColumnCount .
			" existing columns. Drops requested: " . implode(', ', $retiredColumns));
		return;
	}
	foreach ($retiredColumns as $colName) {
		$query = "alter table " . $tableName . " drop " . $colName;
		$this->dbCore->queryAndGetResults($query);
		$this->logger->infoMessage("I dropped a column (1): " . $query);
	}
    }

    /**
     * Whether this build is older than the newest version that shaped the schema.
     *
     * @return bool
     */
    private function isOlderBuildThanRecordedSchema() {
	return ABJ_404_Solution_SchemaHighWaterMark::isNewerThan(ABJ404_VERSION);
    }

    /**
     * @param array{tableName: string, colName: string} $context
     * @return void
     */
    private function runAddedColumnBackfill(array $context) {
	// min_log_id is drained once per verification by runPendingBackfills(),
	// including on later requests after the column already exists.
	if ($context['colName'] === 'min_log_id') {
		return;
	}
	$this->upgrades()->addedColumnBackfillUpgrade()->runBackfillsForAddedColumn($context);
    }

    /** Create table DDL is returned without SQL comments of any kind.
     * Strips block comments (slash-star ... star-slash), line comments (-- ...),
     * and inline COMMENT 'text' column clauses so the column-name regex in
     * getTableDifferences() cannot mistake comment text for column definitions.
     * @param string|null $createTableDDL
     * @return string
     */
	    function removeCommentsFromColumns($createTableDDL) {
		if ($createTableDDL === null) {
			return '';
		}
		$ddl = (string) $createTableDDL;
		// Strip block comments (slash-star ... star-slash), including multi-line.
		$ddl = preg_replace('/\/\*.*?\*\//s', '', $ddl) ?? $ddl;
		// Strip line comments (-- ...).
		$ddl = preg_replace('/--[^\r\n]*/', '', $ddl) ?? $ddl;
		// Strip inline COMMENT 'text', clauses from column definitions.
		return preg_replace('/ (?:COMMENT.+?,[\r\n])/', ",\n", $ddl) ?? $ddl;
	    }
    /**
     * Normalize a single column DDL fragment for comparison.
     *
     * Strips backticks and unquotes integer defaults so that DDL from
     * SHOW CREATE TABLE (e.g. default '1') matches the goal DDL file
     * (e.g. default 1). Used by both getTableDifferences() and
     * updateATableBasedOnDifferences() — a single source of truth
     * prevents the two normalization sites from drifting out of sync.
     *
     * @param mixed $ddl  A column DDL string (or non-string from regex match)
     * @return string
     */
    function normalizeColumnDDL($ddl): string {
	$ddlStr = is_string($ddl) ? $ddl : '';
	$normalized = strtolower(str_replace('`', '', trim($ddlStr)));
	$normalized = preg_replace("/default '(\d+)'/", 'default $1', $normalized) ?? $normalized;
	// MySQL omits DEFAULT NULL for nullable columns — strip it so DDL file
	// and SHOW CREATE TABLE produce identical normalized strings.
	$normalized = preg_replace('/\s+default\s+null\b/', '', $normalized) ?? $normalized;
	return $normalized;
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, string>
     */
    private function stringValues(array $values): array {
	$result = [];
	foreach ($values as $value) {
		if (is_scalar($value)) {
			$result[] = (string)$value;
		}
	}
	return $result;
    }

    /**
     * @param string $tableName
     * @return void
     */
    function deleteIndexes($tableName) {

	// get the indexes list.
	$results = $this->dbCore->queryAndGetResults("show index from " . $tableName .
		" where key_name != 'PRIMARY'");
	/** @var array<int, array<string, mixed>> $rows */
	$rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];

	if (empty($rows)) {
		return;
	}

	// find the key_name column because the case can be different on different systems.
	$keyNameColumn = 'key_name';
	$aRow = $rows[0];
	foreach (array_keys($aRow) as $someKey) {
		if ($this->f->strtolower((string)$someKey) == 'key_name') {
			$keyNameColumn = (string)$someKey;
			break;
		}
	}

	foreach ($rows as $row) {
		// delete them
		$indexName = $row[$keyNameColumn] ?? '';
		if (!is_string($indexName) || $indexName === '') {
			continue;
		}
		$query = "alter table " . $tableName . " drop index " . $indexName;
		$this->dbCore->queryAndGetResults($query);
	}
    }

}
