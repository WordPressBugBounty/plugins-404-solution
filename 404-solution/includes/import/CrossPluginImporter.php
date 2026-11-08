<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-plugin redirect importer.
 *
 * Public entry point for the cross-plugin import feature. It detects installed
 * redirect plugins, previews available redirects, and imports them into the
 * 404 Solution redirects table. Reading and normalizing the foreign sources is
 * delegated to {@see ABJ_404_Solution_ForeignRedirectSourceReader} (data
 * access); this class owns only the business logic of turning a normalized row
 * into a 404 Solution redirect (status/type resolution, RedirectSpec creation).
 *
 * Supported sources:
 *   - Rank Math (rank_math_redirections)
 *   - Yoast SEO Premium (yoast_seo_redirects)
 *   - AIOSEO (aioseo_redirects)
 *   - Safe Redirect Manager (redirect_rule CPT)
 *   - Redirection plugin (redirection_items)
 */
class ABJ_404_Solution_CrossPluginImporter {

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ForeignRedirectSourceReader */
    private $sourceReader;

    /** @var ABJ_404_Solution_ImportIssueSummary */
    private $issueSummary;

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseQueryInterface|null $dbQuery
     */
    public function __construct($redirectsRepository, $logger, $dbQuery = null) {
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logger;
        $this->issueSummary = new ABJ_404_Solution_ImportIssueSummary();
        $this->sourceReader = new ABJ_404_Solution_ForeignRedirectSourceReader(
            $redirectsRepository,
            $logger,
            $dbQuery
        );
    }

    /**
     * Detect which source plugins are installed by checking for their DB tables
     * (or, for Safe Redirect Manager, by checking for the CPT).
     *
     * @return array<string, bool> e.g. ['rankmath' => true, 'redirection' => false, ...]
     */
    public function detectInstalledPlugins(): array {
        return $this->sourceReader->detectInstalledPlugins();
    }

    /**
     * Return a preview of redirects available from the given source plugin.
     *
     * Consumes {@see ABJ_404_Solution_ForeignRedirectSourceReader::readSource()}
     * as a generator and stops as soon as $previewLimit rows are collected,
     * rather than materializing the full source (M502, 2026-07-14) --
     * readSource() only fetches one IMPORT_PAGE_SIZE page of rows per query,
     * so a small $previewLimit (the AJAX preview UI's default) typically
     * needs only a single page fetch regardless of the source's true size.
     *
     * @param string $source       One of 'rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection'
     * @param int    $previewLimit Maximum rows to return for preview
     * @return array<int, array<string, mixed>>
     */
    public function getImportPreview(string $source, int $previewLimit = 10): array {
        if ($previewLimit <= 0) {
            return array();
        }

        $preview = array();
        foreach ($this->sourceReader->readSource($source) as $row) {
            $preview[] = $row;
            if (count($preview) >= $previewLimit) {
                break;
            }
        }
        return $preview;
    }

    /**
     * Count redirects available from the given source plugin without
     * materializing the full row set. Used by the AJAX preview handler,
     * which only needs a number to display, not actual rows -- unlike
     * getImportPreview() above, this never reads more than the source
     * plugin's own storage needs to answer "how many" (see
     * {@see ABJ_404_Solution_ForeignRedirectSourceReader::countSource()}).
     *
     * @param string $source One of 'rankmath', 'yoast', 'aioseo',
     *                       'safe-redirect-manager', 'redirection'
     * @return int
     */
    public function countImportable(string $source): int {
        return $this->sourceReader->countSource($source);
    }

    /**
     * Import all redirects from the given source plugin.
     * Returns the number of redirects successfully imported.
     *
     * Consumes {@see ABJ_404_Solution_ForeignRedirectSourceReader::readSource()}
     * row-by-row as a generator rather than requiring a fully materialized
     * array upfront (M502, 2026-07-14): each row is written via
     * setupRedirect() as soon as it is read, so at most one source page is
     * ever held in memory regardless of how many rows the source plugin has.
     *
     * @param string $source
     * @return int
     */
    public function importFrom(string $source): int {
        $result = $this->importRows($this->sourceReader->readSource($source), $source);
        return $result['imported'];
    }

    /**
     * Import already-normalized rows through the same writer and cycle
     * handling used by the foreign-plugin path. The legacy Redirectioner
     * reader supplies native status/type/disabled/timestamp fields, while
     * foreign readers supply source_url/dest_url and are resolved here.
     *
     * @param iterable<int, array<string, mixed>> $rows
     * @return array{imported: int, issues: array<int, string>, issues_omitted: int, issue_details: array<int, string>}
     */
    public function importRows(iterable $rows, string $source): array {
        $imported = 0;
        $this->issueSummary = new ABJ_404_Solution_ImportIssueSummary();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $prepared = $this->prepareImportRow($row, $source);
                if ($prepared === null) {
                    continue;
                }
                if ($this->writeImportRow($prepared, $source)) {
                    $imported++;
                }
            }
        } catch (ABJ_404_Solution_ImportSourceReadException $e) {
            // The source gateway logged the underlying read failure. Preserve
            // completed writes and expose one bounded issue to the caller.
            $this->issueSummary->add($source . ': ' . $e->getMessage());
        }

        $this->logger->infoMessage(
            'CrossPluginImporter: imported ' . $imported . ' redirect(s) from "' . $source . '".'
        );

        return array(
            'imported' => $imported,
            'issues' => $this->issueSummary->messages(),
            'issues_omitted' => $this->issueSummary->omittedCount(),
            'issue_details' => $this->issueSummary->details(),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{source: string, fields: array<string, mixed>}|null
     */
    private function prepareImportRow(array $row, string $source): ?array {
        $legacy = $source === 'redirectioner';
        $sourceUrl = $this->scalarRowString($row, $legacy ? 'url' : 'source_url');
        $destUrl = $this->scalarRowString($row, $legacy ? 'final_dest' : 'dest_url');
        if ($sourceUrl === '' || $destUrl === '') {
            return null;
        }
        $queryMode = null;
        if (!$legacy) {
            try {
                $queryMode = ABJ_404_Solution_QueryStringHelper::importQueryMode($row, $source === 'redirection');
            } catch (InvalidArgumentException $e) {
                $this->recordImportIssue(array('source' => $sourceUrl, 'message' => $e->getMessage()));
                return null;
            }
        }
        $isRegex = !$legacy && !empty($row['is_regex']);
        $status = $legacy ? $this->rowInt($row, 'status', ABJ404_STATUS_MANUAL)
            : ($isRegex ? ABJ404_STATUS_REGEX : ABJ404_STATUS_MANUAL);
        $type = $legacy ? $this->rowInt($row, 'type', ABJ404_TYPE_EXTERNAL) : 0;
        if (!$legacy) {
            $resolved = $this->resolveDestinationType($destUrl);
            $type = $resolved['type'];
            $destUrl = $resolved['dest'];
        }
        $fields = array(
            'fromURL' => $sourceUrl,
            'status' => (string)$status,
            'type' => (string)$type,
            'finalDest' => $destUrl,
            'code' => (string)$this->rowInt($row, 'code', 301),
            'disabled' => $legacy ? $this->rowInt($row, 'disabled', 0) : 0,
        );
        if ($queryMode !== null) {
            $fields['query_mode'] = $queryMode;
        }
        if ($legacy) {
            $timestamp = ABJ_404_Solution_ExactInteger::read($row['timestamp'] ?? null, 0);
            if ($timestamp !== null) {
                $fields['timestamp'] = $timestamp;
            }
        }
        return array('source' => $sourceUrl, 'fields' => $fields);
    }

    /** @param array{source: string, fields: array<string, mixed>} $prepared */
    private function writeImportRow(array $prepared, string $source): bool {
        $sourceUrl = $prepared['source'];
        $fields = $prepared['fields'];
        $isLegacy = $source === 'redirectioner';
        $writer = $isLegacy && method_exists($this->redirectsRepository, 'setupRedirectIfSourceAbsent')
            ? 'setupRedirectIfSourceAbsent' : 'setupRedirect';
        try {
            $result = $this->redirectsRepository->{$writer}(
                ABJ_404_Solution_RedirectSpec::fromArray($fields)
            );
        } catch (ABJ_404_Solution_RedirectCycleException $e) {
            $this->recordImportIssue(array('source' => $sourceUrl, 'message' => $e->getMessage()));
            return false;
        }
        if ($result !== 0 && $result !== false) {
            return true;
        }
        if (method_exists($this->redirectsRepository, 'getLastWriteError')) {
            $cycleError = (string)$this->redirectsRepository->getLastWriteError();
            if ($cycleError !== '') {
                $this->recordImportIssue(array('source' => $sourceUrl, 'message' => $cycleError));
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private function scalarRowString(array $row, string $key): string {
        $value = $row[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key, int $default): int {
        return ABJ_404_Solution_ExactInteger::readOr($row[$key] ?? null, PHP_INT_MIN, $default);
    }

    /** @param array{source: string, message: string} $issue */
    private function recordImportIssue(array $issue): void {
        $this->issueSummary->add($issue['source'] . ': ' . $issue['message']);
        $this->logger->warn('CrossPluginImporter: ' . $issue['message'] . ' Source: ' . $issue['source']);
    }

    /** @return array<int, string> */
    public function getLastIssues(): array {
        return $this->issueSummary->messages();
    }

    /**
     * Resolve the redirect type and final destination for a given URL.
     *
     * External URLs (http/https) use ABJ404_TYPE_EXTERNAL with the URL as-is.
     * Internal paths are resolved via url_to_postid() — if a post ID is found,
     * ABJ404_TYPE_POST is used with the numeric ID. Otherwise ABJ404_TYPE_EXTERNAL
     * is used so the path is preserved and used as-is by the redirect pipeline.
     *
     * @param string $destUrl
     * @return array{type: int, dest: string}
     */
    private function resolveDestinationType(string $destUrl): array {
        if (preg_match('/^https?:\/\//i', $destUrl)) {
            return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
        }

        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = url_to_postid(home_url($destUrl));
            if ($postId > 0) {
                return array('type' => ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
    }
}
