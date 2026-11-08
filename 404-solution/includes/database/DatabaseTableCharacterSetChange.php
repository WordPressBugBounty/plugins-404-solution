<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Builds one charset repair while preserving permanent and live column contracts. */
final class ABJ_404_Solution_DatabaseTableCharacterSetChange {
    /**
     * CONVERT overrides even an explicit MODIFY in the same ALTER on MySQL.
     * Change the default and only the individual columns that actually drifted.
     * @param array{table:string,collation:string,declarations:array<string,array{collation:string,definition:string}>} $options
     */
    public static function statement(ABJ_404_Solution_DatabaseCore $db, array $options): string {
        $table = $options['table'];
        $metadata = self::columnMetadata($db, $table);
        $ddl = $db->tableNameResolver()->getCreateTableDDL($table);
        $columns = ABJ_404_Solution_CreateTableColumnParser::fromCreateTableSql($ddl);
        // The parser strips SQL comments. Refuse engine-specific executable
        // options rather than silently losing one while rendering a MODIFY.
        if (empty($columns) || strpos($ddl, '/*!') !== false) {
            throw new RuntimeException('Cannot preserve character column options for table ' . $table . '.');
        }
        $changes = array('DEFAULT CHARACTER SET utf8mb4 COLLATE ' . $options['collation']);
        foreach ($columns as $column) {
            $name = strtolower($column['name']);
            $live = $metadata[$name] ?? null;
            if ($live === null) { throw new RuntimeException('Missing live column metadata for ' . $table . '.' . $name); }
            if ($live['collation'] === null) { continue; }
            $declared = $options['declarations'][$name] ?? null;
            $expected = $declared['collation'] ?? $options['collation'];
            if ($live['collation'] === $expected) { continue; }
            $definition = $declared !== null ? $declared['definition']
                : self::convertedDefinition(array('column' => $column, 'type' => $live['type'], 'collation' => $options['collation']));
            $changes[] = 'MODIFY ' . $definition;
        }
        return 'ALTER TABLE ' . $table . ' ' . implode(', ', $changes);
    }

    /** @return array<string,array{type:string,collation:?string}> */
    private static function columnMetadata(ABJ_404_Solution_DatabaseCore $db, string $table): array {
        $result = $db->queryAndGetResults('SHOW FULL COLUMNS FROM ' . $table);
        $error = $result['last_error'] ?? '';
        if ($error !== '' || !empty($result['timed_out']) || !is_array($result['rows'] ?? null)) {
            throw new RuntimeException('Cannot read character column metadata for ' . $table . ': '
                . (is_string($error) ? $error : 'malformed database response'));
        }
        $metadata = array();
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) { throw new RuntimeException('Malformed column metadata for ' . $table); }
            $row = array_change_key_case($row, CASE_LOWER);
            $name = $row['field'] ?? null;
            $type = $row['type'] ?? null;
            $collation = $row['collation'] ?? null;
            if (!is_string($name) || !is_string($type) || !array_key_exists('collation', $row)
                || ($collation !== null && !is_string($collation))) {
                throw new RuntimeException('Malformed character column metadata for ' . $table);
            }
            $metadata[strtolower($name)] = array('type' => $type, 'collation' => $collation);
        }
        return $metadata;
    }

    /** @param array{column:array{name:string,definition:string,type:string},type:string,collation:string} $options Live declaration and target. */
    private static function convertedDefinition(array $options): string {
        $column = $options['column'];
        $type = $options['type'];
        $collation = $options['collation'];
        if (preg_match('/^(?:char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/i', $type) !== 1) {
            throw new RuntimeException('Unsupported character column type for ' . $column['name'] . ': ' . $type);
        }
        $prefix = substr($column['definition'], 0, strlen($column['definition']) - strlen($column['type']));
        $pattern = '~^' . preg_quote($prefix . $type, '~')
            . '(?:\s+CHARACTER\s+SET\s+\w+)?(?:\s+COLLATE\s+\w+)?~i';
        $replacement = $prefix . $type . ' CHARACTER SET utf8mb4 COLLATE ' . $collation;
        // Replace only type-adjacent attributes, never text in DEFAULT/COMMENT.
        if (preg_match($pattern, $column['definition'], $matches) !== 1) {
            throw new RuntimeException('Cannot preserve live character column definition for ' . $column['name']);
        }
        return $replacement . substr($column['definition'], strlen($matches[0]));
    }
}
