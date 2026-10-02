<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Joins SQL fragments into a SQL template, keeping every value out of the SQL text.
 *
 * A SqlFragment is SQL text with `%s` / `%d` placeholders plus the values for them, in
 * the order they appear (see DatabaseQueryBuilderInterface). The query executor rewrites
 * `{wp_...}` tokens across the whole statement and only THEN binds `query_params`, so a
 * value that is part of the text is rewritten along with the template. Assembling a
 * statement from fragments delivers the values as `query_params` instead.
 *
 * fill() replaces each `{slot}` marker in a single left-to-right pass and never scans an
 * inserted fragment again, so a fragment whose text mentions another marker keeps that
 * text. Markers with no slot (the executor's own table-name tokens) are left alone.
 *
 * @phpstan-import-type SqlFragment from ABJ_404_Solution_DatabaseQueryBuilderInterface
 */
final class ABJ_404_Solution_SqlFragmentTemplate {

    /**
     * Replace every `{name}` marker for which a slot exists.
     *
     * @param string $template SQL text holding `{name}` markers.
     * @param array<string, SqlFragment> $slots Fragment by marker name, without braces.
     * @return SqlFragment The joined text and every fragment's params, ordered by the
     *   position of the marker they replaced (repeats included).
     */
    public static function fill(string $template, array $slots): array {
        $fragmentsByMarker = array();
        foreach ($slots as $name => $fragment) {
            $fragmentsByMarker['{' . $name . '}'] = $fragment;
        }

        $sql = '';
        $params = array();
        $offset = 0;
        while (true) {
            $nextMarker = null;
            $nextPosition = 0;
            foreach ($fragmentsByMarker as $marker => $fragment) {
                $position = strpos($template, (string)$marker, $offset);
                if ($position !== false && ($nextMarker === null || $position < $nextPosition)) {
                    $nextMarker = (string)$marker;
                    $nextPosition = $position;
                }
            }
            if ($nextMarker === null) {
                break;
            }

            $fragment = $fragmentsByMarker[$nextMarker];
            $sql .= substr($template, $offset, $nextPosition - $offset) . $fragment['sql'];
            foreach ($fragment['params'] as $param) {
                $params[] = $param;
            }
            $offset = $nextPosition + strlen($nextMarker);
        }

        return array('sql' => $sql . substr($template, $offset), 'params' => $params);
    }

    /**
     * Join fragments end to end. Text is concatenated as given and params keep the order
     * of the fragments, which is the order of their placeholders.
     *
     * @param array<int, SqlFragment> $fragments
     * @return SqlFragment
     */
    public static function concat(array $fragments): array {
        $sql = '';
        $params = array();
        foreach ($fragments as $fragment) {
            $sql .= $fragment['sql'];
            foreach ($fragment['params'] as $param) {
                $params[] = $param;
            }
        }
        return array('sql' => $sql, 'params' => $params);
    }

    /**
     * Join fragments with $glue between them. Params keep the order of the fragments,
     * which is the order of their placeholders.
     *
     * @param string $glue Fixed SQL text such as ' OR '.
     * @param array<int, SqlFragment> $fragments
     * @return SqlFragment Empty when there are no fragments, so no glue is left dangling.
     */
    public static function join(string $glue, array $fragments): array {
        $texts = array();
        $params = array();
        foreach ($fragments as $fragment) {
            $texts[] = $fragment['sql'];
            foreach ($fragment['params'] as $param) {
                $params[] = $param;
            }
        }
        return array('sql' => implode($glue, $texts), 'params' => $params);
    }

    /**
     * The inside of an `IN (...)` list: one placeholder per value, comma separated, with
     * the values to bind. The values never enter the SQL text.
     *
     * @param array<int, int|float|string> $values
     * @param string $placeholder `%s` for strings, `%d` for integers.
     * @return SqlFragment Empty when there are no values. `IN ()` is invalid SQL, so the
     *   caller decides what an empty list means (skip the query, or match nothing).
     */
    public static function inList(array $values, string $placeholder = '%s'): array {
        $params = array_values($values);
        return array(
            'sql' => implode(', ', array_fill(0, count($params), $placeholder)),
            'params' => $params,
        );
    }

    /**
     * `(column LIKE %s OR column LIKE %s ...)` matching rows whose column contains any
     * needle. Each needle is bound as `%needle%`; its characters are not LIKE-escaped, so
     * a `%` or `_` in a needle stays a wildcard, exactly as when the needle was quoted into
     * the text.
     *
     * @param string $column Qualified column expression. SQL text, never a visitor value.
     * @param array<int, string> $needles
     * @return SqlFragment No needles yields `(1 = 0)`, which matches nothing and stays valid SQL.
     */
    public static function anyLike(string $column, array $needles): array {
        if ($needles === array()) {
            return array('sql' => '(1 = 0)', 'params' => array());
        }

        $conditions = array();
        $params = array();
        foreach ($needles as $needle) {
            $conditions[] = $column . ' LIKE %s';
            $params[] = '%' . $needle . '%';
        }
        return array('sql' => '(' . implode(' OR ', $conditions) . ')', 'params' => $params);
    }

    /**
     * A fragment that contributes nothing: an optional clause that is switched off.
     *
     * @return SqlFragment
     */
    public static function none(): array {
        return array('sql' => '', 'params' => array());
    }

    /**
     * Fixed SQL text with no values. Each `%` is doubled so prepare() reads it as a
     * literal percent sign rather than the start of a placeholder.
     *
     * Use it only for a statement that will be prepared, meaning one that also carries
     * bound params. The executor skips prepare() when there are none, and the doubled
     * signs would then reach the server as written.
     *
     * @param string $sql
     * @return SqlFragment
     */
    public static function literal(string $sql): array {
        return array('sql' => str_replace('%', '%%', $sql), 'params' => array());
    }
}
