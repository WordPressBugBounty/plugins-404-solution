<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes the native redirect CSV, the round-trip-able shape this plugin's own
 * importer reads, from a stream of getRedirectsExport.sql rows.
 *
 * The rows arrive one at a time (ViewReadService::redirectsExportRows()) and
 * are written as they come, so memory stays bounded on large redirect tables.
 * Each row's destination is decided by
 * ABJ_404_Solution_RedirectExportDestination::csvColumns().
 *
 * Does NOT query the database. See ABJ_404_Solution_ExportService, which
 * pairs this writer with the row source.
 */
class ABJ_404_Solution_NativeRedirectCsvWriter {

    /** Header row of the native CSV; the importer matches columns by these names. */
    const HEADER = array('from_url', 'status', 'type', 'to_url', 'wp_type', 'engine', 'code');

    /**
     * Write the native CSV to $tempFile, replacing any file already there.
     *
     * A null $rows means the export query could not run: any old file is
     * removed and none is created, so callers see "nothing to export". An
     * empty $rows (a query that ran and returned no redirects) still writes
     * the header-only file.
     *
     * @param string $tempFile Path of the CSV to create.
     * @param iterable<int, array<string, mixed>>|null $rows Raw export rows, or
     *        null when the export query failed.
     * @return void
     */
    public static function write(string $tempFile, ?iterable $rows): void {
        if (file_exists($tempFile)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($tempFile);
        }
        if ($rows === null) {
            return;
        }

        $fh = fopen($tempFile, 'w');
        if ($fh === false) {
            return;
        }
        // try/finally: a row source that throws mid-stream (mysqli's default
        // error mode, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT since PHP 8.1,
        // throws mysqli_sql_exception on a dropped connection mid-fetch) must
        // not skip fclose($fh) and leak the file handle. Same resource-lifecycle
        // shape as includes/import/ImportService.php::doImportFile().
        try {
            fputcsv($fh, self::HEADER, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($fh, ABJ_404_Solution_RedirectExportDestination::csvColumns($row), ',', '"', '\\');
            }
        } finally {
            fclose($fh);
        }
    }
}
