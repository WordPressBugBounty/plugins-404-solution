<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site and database connection one settings write is aimed at, captured
 * when the write starts.
 *
 * Native option hooks can switch to another site or repoint the global wpdb
 * while a write is in flight. Comparing the live globals with this snapshot is
 * how SettingsOptionWriter tells a statement meant for its own site apart from
 * one that only looks like it. An unbound target (nothing captured yet) is
 * never current.
 */
final class ABJ_404_Solution_SettingsWriteTarget {

    /** @var object|null Immutable wpdb instance for the checked write. */
    private $wpdb;
    /** @var int */
    private $blogId;
    /** @var string */
    private $prefix;
    /** @var string */
    private $optionsTable;
    /** @var string */
    private $postsTable;
    /** @var string */
    private $databaseName;

    /**
     * @param array{wpdb:object|null,blogId:int,prefix:string,optionsTable:string,postsTable:string,databaseName:string} $context
     */
    private function __construct(array $context) {
        $this->wpdb = $context['wpdb'];
        $this->blogId = $context['blogId'];
        $this->prefix = $context['prefix'];
        $this->optionsTable = $context['optionsTable'];
        $this->postsTable = $context['postsTable'];
        $this->databaseName = $context['databaseName'];
    }

    /** A target that has captured nothing and is never current. */
    public static function unbound(): self {
        return new self(array(
            'wpdb' => null,
            'blogId' => 0,
            'prefix' => '',
            'optionsTable' => '',
            'postsTable' => '',
            'databaseName' => '',
        ));
    }

    /** Capture the site and connection that are current right now. */
    public static function capture(): self {
        global $wpdb;
        return new self(array(
            'wpdb' => is_object($wpdb) ? $wpdb : null,
            'blogId' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'prefix' => is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '',
            'optionsTable' => is_object($wpdb) && isset($wpdb->options) ? (string) $wpdb->options : '',
            'postsTable' => is_object($wpdb) && isset($wpdb->posts) ? (string) $wpdb->posts : '',
            'databaseName' => is_object($wpdb) && isset($wpdb->dbname) ? (string) $wpdb->dbname : '',
        ));
    }

    /** Whether the live wpdb, site, prefix and tables are still the captured ones. */
    public function isCurrent(): bool {
        global $wpdb;
        if ($this->wpdb === null || !is_object($wpdb)
            || spl_object_id($wpdb) !== spl_object_id($this->wpdb)) {
            return false;
        }
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return $blogId === $this->blogId
            && (string) ($wpdb->prefix ?? '') === $this->prefix
            && (string) ($wpdb->options ?? '') === $this->optionsTable
            && (string) ($wpdb->posts ?? '') === $this->postsTable
            && (string) ($wpdb->dbname ?? '') === $this->databaseName;
    }

    /** @return object|null The captured wpdb, or null for an unbound target or no wpdb. */
    public function wpdb(): ?object {
        return $this->wpdb;
    }

    /** @return string The captured options table name, unquoted. */
    public function optionsTable(): string {
        return $this->optionsTable;
    }

    /** @return string The captured posts table name, unquoted. */
    public function postsTable(): string {
        return $this->postsTable;
    }

    /**
     * Quote a table name for use in SQL, or return '' when it is not a plain
     * identifier.
     *
     * @param mixed $table
     */
    public static function safeTable($table): string {
        if (!is_string($table) || $table === '' || preg_match('/^[A-Za-z0-9_$]+$/D', $table) !== 1) {
            return '';
        }
        return '`' . $table . '`';
    }
}
