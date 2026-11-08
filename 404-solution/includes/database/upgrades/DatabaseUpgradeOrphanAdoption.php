<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../DatabaseCollationHelper.php';

/**
 * Adopts verified old-prefix plugin data while preserving tenant isolation.
 */
class ABJ_404_Solution_DatabaseUpgradeOrphanAdoption extends ABJ_404_Solution_DatabaseUpgradeComponent {

	/**
	 * Detect orphaned plugin tables under old prefixes and adopt their data
	 * into the current-prefix tables. Uses slug verification against the logs
	 * table to confirm ownership before adopting.
	 *
	 * @return void
	 */
	public function adoptOrphanedTables(): void {
		global $wpdb;

		$dbNameRaw = $wpdb->dbname ?? '';
		if ($dbNameRaw === '') {
			return;
		}
		// @utf8-audit: opt-out — $wpdb->dbname is set by WordPress at
		// bootstrap from wp-config.php; never user input.

		// Find all abj404 tables in the database, grouped by prefix. Both values
		// are bound, not spliced: the executor rewrites `{wp_...}` tokens over
		// the statement text before it binds query_params. The pattern's
		// backslash keeps the underscore literal in LIKE.
		$query = "SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = %s
			AND LOWER(table_name) LIKE %s";
		$results = $this->dbCore->queryAndGetResults(
			$query,
			array('query_params' => array($dbNameRaw, '%abj404\\_%'))
		);

		if (!$this->queryResultSucceeded($results) || !is_array($results['rows']) || empty($results['rows'])) {
			return;
		}

		$currentPrefix = $this->dbCore->tableNameResolver()->getLowercasePrefix();

		// Group tables by their prefix (everything before 'abj404_').
		$tablesByPrefix = $this->groupAbj404TablesByPrefix($results['rows']);

		// Skip prefixes we've already adopted.
		$adoptedPrefixes = ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_adopted_prefixes', array());
		if (!is_array($adoptedPrefixes)) {
			$adoptedPrefixes = array();
		}

		// Authoritative tenant-isolation boundary: any prefix that belongs to an
		// active multisite blog (a sibling subsite) is NEVER an orphan to adopt,
		// regardless of how well its content happens to match this site. The
		// slug-match heuristic below is only a tie-breaker among genuine
		// migration leftovers, never the authorization boundary.
		$activeBlogPrefixes = $this->getActiveBlogPrefixesLowercase();
		$foreignOptionsPrefixes = $this->getForeignWordPressOptionsPrefixes(array_keys($tablesByPrefix));

		// Process each OLD prefix (not the current one).
		foreach ($tablesByPrefix as $oldPrefix => $tables) {
			$oldPrefix = (string)$oldPrefix;
			if ($oldPrefix === $currentPrefix) {
				continue;
			}
			if (in_array($oldPrefix, $adoptedPrefixes, true)) {
				continue;
			}
			if (in_array($oldPrefix, $activeBlogPrefixes, true)) {
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' belong to an active "
					. "multisite blog (sibling subsite). Skipping adoption to preserve "
					. "tenant isolation."
				);
				continue;
			}
			if (in_array($oldPrefix, $foreignOptionsPrefixes, true)) {
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' have a separate "
					. 'WordPress core options table; skipping adoption to preserve tenant isolation.'
				);
				continue;
			}

			$this->logger->infoMessage(
				"Found orphaned plugin tables under prefix '{$oldPrefix}' "
				. "(current prefix is '{$currentPrefix}'): " . implode(', ', $tables)
			);

			// Check if old tables have any data at all.
			$rowCount = $this->countOldPrefixRows($oldPrefix, $tables);
			if ($rowCount['uncertain']) {
				$this->logger->warn(
					"Could not complete the row-count probe for orphan prefix '{$oldPrefix}'; "
					. 'deferring adoption until it can be retried.'
				);
				continue;
			}
			$totalRows = $rowCount['total'];
			if ($totalRows === 0) {
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' are all empty. Skipping adoption."
				);
				continue;
			}

			// Verify ownership via logs dest_url slug matching.
			$matchResult = $this->verifyOwnershipViaLogs($oldPrefix);

			if ($matchResult === null) {
				// Logs verification returned no data — fall back to redirects post-ID check.
				$matchResult = $this->verifyOwnershipViaRedirects($oldPrefix);
			}

			if ($matchResult !== true) {
				// false = data doesn't match this site; null = insufficient data to verify.
				// Either way, do not adopt — absence of veto is not permission.
				$reason = ($matchResult === false)
					? "Data does not appear to belong to this site."
					: "Insufficient data in logs and redirects to verify ownership.";
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' — skipping adoption. {$reason}"
				);
				continue;
			}

			// Ownership positively verified. Adopt the data.
			$this->adoptDataFromPrefix(array('source' => $oldPrefix, 'destination' => $currentPrefix), $tables);
		}
	}

	/**
	 * Parse information_schema rows into a prefix => [lowercase table name, ...]
	 * map. Each table name is lowercased and grouped by the substring before its
	 * 'abj404_' segment; rows without a recognizable table_name or abj404_
	 * segment are skipped. Extracted from adoptOrphanedTables() so that method's
	 * branching stays within the cyclomatic-complexity budget.
	 *
	 * @param array<array-key, mixed> $rows
	 * @return array<string, array<int, string>>
	 */
	private function groupAbj404TablesByPrefix(array $rows): array {
		$tablesByPrefix = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$tableName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'table_name' && is_scalar($value)) {
					$tableName = strtolower((string)$value);
					break;
				}
			}
			if ($tableName === null) {
				continue;
			}

			$abj404Pos = strpos($tableName, 'abj404_');
			if ($abj404Pos === false) {
				continue;
			}

			$prefix = substr($tableName, 0, $abj404Pos);
			if (!is_string($prefix)) {
				continue;
			}
			$tablesByPrefix[$prefix][] = $tableName;
		}
		return $tablesByPrefix;
	}

	/**
	 * Count total rows across all known plugin tables for a given prefix.
	 *
	 * @param string $oldPrefix
	 * @param array<int, string> $knownTables  Table names actually found in information_schema.
	 * @return array{total: int, uncertain: bool}
	 */
	private function countOldPrefixRows(string $oldPrefix, array $knownTables): array {
		$total = 0;
		$uncertain = false;
		foreach ($this->getPluginTableSuffixes() as $suffix) {
			$tableName = $oldPrefix . $suffix;
			if (!in_array($tableName, $knownTables, true)) {
				continue;
			}
			$result = $this->dbCore->queryAndGetResults(
				"SELECT COUNT(*) AS cnt FROM `{$tableName}`",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (!$this->queryResultSucceeded($result)) {
				if (!$this->queryResultIsExpectedMissingTable($result)) {
					$uncertain = true;
				}
				continue;
			}
			if (!array_key_exists('rows', $result) || !is_array($result['rows']) || empty($result['rows'])
					|| !is_array($result['rows'][0] ?? null)) {
				$uncertain = true;
				continue;
			}
			if (array_key_exists('cnt', $result['rows'][0]) || array_key_exists('CNT', $result['rows'][0])) {
				$row = $result['rows'][0];
                $countValue = $row['cnt'] ?? $row['CNT'] ?? 0;
				$cnt = ABJ_404_Solution_ExactInteger::read($countValue, 0);
				if ($cnt === null) {
					$uncertain = true;
					continue;
				}
				$total += $cnt;
			} else {
				$uncertain = true;
			}
		}
		return array('total' => $total, 'uncertain' => $uncertain);
	}

	/**
	 * Verify ownership of orphaned tables by matching logs dest_url against
	 * current site's published post slugs.
	 *
	 * @param string $oldPrefix   The old table prefix.
	 * @return bool|null  true = verified, false = failed, null = no data to verify.
	 */
	private function verifyOwnershipViaLogs(string $oldPrefix): ?bool {
		global $wpdb;
		$logsTable = $oldPrefix . 'abj404_logsv2';
		// WordPress core posts table uses the original $wpdb->prefix (possibly mixed-case),
		// NOT our lowercased prefix. Only plugin tables were renamed to lowercase.
		$postsTable = ($wpdb->prefix ?? 'wp_') . 'posts';

		// p.post_name belongs to a WordPress CORE table and dest_url to a PLUGIN
		// table, so each carries whatever collation its own table was created
		// with. When those differ MySQL refuses the comparison outright
		// ("Illegal mix of collations ... for operation 'locate'", errno 1267),
		// the query returns nothing, ownership is never verified, and the
		// site's orphaned tables stay stranded under the old prefix. Converting
		// BOTH operands to one charset and one collation is the same treatment
		// updatePermalinkCache.sql and getPublishedPagesAndPostsIDs.sql already
		// give their wp_posts / wp_terms comparisons; this query is built
		// inline, which is how it missed out. The collation comes from the one
		// derivation that guarantees it is valid for the charset beside it.
		$collation = ABJ_404_Solution_DatabaseCollationHelper::utf8mb4CollationOrFallback(
			isset($wpdb->collate) && is_scalar($wpdb->collate) ? (string)$wpdb->collate : ''
		);
		$postNameOperand = "CONVERT(p.post_name USING utf8mb4) COLLATE " . $collation;
		$destUrlOperand = "CONVERT(dest_url USING utf8mb4) COLLATE " . $collation;

		// Check distinct internal dest_urls against published post slugs.
		$query = "SELECT COUNT(*) AS total,
				SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) AS matches
			FROM (
				SELECT DISTINCT dest_url,
					EXISTS(SELECT 1 FROM `{$postsTable}` p
						WHERE p.post_status = 'publish'
						AND LENGTH(p.post_name) >= 3
						AND LOCATE({$postNameOperand}, {$destUrlOperand}) > 0) AS matched
				FROM `{$logsTable}` l
				WHERE dest_url IS NOT NULL
					AND dest_url != ''
					AND dest_url != '404'
					AND dest_url NOT LIKE 'http://%'
					AND dest_url NOT LIKE 'https://%'
				LIMIT 500
			) sub";

		$result = $this->dbCore->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!$this->queryResultSucceeded($result) || !is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = is_array($result['rows'][0] ?? null) ? $result['rows'][0] : [];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total') { $total = ABJ_404_Solution_ExactInteger::readOr($value, 0, 0); }
			if ($lk === 'matches') { $matches = ABJ_404_Solution_ExactInteger::readOr($value, 0, 0); }
		}

		if ($total === 0) {
			return null; // No internal dest_urls to verify.
		}

		$matchPct = ($matches / max(1, $total)) * 100;
		$this->logger->infoMessage(
			"Logs ownership verification for prefix '{$oldPrefix}': "
			. "{$matches}/{$total} distinct internal dest_urls match published post slugs "
			. "({$matchPct}%)"
		);

		return $matchPct >= 80;
	}

	/**
	 * Fallback ownership verification using redirects table post-ID existence.
	 * Weaker than slug matching but useful when logs have no internal dest_urls.
	 *
	 * @param string $oldPrefix
	 * @return bool|null  true = verified, false = failed, null = no data.
	 */
	private function verifyOwnershipViaRedirects(string $oldPrefix): ?bool {
		global $wpdb;
		$redirectsTable = $oldPrefix . 'abj404_redirects';
		$postsTable = ($wpdb->prefix ?? 'wp_') . 'posts';
		$termsTable = ($wpdb->prefix ?? 'wp_') . 'terms';

		// final_dest is a wp_posts.ID for a post redirect (type 1) and a
		// wp_terms.term_id for a category or tag redirect (types 2, 3). The id
		// spaces overlap, so each type is checked against its own table.
		$query = "SELECT COUNT(*) AS total,
				SUM(CASE WHEN p.ID IS NOT NULL OR t.term_id IS NOT NULL THEN 1 ELSE 0 END) AS matches
			FROM `{$redirectsTable}` r
			LEFT JOIN `{$postsTable}` p
				ON r.type = 1
				AND p.ID = CAST(r.final_dest AS UNSIGNED)
				AND p.post_status IN ('publish', 'draft', 'private')
			LEFT JOIN `{$termsTable}` t
				ON r.type IN (2, 3)
				AND t.term_id = CAST(r.final_dest AS UNSIGNED)
			WHERE r.type IN (1, 2, 3)";

		$result = $this->dbCore->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!$this->queryResultSucceeded($result) || !is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = is_array($result['rows'][0] ?? null) ? $result['rows'][0] : [];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total') { $total = ABJ_404_Solution_ExactInteger::readOr($value, 0, 0); }
			if ($lk === 'matches') { $matches = ABJ_404_Solution_ExactInteger::readOr($value, 0, 0); }
		}

		if ($total === 0) {
			return null;
		}

		$matchPct = ($matches / max(1, $total)) * 100;
		$this->logger->infoMessage(
			"Redirects fallback ownership verification for prefix '{$oldPrefix}': "
			. "{$matches}/{$total} type 1/2/3 redirects point to existing posts or terms ({$matchPct}%)"
		);

		return $matchPct >= 80;
	}

	/**
	 * Adopt data from orphaned tables under an old prefix into current-prefix tables.
	 * Uses INSERT IGNORE to avoid duplicate key conflicts.
	 *
	 * @param array{source:string,destination:string} $prefixes
	 * @param array<string> $knownTables
	 * @return void
	 */
	private function adoptDataFromPrefix(array $prefixes, array $knownTables): void {
		['source' => $oldPrefix, 'destination' => $currentPrefix] = $prefixes;
		$this->logger->infoMessage(
			"Beginning adoption of data from prefix '{$oldPrefix}' to '{$currentPrefix}'"
		);

		$totalAdopted = 0;
		$adoptionFailed = false;

		foreach ($this->getPluginTableSuffixes() as $suffix) {
			$oldTable = $oldPrefix . $suffix;
			if (!in_array($oldTable, $knownTables, true)) {
				continue;
			}
			$newTable = $currentPrefix . $suffix;
			$tableResult = $this->adoptOneTable(array('source' => $oldTable, 'destination' => $newTable), $suffix);
			$totalAdopted += $tableResult['affected'];
			$adoptionFailed = $adoptionFailed || $tableResult['failed'];
		}

		if ($adoptionFailed) {
			$this->logger->infoMessage(
				"Adoption deferred: {$totalAdopted} rows were confirmed for prefix '{$oldPrefix}', "
				. 'but one or more reads or writes were uncertain; retaining the prefix for retry.'
			);
			return;
		}

		$this->logger->infoMessage(
			"Adoption complete: {$totalAdopted} total rows adopted from prefix '{$oldPrefix}' to '{$currentPrefix}'"
		);

		// Record this prefix as adopted so we don't re-detect it on every page load.
		$adoptedPrefixes = ABJ_404_Solution_OptionShapeRead::arrayValue('abj404_adopted_prefixes', array());
		if (!is_array($adoptedPrefixes)) {
			$adoptedPrefixes = array();
		}
		if (!in_array($oldPrefix, $adoptedPrefixes, true)) {
			$adoptedPrefixes[] = $oldPrefix;
			update_option('abj404_adopted_prefixes', $adoptedPrefixes, false);
		}
	}

	/** @param array{source:string,destination:string} $tables
	 * @return array{affected:int,failed:bool} */
	private function adoptOneTable(array $tables, string $suffix): array {
		['source' => $oldTable, 'destination' => $newTable] = $tables;
        // Check if the old table exists and has rows.
        $oldCount = $this->oldTableRowCount($oldTable);
        if ($oldCount === null) { return array('affected' => 0, 'failed' => true); }
        if ($oldCount === 0) { return array('affected' => 0, 'failed' => false); }
		// Auto-repair should have created the new table; verify it before adoption.
		$newExists = $this->dbCore->queryAndGetResults("SELECT 1 FROM `{$newTable}` LIMIT 1",
			array('ignore_errors' => array("doesn't exist", 'not found')));
		if (!$this->queryResultSucceeded($newExists) || !is_array($newExists['rows'] ?? null)) {
			$this->logger->infoMessage("Target table '{$newTable}' could not be verified. Skipping adoption for '{$suffix}' and retaining the prefix for retry.");
			return array('affected' => 0, 'failed' => true);
		}
		// Match columns to tolerate schema drift in tables from older versions.
		$common = (new ABJ_404_Solution_DatabaseColumnIntersection($this->dbCore))->between($oldTable, $newTable);
		if ($common['uncertain'] || empty($common['columns'])) {
			$this->logger->infoMessage("Common columns between '{$oldTable}' and '{$newTable}' could not be safely established. Skipping and retaining the prefix for retry.");
			return array('affected' => 0, 'failed' => true);
		}
        $affected = $this->insertAdoptedRows($tables, $suffix, $common['columns']);
        if ($affected === null) { return array('affected' => 0, 'failed' => true); }
		if ($affected > 0) {
			$this->logger->infoMessage("Adopted {$affected} rows from '{$oldTable}' into '{$newTable}'");
			if ($suffix === 'abj404_redirects' || $suffix === 'abj404_redirect_conditions') { ABJ_404_Solution_RedirectPageCachePurge::queueSiteWide(); }
		}
		return array('affected' => $affected, 'failed' => false);
	}

    /** @return int|null Null means the read was uncertain. */
    private function oldTableRowCount(string $oldTable): ?int {
        $result = $this->dbCore->queryAndGetResults("SELECT COUNT(*) AS cnt FROM `{$oldTable}`",
            array('ignore_errors' => array("doesn't exist", 'not found')));
        if (!$this->queryResultSucceeded($result)) {
            return $this->queryResultIsExpectedMissingTable($result) ? 0 : null;
        }
        $rows = $result['rows'] ?? array();
        if (!is_array($rows) || empty($rows) || !is_array($rows[0] ?? null)) { return null; }
        $row = $rows[0];
        if (!array_key_exists('cnt', $row) && !array_key_exists('CNT', $row)) { return null; }
        return ABJ_404_Solution_ExactInteger::read($row['cnt'] ?? $row['CNT'] ?? 0, 0);
    }

    /** @param array{source:string,destination:string} $tables
     * @param array<int,string> $columns
     * @return int|null */
    private function insertAdoptedRows(array $tables, string $suffix, array $columns): ?int {
        ['source' => $oldTable, 'destination' => $newTable] = $tables;
        $columnList = implode('`, `', $columns);
        $result = $this->dbCore->queryAndGetResults("INSERT IGNORE INTO `{$newTable}` (`{$columnList}`) SELECT `{$columnList}` FROM `{$oldTable}`",
            array('ignore_errors' => array("doesn't exist", 'not found', 'Duplicate')));
        if (!$this->queryResultSucceeded($result)) {
            if ($suffix === 'abj404_redirects' || $suffix === 'abj404_redirect_conditions') { ABJ_404_Solution_RedirectPageCachePurge::queueSiteWide(); }
            $this->logger->warn("Adoption write for '{$newTable}' did not succeed; retaining the old-prefix work for retry.");
            return null;
        }
        return ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0);
    }

	/** @param array<string,mixed> $result */
	private function queryResultIsExpectedMissingTable(array $result): bool {
		if (($result['query_succeeded'] ?? null) === true
				|| ($result['timed_out'] ?? false) === true) {
			return false;
		}
		$error = $result['last_error'] ?? null;
		if (!is_string($error) || $error === '') {
			return false;
		}
		$error = strtolower($error);
		return strpos($error, "doesn't exist") !== false
			|| strpos($error, 'does not exist') !== false
			|| strpos($error, 'unknown table') !== false
			|| strpos($error, 'not found') !== false;
	}

	/** @param array<string,mixed> $result */
	private function queryResultSucceeded(array $result): bool {
		return ($result['query_succeeded'] ?? null) === true
			&& is_string($result['last_error'] ?? null)
			&& $result['last_error'] === ''
			&& (!array_key_exists('timed_out', $result) || $result['timed_out'] === false);
	}

}
