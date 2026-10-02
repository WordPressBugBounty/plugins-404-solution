<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The columns this plugin has deliberately retired, per table.
 *
 * The schema-diff upgrade may only drop a column listed here. Any other live
 * column that the running build's bundled create*Table.sql does not declare is
 * unknown: it may belong to a newer plugin version (an older build is running
 * against a schema a newer build shaped, e.g. after a downgrade or a rolled-back
 * beta) or to the site owner. Dropping it destroys data this build has no
 * evidence it may destroy, so the upgrade keeps it.
 *
 * A retired column is positive evidence that an earlier release of THIS plugin
 * created the column and a later release stopped using it, which is the only
 * case where dropping it is the plugin's own cleanup.
 */
class ABJ_404_Solution_RetiredColumns {

    /**
     * Retired columns keyed by table-name suffix (the table name without the
     * WordPress prefix). Column names are lowercase, because the schema diff
     * lowercases both DDL statements before comparing.
     *
     * MAINTAINERS: when you remove a column from a create*Table.sql file, add it
     * here in the same change. Until you do, the upgrade keeps the column on
     * existing sites instead of dropping it, which is safe but leaves dead data.
     * Never list a column the bundled DDL still declares.
     *
     * @var array<string, array<int, string>>
     */
    private const RETIRED = array(
        'abj404_logsv2' => array('country', 'location', 'reason'),
        'abj404_permalink_cache' => array('structure', 'rowtype'),
        'abj404_ngram_cache' => array('page_id'),
        'abj404_spelling_cache' => array('redirect_id'),
    );

    /**
     * Whether this plugin deliberately retired a column from a table.
     *
     * The table matches when its name ENDS with a listed suffix, so any WordPress
     * or multisite prefix works and a name that merely contains or extends a
     * suffix (wp_abj404_logsv2_tmp, wp_abj404_logsv2x) does not. The column
     * comparison is case-insensitive; neither argument is trimmed.
     *
     * @param string $tableName Full table name including the WordPress prefix.
     * @param string $columnName Column name.
     * @return bool True only when the column is listed as retired for this table.
     */
    public static function isRetired(string $tableName, string $columnName): bool {
        if ($columnName === '') {
            return false;
        }
        return in_array(strtolower($columnName), self::retiredFor($tableName), true);
    }

    /**
     * The retired columns of a table.
     *
     * @param string $tableName Full table name including the WordPress prefix.
     * @return array<int, string> Lowercase column names; empty when the table has none,
     *     including when the name only contains or extends a known suffix.
     */
    public static function retiredFor(string $tableName): array {
        $lowerName = strtolower($tableName);
        foreach (self::RETIRED as $suffix => $columns) {
            $suffixLength = strlen($suffix);
            if (strlen($lowerName) >= $suffixLength
                && substr($lowerName, -$suffixLength) === $suffix) {
                return $columns;
            }
        }
        return array();
    }

    /**
     * Sort columns a build's DDL does not declare into the ones this plugin
     * deliberately retired (safe to drop) and the unknown ones (kept).
     *
     * @param string $tableName Full table name including the WordPress prefix.
     * @param array<int|string, string> $columns Column names to sort; input order is kept.
     * @return array{retired: array<int, string>, unknown: array<int, string>}
     */
    public static function partition(string $tableName, array $columns): array {
        $partitioned = array('retired' => array(), 'unknown' => array());
        foreach ($columns as $column) {
            $kind = self::isRetired($tableName, $column) ? 'retired' : 'unknown';
            $partitioned[$kind][] = $column;
        }
        return $partitioned;
    }
}
