<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The DDL-issuing half of the table-index domain: given a table and the index
 * definition the shipped schema asks for, bring the engine to that state and
 * say honestly what happened.
 *
 * {@see ABJ_404_Solution_TableIndexDefinitions} is the read half and says so in
 * its own header ("Everything here is read-only ... It issues no DDL and makes
 * no repair decisions"). This is the counterpart it names. Between them sits
 * {@see ABJ_404_Solution_IndexDefinitionComparator}, which both use to ask
 * whether two definitions agree.
 *
 * What lives here is everything that depends on how an ENGINE behaves rather
 * than on what the plugin's schema wants: which syntax this server accepts
 * (MariaDB 10.5+ takes ADD INDEX IF NOT EXISTS, MySQL never has), whether the
 * online-DDL hints are worth trying before a plain ALTER, and what each answer
 * it gives back actually means. The decision of WHICH indexes need repairing,
 * in what order, and how often a rebuild may be re-attempted stays with
 * {@see ABJ_404_Solution_DatabaseUpgradeIndexes}, which is schema policy and
 * changes for entirely different reasons.
 *
 * The interpretation half is the reason this is a module and not a function.
 * An ADD INDEX has three outcomes, not two: it worked, it failed, or it was
 * redundant because another process made the same change first -- and the third
 * one reached the state the caller wanted. Report 270 (dianthus.zuidplas.net,
 * 2026-08-16 07:10:52) is what the third outcome looks like when it is handled
 * as the second: two concurrent requests both read SHOW INDEX before either
 * wrote, both decided idx_status_disabled_timestamp_id was missing, and the
 * loser answered the winner's success with a retry that could not succeed and
 * five ERROR lines about an index that was there. Every caller that emits index
 * DDL needs that distinction, and before this module there were two copies of
 * the emit path and only one of them made it.
 *
 * Being told the name is taken is NOT taken as proof the goal was met: this
 * domain exists because an index can carry exactly the right name and the wrong
 * columns (MySQL silently narrows an index when a column it names is dropped).
 * So the schema is read back and compared, and only a match is recorded as an
 * index that is present.
 */
class ABJ_404_Solution_TableIndexWriter {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore Runs the statements and owns the error taxonomy.
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Bring one index into existence with the definition the schema asks for.
     *
     * @param string $tableName Fully-qualified table name.
     * @param array{name: string, columns: string, unique: bool, replace_existing?: bool} $spec The index the schema defines.
     *        `columns` carries its own parentheses, e.g. "(`a`, `b`(190))".
     * @param array{replace_existing?: bool, try_online_first?: bool} $options
     *        replace_existing: drop the same-named index in the SAME ALTER first, so a drifted
     *          index is swapped without the table ever being without it (default false).
     *        try_online_first: attempt ALGORITHM=INPLACE, LOCK=NONE and fall back to a plain
     *          ALTER if the server or storage engine rejects the hints (default true). The
     *          logsv2 composite passes false: its repair is a DROP and an ADD in one statement
     *          on a table that can be multi-GB, and it has always issued that plainly.
     * @return void
     */
    public function addIndex(string $tableName, array $spec, array $options = array()): void {
        $this->addIndexes($tableName, array($spec), $options);
    }

    /**
     * Bring a set of indexes into existence in one ALTER TABLE.
     *
     * The set is validated before any DDL is emitted. A batch uses the same
     * options and per-index shape as addIndex(), with an optional per-index
     * replacement override. It shares the table scan and online-DDL fallback
     * so a large upgrade does not queue one table rebuild per index.
     *
     * @param string $tableName Fully-qualified table name.
     * @param array<int, array{name: string, columns: string, unique: bool, replace_existing?: bool}> $specs
     * @param array{replace_existing?: bool, try_online_first?: bool} $options
     * @return void
     */
    public function addIndexes(string $tableName, array $specs, array $options = array()): void {
        if ($specs === array()) {
            return;
        }
        $specs = array_values($specs);
        if (!$this->validateBatchInput($tableName, $specs, $options)) {
            return;
        }
        $options = $this->normalizeBatchOptions($options);

        $replaceExisting = !empty($options['replace_existing']);
        $tryOnlineFirst = !array_key_exists('try_online_first', $options)
            || !empty($options['try_online_first']);
        $request = array(
            'tableName' => $tableName,
            'specs' => $specs,
            'options' => $options,
            'online' => $tryOnlineFirst,
            'replaceExisting' => $replaceExisting,
        );
        $statement = $this->buildAddIndexesStatement($request);
        $lastError = $this->runStatement($statement);

        if ($lastError !== '') {
            if ($this->recordRedundantBatchChange(array(
                'tableName' => $tableName,
                'specs' => $specs,
                'options' => $options,
                'lastError' => $lastError,
            ))) {
                return;
            }
            if ($tryOnlineFirst && $this->isOnlineDdlHintRejection($lastError)) {
                $warningPrefix = count($specs) === 1
                    ? "Online index add for {$specs[0]['name']} on {$tableName} failed; "
                    : "Online index batch add on {$tableName} failed; ";
                $this->logger->warn($warningPrefix .
                    "retrying without online DDL hints: " . $lastError . " (query: {$statement})");
                $request['online'] = false;
                $statement = $this->buildAddIndexesStatement($request);
                $lastError = $this->runStatement($statement);
                if ($lastError !== '' && $this->recordRedundantBatchChange(array(
                    'tableName' => $tableName,
                    'specs' => $specs,
                    'options' => $options,
                    'lastError' => $lastError,
                ))) {
                    return;
                }
            }
        }

        if ($lastError !== '') {
            $names = implode(', ', array_map(static function (array $spec): string {
                return (string)$spec['name'];
            }, $specs));
            $label = count($specs) === 1 ? "index {$names}" : "indexes {$names}";
            $this->logger->errorMessage("Failed to add {$label} to {$tableName}: " .
                $lastError . " (query: {$statement})");
            return;
        }

        $message = count($specs) === 1 ? 'I added an index: ' : 'I added indexes: ';
        $this->logger->infoMessage($message . $statement);
    }

    /**
     * @param string $statement
     * @return string The engine's error, or '' when it had none.
     */
    private function runStatement(string $statement): string {
        $result = $this->dbCore->queryAndGetResults($statement);
        return isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
    }

    /**
     * Whether the engine rejected the optional online-DDL clauses themselves.
     * Other failures (permissions, disk, connection, syntax) must not be
     * repeated as a bare ALTER that cannot correct their cause.
     *
     * @param string $lastError What the engine said.
     * @return bool
     */
    private function isOnlineDdlHintRejection(string $lastError): bool {
        $lower = strtolower($lastError);
        $namesOnlineClause = strpos($lower, 'lock=none') !== false
            || strpos($lower, 'algorithm=inplace') !== false;
        $rejectsClause = strpos($lower, 'not supported') !== false
            || strpos($lower, 'unsupported') !== false;
        return $namesOnlineClause && $rejectsClause;
    }

    /**
     * Validate every member before the first destructive statement.
     *
     * @param string $tableName
     * @param array<int, mixed> $specs
     * @param array<string, mixed> $options
     * @return bool
     */
    private function validateBatchInput(string $tableName, array $specs, array $options): bool {
        if (trim($tableName) === '') {
            $this->logger->errorMessage('Cannot add indexes without a table name.');
            return false;
        }
        foreach (array('replace_existing', 'try_online_first') as $optionName) {
            if (array_key_exists($optionName, $options) && !is_bool($options[$optionName])) {
                $this->logger->errorMessage("Cannot add indexes: option {$optionName} must be boolean.");
                return false;
            }
        }

        $seenNames = array();
        foreach ($specs as $spec) {
            if (!$this->validateBatchSpec($spec, $seenNames)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param mixed $spec
     * @param array<string, bool> $seenNames
     * @return bool
     */
    private function validateBatchSpec($spec, array &$seenNames): bool {
        if (!is_array($spec)) {
            $this->logger->errorMessage('Cannot add indexes: unreadable definition (unknown).');
            return false;
        }
        $nameValue = $spec['name'] ?? null;
        $name = is_string($nameValue) ? trim($nameValue) : '(non-string name)';
        if (!is_string($nameValue) || $name === '') {
            $this->logger->errorMessage("Cannot add indexes: unreadable definition {$name}.");
            return false;
        }

        $columnsValue = $spec['columns'] ?? null;
        if (!is_string($columnsValue) || trim($columnsValue) === '') {
            $this->logger->errorMessage("Cannot add indexes: unreadable definition {$name}.");
            return false;
        }
        $uniqueValue = $spec['unique'] ?? null;
        if (!is_bool($uniqueValue)) {
            $this->logger->errorMessage("Cannot add indexes: definition {$name} has no boolean unique flag.");
            return false;
        }
        if (array_key_exists('replace_existing', $spec) && !is_bool($spec['replace_existing'])) {
            $this->logger->errorMessage("Cannot add indexes: definition {$name} has no boolean replace_existing flag.");
            return false;
        }
        $signatureSpec = array(
            'name' => $name,
            'columns' => trim($columnsValue),
            'unique' => $uniqueValue,
        );
        if (ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($signatureSpec) === null) {
            $this->logger->errorMessage("Cannot add indexes: unreadable definition {$name}.");
            return false;
        }

        $normalizedName = strtolower($name);
        if (isset($seenNames[$normalizedName])) {
            $this->logger->errorMessage("Cannot add indexes: duplicate definition {$name}.");
            return false;
        }
        $seenNames[$normalizedName] = true;
        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{replace_existing?: bool, try_online_first?: bool}
     */
    private function normalizeBatchOptions(array $options): array {
        $normalized = array();
        foreach (array('replace_existing', 'try_online_first') as $optionName) {
            if (array_key_exists($optionName, $options) && is_bool($options[$optionName])) {
                $normalized[$optionName] = $options[$optionName];
            }
        }
        return $normalized;
    }

    /**
     * Interpret a redundant batch result against one metadata read, then use
     * bounded single-index fallbacks only for goals still absent from that
     * read. This avoids repeating an already-matching ALTER after a race while
     * still repairing a partial multi-index result safely.
     *
     * @param array{tableName: string, specs: array<int, array{name: string, columns: string, unique: bool, replace_existing?: bool}>,
     *        options: array{replace_existing?: bool, try_online_first?: bool}, lastError: string} $request
     * @return bool True when the redundant error was handled.
     */
    private function recordRedundantBatchChange(array $request): bool {
        $lastError = (string)$request['lastError'];
        $specs = $request['specs'];
        if (count($specs) === 1) {
            return $this->recordRedundantChange(array(
                'tableName' => $request['tableName'],
                'spec' => $specs[0],
                'lastError' => $lastError,
            ));
        }
        if (!$this->dbCore->sqlErrorReporter()->isRedundantSchemaChangeError($lastError)) {
            return false;
        }

        $tableName = $request['tableName'];
        $liveDefinitions = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($tableName);
        if ($liveDefinitions === null) {
            foreach ($specs as $spec) {
                $this->logger->warn("Index {$spec['name']} on {$tableName} was already there when this " .
                    "process tried to add it, and the table's index metadata could not be read back to " .
                    "confirm what it contains. Leaving it for the next upgrade tick to check.");
            }
            return true;
        }

        $missing = array();
        foreach ($specs as $spec) {
            $indexName = (string)$spec['name'];
            $goalSignature = ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec);
            $liveDefinition = $liveDefinitions[strtolower($indexName)] ?? null;
            if ($goalSignature !== null && is_array($liveDefinition)
                    && ABJ_404_Solution_IndexDefinitionComparator::signatureOfLiveDefinition($liveDefinition)
                        === $goalSignature) {
                $this->logger->infoMessage("Index {$indexName} on {$tableName} was added by another " .
                    "process while this one was building it, and it matches the shipped definition.");
                continue;
            }
            if (is_array($liveDefinition)) {
                $this->logger->warn("Index {$indexName} on {$tableName} was already there when this process " .
                    "tried to add it, but the server does not describe it as " . trim((string)$spec['columns']) .
                    ". Leaving it for the drift check to repair.");
                continue;
            }
            $missing[] = $spec;
        }

        if ($missing === array()) {
            return true;
        }

        $fallbackOptions = $request['options'];
        // A missing member cannot be dropped safely. The batch's duplicate
        // answer came from another member, so the bounded fallback is an ADD.
        $fallbackOptions['replace_existing'] = false;
        foreach ($missing as $spec) {
            // A missing member is an ADD-only fallback even when the original
            // batch carried a replacement flag for a different member.
            unset($spec['replace_existing']);
            $this->addIndex($tableName, $spec, $fallbackOptions);
        }
        return true;
    }

    /**
     * Whether the statement failed only because the change had already been
     * made and -- when it had -- what the table actually ended up carrying.
     *
     * Retrying such a statement cannot change the answer: the name is taken
     * either way, which is the state the caller asked for. Reporting it as a
     * failed index add describes a table that has the index. So the caller
     * stops here, and what gets recorded is what the schema now says, read
     * back rather than assumed.
     *
     * @param array{tableName: string, spec: array{name: string, columns: string, unique: bool, replace_existing?: bool},
     *        lastError: string} $request
     * @return bool True when the change was already applied and the caller must stop.
     */
    private function recordRedundantChange(array $request): bool {
        $tableName = $request['tableName'];
        $spec = $request['spec'];
        $lastError = $request['lastError'];
        if (!$this->dbCore->sqlErrorReporter()->isRedundantSchemaChangeError($lastError)) {
            return false;
        }

        $indexName = (string)$spec['name'];
        $goalSignature = ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec);
        $liveDefinitions = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($tableName);
        if ($liveDefinitions === null) {
            $this->logger->warn("Index {$indexName} on {$tableName} was already there when this " .
                "process tried to add it, and the table's index metadata could not be read back to " .
                "confirm what it contains. Leaving it for the next upgrade tick to check.");
            return true;
        }

        $liveDefinition = $liveDefinitions[strtolower($indexName)] ?? null;
        if ($goalSignature !== null && is_array($liveDefinition)
                && ABJ_404_Solution_IndexDefinitionComparator::signatureOfLiveDefinition($liveDefinition)
                    === $goalSignature) {
            $this->logger->infoMessage("Index {$indexName} on {$tableName} was added by another " .
                "process while this one was building it, and it matches the shipped definition.");
            return true;
        }

        // The name is held by something other than what the schema asks for.
        // The plugin still works (worst case a sort is slower), and the drift
        // branch of verifyIndexes() rebuilds a mismatch on a later pass, so this
        // is recorded rather than reported.
        $this->logger->warn("Index {$indexName} on {$tableName} was already there when this process " .
            "tried to add it, but the server does not describe it as " . trim((string)$spec['columns']) .
            ". Leaving it for the drift check to repair.");
        return true;
    }

    /**
     * Build one ALTER TABLE with all requested index clauses.
     *
     * @param array{tableName: string, specs: array<int, array{name: string, columns: string, unique: bool, replace_existing?: bool}>,
     *        online: bool, replaceExisting: bool} $request
     * @return string
     */
    private function buildAddIndexesStatement(array $request): string {
        $tableName = $request['tableName'];
        $specs = $request['specs'];
        $online = $request['online'];
        $replaceExisting = $request['replaceExisting'];
        global $wpdb;
        /** @var \wpdb $wpdb */
        $serverVersion = is_object($wpdb) && method_exists($wpdb, 'db_version') ? ($wpdb->db_version() ?: '') : '';
        $serverInfo = is_object($wpdb) && property_exists($wpdb, 'db_server_info') ? ($wpdb->db_server_info ?? '') : '';

        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
        $cleanedVersion = preg_replace('/[^\d\.]/', '', $serverVersion) ?? '';
        $supportsIfNotExists = $isMaria && version_compare($cleanedVersion, '10.5', '>=');

        $onlineClause = $online ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';
        $clauses = array();
        foreach ($specs as $spec) {
            $indexName = (string)$spec['name'];
            $indexType = !empty($spec['unique']) ? 'unique index' : 'index';
            $replaceThisIndex = array_key_exists('replace_existing', $spec)
                ? $spec['replace_existing'] : $replaceExisting;
            $ifNotExists = ($supportsIfNotExists && !$replaceThisIndex) ? ' if not exists' : '';
            if ($replaceThisIndex) {
                $clauses[] = "drop index `" . $indexName . "`";
            }
            $clauses[] = "add " . $indexType . $ifNotExists . " `" . $indexName . "` " .
                trim((string)$spec['columns']);
        }
        return "alter table " . $tableName . ' ' . implode(', ', $clauses) . $onlineClause;
    }
}
