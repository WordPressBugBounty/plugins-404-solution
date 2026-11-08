<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for redirect CRUD, conditions, regex matching, and cleanup operations.
 *
 * Extracted from DataAccess in Phase 2 of the DataAccess refactor. Callers that
 * need redirect lookups, mutations, scheduled cleanup, or condition management
 * program against this interface.
 */
interface ABJ_404_Solution_RedirectsRepositoryInterface {

    /** Shared error returned when a stored-row mutation finds no row. */
    public const ROW_NOT_FOUND = 'Source redirect not found.';

    // =========================================================================
    // Redirect CRUD (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param int|string $id
     * @return void
     */
    public function deleteRedirect($id);

    /** Trash all live rows sharing this stored source, including legacy duplicates. */
    public function moveSourceRedirectsToTrash(int $id): string;

    /**
     * Fetch manual and regex redirects that are eligible for server-format
     * export, as stored. Where each one points is decided by
     * ABJ_404_Solution_RedirectExportDestination, not here.
     *
     * @return array<int, array{source: string, code: int, type: int, final_dest: string, cached_url: string, is_regex: bool}>
     */
    public function getExportableRedirectRows(): array;

    /**
     * Store a redirect for future use.
     *
     * Refactored in queue task c738 (audit source: design-audit-2026-05-29.md,
     * criterion 220 Interface Size). The previous 8-positional signature
     * (fromURL, status, type, final_dest, code, disabled, engine, score) had
     * two transposable URL strings and five overlapping numeric fields; all
     * those fields are now bundled into {@see ABJ_404_Solution_RedirectSpec}.
     *
     * @param ABJ_404_Solution_RedirectSpec $spec
     * @return int
     */
    public function setupRedirect(ABJ_404_Solution_RedirectSpec $spec);

    /**
     * Store a redirect only when no row already has its normalized source URL.
     * The persistence boundary performs the check and insert in one statement.
     *
     * @return int New row id, or 0 when the source already exists/write fails.
     */
    public function setupRedirectIfSourceAbsent(ABJ_404_Solution_RedirectSpec $spec): int;

    /**
     * Group already fenced import rows under one ownership scope.
     * Group contention retains ordinary per-row admission. A lost scope returns its cause.
     * @param callable():void $operation Bounded import batch, with per-row persistence.
     */
    public function runWithImportBatch(callable $operation): string;

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    public function getActiveRedirectForURL($url, $degradedMode = false);

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForURL($url);

    /**
     * Move redirects with the specified status/type values to trash.
     *
     * @param array<int, int|string> $types
     * @param string $purgeType
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function deleteSpecifiedRedirects(array $types, string $purgeType): array;

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array;

    /**
     * Read redirect conditions with the database acknowledgement retained.
     * An empty rows array is only authoritative when query_succeeded is true.
     *
     * @param int $redirectId
     * @return array{rows: array<int, array<string, mixed>>, last_error: string,
     *     query_succeeded: bool, timed_out: bool, table_available: bool}
     */
    public function readRedirectConditions(int $redirectId): array;

    /**
     * Replace the full condition set for a redirect. The delete + inserts run
     * inside a single transaction so a mid-replacement DB failure cannot leave
     * a partial condition set.
     *
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return string Error message on failure ('' on success).
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): string;

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /**
     * Mutate an existing redirect row. Inputs flow through
     * {@see ABJ_404_Solution_RedirectUpdate}, a typed parameter object that
     * replaced an eight-argument positional signature so $fromUrl and
     * $destination (both arbitrary strings) cannot be silently swapped.
     *
     * @return string Error message on validation failure, empty string on success.
     */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string;

    /**
     * Build and persist an update from the row read under the graph lock.
     *
     * @param int $id
     * @param callable(array<string, mixed>): (ABJ_404_Solution_RedirectUpdate|non-empty-string) $buildUpdate
     * @return string Empty on success; ROW_NOT_FOUND when absent; otherwise an error.
     */
    public function updateRedirectFromStoredRow(int $id, callable $buildUpdate): string;

    /**
     * Rows only, for display. A refused read and a missing row both come back
     * as an empty array; callers that mutate, or that tell the admin a row is
     * missing, must use readRedirectsByIDs().
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectsByIDs($ids);

    /**
     * Rows by id with the database error kept: empty `rows` and an empty
     * `last_error` is a row that does not exist, empty `rows` and an error is a
     * row nobody has seen.
     *
     * @param array<int, int|string> $ids
     * @return array{rows: array<int, array<string, mixed>>, last_error: string}
     */
    public function readRedirectsByIDs($ids);

    /**
     * @param int $id
     * @param string $newstatus
     * @return string
     */
    public function updateRedirectTypeStatus($id, $newstatus);

    /**
     * @param int $id
     * @param int $trash
     * @return string
     */
    public function moveRedirectsToTrash($id, $trash);

    // =========================================================================
    // Regex cache (from DataAccess static state)
    // =========================================================================

    /** @return void */
    public function clearRegexRedirectsCache(): void;

    // =========================================================================
    // Static utilities (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param mixed $url
     * @return string
     */
    public static function computeRedirectsCanonicalUrl($url): string;

    /**
     * @param string $columnExpr
     * @return string
     */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string;
}
