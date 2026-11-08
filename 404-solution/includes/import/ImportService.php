<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns upload-level redirect-import orchestration.
 *
 * This service validates the uploaded file, streams it through
 * ImportCsvParser, delegates each canonical row to ImportRedirectRowProcessor,
 * and coordinates ImportProgressStore checkpoints so interrupted imports can
 * resume by re-uploading the same file content.
 */
class ABJ_404_Solution_ImportService {

    const IMPORT_PROGRESS_OPTION = 'abj404_import_progress';
    const IMPORT_PROGRESS_CHECKPOINT_INTERVAL = 50;
    /** Bound continuous graph ownership while retaining per-row persistence. */
    const MUTATION_BATCH_ROWS = 50;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ImportCsvParser */
    private $parser;

    /** @var ABJ_404_Solution_ImportProgressStore */
    private $progressStore;

    /** @var ABJ_404_Solution_ImportUploadValidator */
    private $uploadValidator;

    /** @var ABJ_404_Solution_ImportRedirectRowProcessor */
    private $rowProcessor;

    /**
     * Constructor supports two signatures for backward compatibility:
     *   (1) New: (RedirectsRepository, ContentRepository, Logging)
     *   (2) Legacy: (DataAccess, Logging) -- DataAccess delegates to modules
     *
     * @param mixed $redirectsRepoOrDataAccess RedirectsRepository or legacy DataAccess facade
     * @param mixed $contentRepoOrLogging ContentRepository or legacy Logging
     * @param ABJ_404_Solution_Logging|null $logging
     */
    function __construct($redirectsRepoOrDataAccess, $contentRepoOrLogging, $logging = null) {
        if ($logging === null) {
            /** @var ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepoOrDataAccess */
            $this->redirectsRepository = $redirectsRepoOrDataAccess;
            $this->contentRepository = (is_object($redirectsRepoOrDataAccess) && method_exists($redirectsRepoOrDataAccess, 'getContentRepo'))
                ? $redirectsRepoOrDataAccess->getContentRepo()
                : $redirectsRepoOrDataAccess;
            /** @var ABJ_404_Solution_Logging $contentRepoOrLogging */
            $this->logger = $contentRepoOrLogging;
        } else {
            /** @var ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepoOrDataAccess */
            $this->redirectsRepository = $redirectsRepoOrDataAccess;
            /** @var ABJ_404_Solution_ContentRepositoryInterface $contentRepoOrLogging */
            $this->contentRepository = $contentRepoOrLogging;
            $this->logger = $logging;
        }

        $this->parser = new ABJ_404_Solution_ImportCsvParser();
        $this->progressStore = new ABJ_404_Solution_ImportProgressStore(self::IMPORT_PROGRESS_OPTION);
        $this->uploadValidator = new ABJ_404_Solution_ImportUploadValidator();
        $this->rowProcessor = new ABJ_404_Solution_ImportRedirectRowProcessor(
            $this->redirectsRepository,
            $this->contentRepository,
            $this->logger
        );
    }

    /**
     * Expected formats:
     * - from_url,status,type,to_url,wp_type
     * - from_url,to_url
     *
     * @return string
     */
    function doImportFile(): string {
        $uploadFile = $_FILES['import_file'] ?? null;
        if (!is_array($uploadFile) ||
                ABJ_404_Solution_ExactInteger::readOr(
                    $uploadFile['error'] ?? null,
                    0,
                    -1
                ) !== UPLOAD_ERR_OK) {
            return __('File upload error.', '404-solution');
        }

        $dryRun = ABJ_404_Solution_RequestInputNormalizer::readText(
            $_POST, array('name' => 'dry_run')) === '1';
        $overwriteExisting = ABJ_404_Solution_RequestInputNormalizer::readText(
            $_POST, array('name' => 'overwrite_existing')) === '1';

        $validationError = $this->uploadValidator->validate($uploadFile);
        if ($validationError !== '') {
            return $validationError;
        }

        $tmpName = $this->uploadValidator->tmpName($uploadFile);
        $file_handle = fopen($tmpName, 'r');
        if (!$file_handle) {
            return __('Error opening the file.', '404-solution');
        }

        try {
            $hashResult = hash_file('sha256', $tmpName);
            $contentHash = ($dryRun || !is_string($hashResult)) ? '' : $hashResult;
            $runState = $this->initialRunState($contentHash, $dryRun);

            $delimiter = $this->parser->detectCsvDelimiterFromFile($file_handle);
            rewind($file_handle);
            $this->processFileRows(
                $file_handle,
                $delimiter,
                $dryRun,
                $overwriteExisting,
                $contentHash,
                $runState
            );
        } finally {
            fclose($file_handle);
        }

        $abortMessage = $runState->abortMessage();
        if ($abortMessage !== null) {
            return $abortMessage;
        }

        if (!$dryRun) {
            $this->progressStore->clearImportProgress();
        }

        return $this->formatImportResult($runState, $dryRun, $overwriteExisting);
    }

    /**
     * A fresh run, or one resumed from the checkpoint of an interrupted upload
     * of the same file content. Dry runs never resume.
     *
     * @param string $contentHash
     * @param bool $dryRun
     * @return ABJ_404_Solution_ImportRunState
     */
    private function initialRunState(string $contentHash, bool $dryRun): ABJ_404_Solution_ImportRunState {
        if ($dryRun) {
            return ABJ_404_Solution_ImportRunState::fresh();
        }

        $existingProgress = $this->progressStore->getResumeProgress($contentHash);
        return $existingProgress === null
            ? ABJ_404_Solution_ImportRunState::fresh()
            : ABJ_404_Solution_ImportRunState::fromProgress($existingProgress);
    }

    /**
     * @param resource $fileHandle
     * @param string $delimiter
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @param string $contentHash
     * @param ABJ_404_Solution_ImportRunState $state
     * @return void
     */
    private function processFileRows($fileHandle, string $delimiter, bool $dryRun,
                                     bool $overwriteExisting, string $contentHash,
                                     ABJ_404_Solution_ImportRunState $state): void {
        $rows = $this->parser->readRows($fileHandle, $delimiter, $state->resumeFrom());
        $batchError = $this->runImportBatches($rows, function (array $batch) use ($dryRun, $overwriteExisting, $contentHash, $state): bool {
            foreach ($batch as $entry) {
                $dataArray = $entry['data'];
                $dataRowsSeen = $entry['index'];
                if (isset($dataArray['error'])) { $state->abort($dataArray['error']); return false; }
                if ($this->isRepeatedHeaderDataRow($dataArray)) { continue; }
                $this->processDataArray(
                    $dataArray, $dataRowsSeen, $dryRun, $overwriteExisting, $contentHash, $state
                );
                $dataRowsSeen++;
                if (!$dryRun && ($dataRowsSeen % self::IMPORT_PROGRESS_CHECKPOINT_INTERVAL) === 0) {
                    $this->persistCheckpoint($contentHash, $dataRowsSeen, $state);
                }
                if ($state->abortMessage() !== null) { return false; }
            }
            return true;
        }, $dryRun);
        if ($batchError !== '') { $state->abort($batchError); }
    }

    /**
     * Prefetch a bounded batch before ownership, then persist its rows individually.
     * File parsing, including long blank runs, never holds the graph mutex.
     * @param \Generator<int,array{index:int,data:array<string,string>}> $rows
     * @param callable(array<int,array{index:int,data:array<string,string>}>):bool $operation False stops the file.
     */
    public function runImportBatches(\Generator $rows, callable $operation, bool $dryRun = false): string {
        while ($rows->valid()) {
            $batch = array();
            for ($row = 0; $row < self::MUTATION_BATCH_ROWS && $rows->valid(); $row++, $rows->next()) {
                $batch[] = $rows->current();
            }
            $continue = false;
            $error = $this->runWithImportBatch(function () use ($operation, $batch, &$continue): void {
                $continue = $operation($batch);
            }, $dryRun);
            if ($error !== '') { return $error; }
            if (!$continue) { break; }
        }
        return '';
    }

    /**
     * Dry runs retain ordinary read semantics; real rows keep their own admission fences.
     * @param callable():void $operation Bounded file batch.
     */
    private function runWithImportBatch(callable $operation, bool $dryRun = false): string {
        if ($dryRun) { $operation(); return ''; }
        $repository = $this->redirectsRepository;
        if ($repository instanceof ABJ_404_Solution_DataAccess) { $repository = $repository->getRedirectsRepo(); }
        return $repository->runWithImportBatch($operation);
    }

    /**
     * @param array<string, string> $dataArray
     * @return bool
     */
    private function isRepeatedHeaderDataRow(array $dataArray): bool {
        return isset($dataArray['from_url']) &&
            ($dataArray['from_url'] === 'from_url' || $dataArray['from_url'] === 'request');
    }

    /**
     * @param array<string, string> $dataArray
     * @param int $dataRowsSeen
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @param string $contentHash
     * @param ABJ_404_Solution_ImportRunState $state
     * @return void
     */
    private function processDataArray(array $dataArray, int $dataRowsSeen, bool $dryRun,
                                      bool $overwriteExisting, string $contentHash,
                                      ABJ_404_Solution_ImportRunState $state): void {
        try {
            $state->beginRow();
            $wasOverwrite = $this->wouldOverwriteExisting($dataArray, $overwriteExisting);
            $dataArray['__line_number'] = (string)($dataRowsSeen + 1);
            $issues = $this->rowProcessor->importRow($dataArray, $dryRun, $overwriteExisting);
            $state->recordRowOutcome($issues, $wasOverwrite);
        } catch (\Throwable $e) {
            $this->recordPausedImport($contentHash, $dataRowsSeen, $state, $dryRun, $e);
            $state->abort(sprintf(
                __('Import paused at row %1$d. %2$d redirect(s) imported so far. Re-upload the same file to resume from row %1$d.', '404-solution'),
                $dataRowsSeen + 1,
                $state->valid()
            ));
        }
    }

    /**
     * @param array<string, string> $dataArray
     * @param bool $overwriteExisting
     * @return bool
     */
    private function wouldOverwriteExisting(array $dataArray, bool $overwriteExisting): bool {
        if (!$overwriteExisting || !isset($dataArray['from_url']) || !is_string($dataArray['from_url'])) {
            return false;
        }
        $existing = $this->redirectsRepository->getExistingRedirectForURL($dataArray['from_url']);
        return is_array($existing)
            && ABJ_404_Solution_ExactInteger::readOr($existing['id'] ?? null, 1, 0) !== 0;
    }

    /**
     * @param string $contentHash
     * @param int $dataRowsSeen
     * @param ABJ_404_Solution_ImportRunState $state
     * @param bool $dryRun
     * @param Throwable $e
     * @return void
     */
    private function recordPausedImport(string $contentHash, int $dataRowsSeen, ABJ_404_Solution_ImportRunState $state,
                                        bool $dryRun, \Throwable $e): void {
        if (!$dryRun) {
            $this->progressStore->persistImportProgress($contentHash, array_merge(
                $state->progressSnapshot($dataRowsSeen, max(0, $state->processed() - 1)),
                array(
                    'last_error' => $e->getMessage(),
                    'paused_at'  => abj_clock()->now(),
                )
            ));
        }
        $this->logger->warn(sprintf(
            'Import paused at row %d of %d due to: %s',
            $dataRowsSeen + 1,
            $dataRowsSeen + 1,
            $e->getMessage()
        ));
    }

    /**
     * @param string $contentHash
     * @param int $dataRowsSeen
     * @param ABJ_404_Solution_ImportRunState $state
     * @return void
     */
    private function persistCheckpoint(string $contentHash, int $dataRowsSeen, ABJ_404_Solution_ImportRunState $state): void {
        $this->progressStore->persistImportProgress(
            $contentHash,
            $state->progressSnapshot($dataRowsSeen, $state->processed())
        );
    }

    /**
     * @param ABJ_404_Solution_ImportRunState $state
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @return string
     */
    private function formatImportResult(ABJ_404_Solution_ImportRunState $state, bool $dryRun, bool $overwriteExisting): string {
        $issues = $state->issueSummary()->messages($dryRun ? 20 : 100);
        if ($dryRun) {
            $msg = sprintf(
                __('Dry run complete. Valid redirects: %d. Invalid rows: %d. Total rows processed: %d.', '404-solution'),
                $state->valid(),
                $state->invalid(),
                $state->processed()
            );
            return count($issues) > 0
                ? $msg . ' ' . __('Preview issues:', '404-solution') . ' ' . implode(", <BR/>\n", $issues)
                : $msg;
        }
        if (count($issues) > 0) {
            return __('Error:', '404-solution') . ' ' . implode(", <BR/>\n", $issues);
        }
        if ($overwriteExisting && $state->overwritten() > 0) {
            return sprintf(
                __('The file seems to have loaded okay. %d existing redirect(s) were overwritten. Please check the redirects page.', '404-solution'),
                $state->overwritten()
            );
        }
        return __('The file seems to have loaded okay. Please check the redirects page.', '404-solution');
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @return array<int, string>
     */
    function loadDataArrayFromFile($dataArray, $dryRun = false, $overwriteExisting = false): array {
        return $this->rowProcessor->importRow($dataArray, $dryRun, $overwriteExisting);
    }

    /**
     * @param mixed $line
     * @return array<string, string>
     */
    function splitCsvLine($line): array {
        return $this->parser->splitCsvLine($line);
    }

    /** @param array<int, string> $columns @return bool */
    function isCompatibleImportHeaderRow($columns): bool {
        return $this->parser->isCompatibleImportHeaderRow($columns);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string|null>
     */
    function normalizeImportHeaders($columns): array {
        return $this->parser->normalizeImportHeaders($columns);
    }

    /** @param array<int, string> $columns @return string */
    function detectImportFormatFromHeaders($columns): string {
        return $this->parser->detectImportFormatFromHeaders($columns);
    }

    /**
     * @param array<int, string> $row
     * @param array<int, string|null> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders($row, $normalizedHeaders): array {
        return $this->parser->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, string>
     */
    function mapImportRowWithoutHeaders($columns): array {
        return $this->parser->mapImportRowWithoutHeaders($columns);
    }

    /** @param resource $fileHandle @return string */
    function detectCsvDelimiterFromFile($fileHandle): string {
        return $this->parser->detectCsvDelimiterFromFile($fileHandle);
    }
}
