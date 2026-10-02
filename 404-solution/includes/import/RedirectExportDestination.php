<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides where an exported redirect points.
 *
 * The Data layer reads redirect rows; turning a row into the destination an
 * exported file should name is a decision about how WordPress resolves a post,
 * category, tag or home destination, so it lives here in Core next to
 * ABJ_404_Solution_PermalinkResolver, whose per-type resolution it reuses.
 * An exported rule has to point where the live redirect points: a category or
 * tag redirect's final_dest is a term id, which is not a post id, so only the
 * resolver knows which page the number names.
 *
 * Two output shapes share that decision:
 *   - forServerRule(): the destination of a server-format rule (.htaccess,
 *     nginx, Cloudflare, Netlify, Vercel); a row with no destination to
 *     export yields null and is left out.
 *   - csvColumns(): the columns of one native CSV row; a row whose
 *     destination no longer resolves keeps the stored to_url.
 *
 * Does NOT read the database and does NOT write files. See
 * ABJ_404_Solution_RedirectExportReader (rows) and
 * ABJ_404_Solution_NativeRedirectCsvWriter (CSV file).
 */
class ABJ_404_Solution_RedirectExportDestination {

    /**
     * Where an exported server-format rule sends the visitor: the same place
     * the live redirect does, or null when the row has no destination to
     * export (a deleted post or term, a "display the 404 page" row, an empty
     * external URL).
     *
     * A 410 or 451 row names its own source (the rule answers the request
     * itself). An external row names its final_dest, a home row names the
     * site's home URL, and a post, category or tag row names the URL
     * ABJ_404_Solution_PermalinkResolver::internalDestinationUrl() resolves.
     *
     * @param array{source: string, code: int, type: int, final_dest: string, cached_url: string} $row
     *        A row from RedirectExportReader::getExportableRedirectRows().
     * @return string|null The destination, or null when the row is not exportable.
     */
    public static function forServerRule(array $row): ?string {
        $code = $row['code'];
        if ($code === 410 || $code === 451) {
            return $row['source'];
        }

        $type = $row['type'];
        if ($type === (int)ABJ404_TYPE_EXTERNAL) {
            $dest = $row['final_dest'];
        } else if ($type === (int)ABJ404_TYPE_HOME) {
            $dest = function_exists('home_url') ? home_url('/') : '/';
        } else {
            $dest = ABJ_404_Solution_PermalinkResolver::internalDestinationUrl(
                $type,
                $row['final_dest'],
                $row['cached_url']
            );
        }

        return (is_string($dest) && $dest !== '') ? $dest : null;
    }

    /**
     * One getRedirectsExport.sql row as the native CSV columns:
     * from_url, status, type, to_url, wp_type, engine, code.
     *
     * A post, category or tag destination is written as the URL the live
     * redirect sends visitors to (PermalinkResolver::internalDestinationUrl()),
     * so the file names the page rather than a bare id; the importer reads a
     * bare id as an external URL named after the number. When the destination
     * no longer resolves, the query's own to_url (the stored id) is kept.
     *
     * @param array<string, mixed> $row One raw row of getRedirectsExport.sql,
     *        including its type_id, final_dest and cached_url columns.
     * @return array<int, bool|float|int|string>
     */
    public static function csvColumns(array $row): array {
        $toUrl = $row['to_url'] ?? null;
        $typeId = ABJ_404_Solution_ExactInteger::read($row['type_id'] ?? null, 0);
        if ($typeId !== null) {
            $finalDest = $row['final_dest'] ?? '';
            $cachedUrl = $row['cached_url'] ?? '';
            $resolved = ABJ_404_Solution_PermalinkResolver::internalDestinationUrl(
                $typeId,
                is_scalar($finalDest) ? (string)$finalDest : '',
                is_string($cachedUrl) ? $cachedUrl : ''
            );
            if ($resolved !== '') {
                $toUrl = $resolved;
            }
        }

        return array(
            self::csvField($row['from_url'] ?? '', ''),
            self::csvField($row['status'] ?? '', ''),
            self::csvField($row['type'] ?? '', ''),
            self::csvField($toUrl, ''),
            self::csvField($row['type_wp'] ?? '', ''),
            self::csvField($row['engine'] ?? null, ''),
            self::csvField($row['code'] ?? null, '301'),
        );
    }

    /**
     * A result-set value as a CSV field: scalars as they came from the driver,
     * anything else (null, a non-scalar) as $default.
     *
     * @param mixed $value
     * @param string $default
     * @return bool|float|int|string
     */
    private static function csvField($value, string $default) {
        return is_scalar($value) ? $value : $default;
    }
}
