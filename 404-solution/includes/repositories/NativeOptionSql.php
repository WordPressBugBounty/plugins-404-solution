<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recognizes, and rewrites, the fixed-shape SQL that WordPress's native
 * update_option() and add_option() send for ONE option row.
 *
 * SettingsOptionWriter keeps those native functions (and their hooks) and
 * fences the statements they emit instead. Everything here is matching on the
 * exact prepared literals: the serialized payload is compared as a literal and
 * never parsed, and a statement that is not exactly the known native shape is
 * reported as such so the writer can refuse it rather than guess.
 */
final class ABJ_404_Solution_NativeOptionSql {

    /** @var string Options table name without quoting. */
    private $bareTable;
    /** @var string Prepared SQL literal of the option name. */
    private $optionLiteral;

    /**
     * @param string $bareTable Options table name, unquoted.
     * @param string $optionLiteral The option name as a prepared SQL literal ('' matches nothing).
     */
    public function __construct(string $bareTable, string $optionLiteral) {
        $this->bareTable = $bareTable;
        $this->optionLiteral = $optionLiteral;
    }

    /**
     * The native SQL literal for a string value, or '' when wpdb cannot
     * produce one safely.
     *
     * @param object $wpdb
     */
    public static function literal($wpdb, string $value): string {
        $prepare = array($wpdb, 'prepare');
        if (!is_callable($prepare)) {
            return '';
        }
        $literal = call_user_func($prepare, '%s', $value);
        if (!is_string($literal)) {
            return '';
        }
        // wpdb::prepare() escapes every percent into a placeholder token.
        // The query filter receives SQL after core's priority-0 removal filter,
        // so compare and append the native literal representation here.
        $remove = array($wpdb, 'remove_placeholder_escape');
        if (!is_callable($remove)) {
            // Minimal wpdb adapters used by the existing real-DB bridge return
            // direct SQL from prepare() and have no placeholder-escape API.
            // A partial adapter that advertises placeholder escaping but cannot
            // remove it is unsafe, so fail closed in that shape.
            return is_callable(array($wpdb, 'placeholder_escape')) ? '' : $literal;
        }
        $literal = call_user_func($remove, $literal);
        return is_string($literal) ? $literal : '';
    }

    /** Whether the statement is an UPDATE with a WHERE clause. */
    public static function isUpdate(string $query): bool {
        return preg_match('/^\\s*UPDATE\\b/i', $query) === 1
            && preg_match('/\\bWHERE\\b/i', $query) === 1;
    }

    /** Whether the statement is an INSERT. */
    public static function isInsert(string $query): bool {
        return preg_match('/^\\s*INSERT\\b/i', $query) === 1;
    }

    /** Whether the statement is an UPDATE of, or INSERT INTO, the given options table. */
    public static function usesTable(string $query, string $bareTable): bool {
        return preg_match('/^\s*(?:UPDATE\s+|INSERT\s+INTO\s+)`?'
            . preg_quote($bareTable, '/') . '`?\b/is', $query) === 1;
    }

    /** Whether the statement is an UPDATE or INSERT that targets this option row. */
    public function writesOption(string $query): bool {
        return self::isUpdate($query) ? $this->updatesOption($query) : $this->insertsOption($query);
    }

    /** Whether an UPDATE names this option row in its WHERE clause. */
    public function updatesOption(string $query): bool {
        return $this->optionLiteral !== ''
            && preg_match('/(?:`option_name`|option_name)\\s*=\\s*'
                . preg_quote($this->optionLiteral, '/') . '/i', $query) === 1;
    }

    /** Whether an INSERT INTO the options table lists this option row first. */
    public function insertsOption(string $query): bool {
        return $this->optionLiteral !== ''
            && preg_match('/^\\s*INSERT\\s+INTO\\s+`?' . preg_quote($this->bareTable, '/')
                . '`?\\s*\\([^)]*\\)\\s+VALUES\\s*\\(\\s*'
                . preg_quote($this->optionLiteral, '/') . '(?:\\s*,|\\s*\\))/is', $query) === 1;
    }

    /** Whether the statement is exactly the native update_option() UPDATE writing this value. */
    public function isExactUpdate(string $query, string $valueLiteral): bool {
        $pattern = '/^\\s*UPDATE\\s+`?' . preg_quote($this->bareTable, '/') . '`?\\s+'
            . 'SET\\s+`?option_value`?\\s*=\\s*' . preg_quote($valueLiteral, '/')
            . '(?:\\s*,\\s*`?autoload`?\\s*=\\s*[^,]+)?'
            . '\\s+WHERE\\s+`?option_name`?\\s*=\\s*' . preg_quote($this->optionLiteral, '/')
            . '\\s*;?\\s*$/is';
        return preg_match($pattern, $query) === 1;
    }

    /** Whether the statement is exactly the native add_option() INSERT writing this value. */
    public function isExactInsert(string $query, string $valueLiteral): bool {
        return $this->matchExactInsert($query, $valueLiteral) !== null;
    }

    /**
     * Append a predicate to the WHERE clause of an UPDATE, keeping any trailing semicolon last.
     */
    public static function withWherePredicate(string $query, string $predicate): string {
        $suffix = '';
        if (substr(rtrim($query), -1) === ';') {
            $query = rtrim(substr(rtrim($query), 0, -1));
            $suffix = ';';
        }
        return $query . ' AND ' . $predicate . $suffix;
    }

    /**
     * Rewrite the native add_option() INSERT into INSERT ... SELECT so the row
     * is created only while the predicates hold and no row exists yet.
     * Returns null when the statement is not exactly the native shape.
     *
     * @param string $ownershipPredicate Site-lock ownership condition.
     * @param string $destinationPredicate Extra condition (starts with ' AND ') or ''.
     */
    public function insertContingentOn(string $query, string $valueLiteral,
            string $ownershipPredicate, string $destinationPredicate): ?string {
        $match = $this->matchExactInsert($query, $valueLiteral);
        if ($match === null) {
            return null;
        }
        return 'INSERT INTO ' . $match[1] . ' ' . $match[2]
            . ' SELECT ' . substr($match[3], 1, -1)
            . ' FROM DUAL WHERE ' . $ownershipPredicate
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $match[1]
            . ' WHERE option_name = ' . $this->optionLiteral . ')'
            . $destinationPredicate;
    }

    /**
     * Match the fixed native add_option shape for this option and value:
     * group 1 is the table, 2 the column list, 3 the VALUES tuple.
     *
     * @return array<int,string>|null
     */
    private function matchExactInsert(string $query, string $valueLiteral): ?array {
        if ($this->optionLiteral === '' || $valueLiteral === '') {
            return null;
        }
        $pattern = '/^\\s*INSERT\\s+INTO\\s+(`?' . preg_quote($this->bareTable, '/')
            . '`?)\\s*(\\(\\s*`?option_name`?\\s*,\\s*`?option_value`?\\s*,\\s*`?autoload`?\\s*\\))'
            . '\\s+VALUES\\s*(\\(\\s*' . preg_quote($this->optionLiteral, '/')
            . '\\s*,\\s*' . preg_quote($valueLiteral, '/')
            . '\\s*,\\s*\\x27[^\\x27]*\\x27\\s*\\))'
            . '(?:\\s+ON\\s+DUPLICATE\\s+KEY\\s+UPDATE\\s+`?option_name`?\\s*=\\s*VALUES\\s*\\(\\s*`?option_name`?\\s*\\)'
            . '\\s*,\\s*`?option_value`?\\s*=\\s*VALUES\\s*\\(\\s*`?option_value`?\\s*\\)'
            . '\\s*,\\s*`?autoload`?\\s*=\\s*VALUES\\s*\\(\\s*`?autoload`?\\s*\\))?\\s*;?\\s*$/is';
        if (preg_match($pattern, $query, $match) !== 1) {
            return null;
        }
        return $match;
    }
}
