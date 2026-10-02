<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable SQL-string builders shared by every DAO query that filters by
 * post type or category, and the session-tuning hook that lets large
 * queries run.
 *
 * A SqlFragment is SQL text that holds `%s` / `%d` placeholders and never a
 * value, plus the values for those placeholders in the order they appear. The
 * executor rewrites `{wp_...}` tokens across the whole statement text and only
 * THEN binds `query_params`, so a value spliced into the text (quoted or not)
 * is rewritten along with the template. A fragment keeps every value out of the
 * text, and the caller hands `params` to the executor as `query_params`.
 *
 * @phpstan-type SqlFragment array{sql: string, params: array<int, int|float|string>}
 */
interface ABJ_404_Solution_DatabaseQueryBuilderInterface {

    /**
     * Build the IN (...) list for the recognized_post_types option.
     *
     * @param array<string, mixed> $options
     * @return SqlFragment One `%s` per configured type, joined by commas, and the
     *   sanitized types. Both are empty when the setting is empty, and callers
     *   must not issue a query then, because IN () is invalid SQL.
     */
    public function buildPostTypeSqlList(array $options): array;

    /**
     * Build the IN (...) list for the recognized_categories option.
     *
     * @param array<string, mixed> $options
     * @return SqlFragment One `%s` per configured category, joined by commas, and
     *   the sanitized categories. Both are empty when the setting is empty.
     */
    public function buildCategorySqlList(array $options): array;

    /**
     * Set SQL session variables to allow large queries.
     *
     * @return void
     */
    public function setSqlBigSelects(): void;
}
