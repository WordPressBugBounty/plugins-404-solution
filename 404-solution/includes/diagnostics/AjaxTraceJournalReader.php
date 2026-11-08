<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read side of the AJAX stage-trace journal: everything the support-collection
 * pipeline is served from it.
 *
 * Split out of ABJ_404_Solution_AjaxTraceJournal, which is now the write-ahead
 * spool and retention owner and nothing else. The dependency stays
 * one-directional by design, exactly as for ABJ_404_Solution_CheckpointJournalReader:
 * this reader learns the journal's file names from the journal's constants, and
 * the journal never calls back into the reader, so a bug in support collection
 * cannot take trace recording down with it.
 *
 * Consumers: ABJ_404_Solution_DiagnosticChannelEvidence (the bounded support
 * excerpt), the evidence classes that read the trace whole
 * (CanaryReceiptEvidence, EncodedTableResponseSize, FailingSessionEvidence,
 * ResponseBodyDeliveryEvidence) and ABJ_404_Solution_DeveloperLogMailer (the
 * unbounded archive).
 */
final class ABJ_404_Solution_AjaxTraceJournalReader {

    /**
     * Share of the support payload's excerpt field this journal may claim.
     * Smaller than the checkpoint journal's because a request costs ~11 stage
     * records here against ~27 checkpoints. The per-section budgets are proven
     * to sum inside the report contract by SupportExcerptBudgetContractTest.
     */
    const MAX_SUPPORT_EXCERPT_BYTES = 49152;

    /**
     * Bounded recent journal lines for the existing support-request payload,
     * newest files last. Pending spools are included on purpose: a request
     * that is hung RIGHT NOW has written nothing to the journal yet, and it
     * is the most interesting request in the file.
     *
     * This journal holds no client verdicts of its own -- they are written to
     * the checkpoint journal -- so a caller that omits $knownFailingIds gets an
     * excerpt that cannot tell a browser-lost request from a healthy one. See
     * ABJ_404_Solution_DiagnosticJournalExcerpt::failureIndex().
     *
     * @param array<string, bool> $knownFailingIds
     * @param array{paths: array<int, string>, manifest: array<string, mixed>}|null $fileSelection
     *   Shared selection plan used by the primary support manifest.
     */
    public static function readRecentForSupport(
        array $knownFailingIds = array(),
        ?array $fileSelection = null
    ): string {
        try {
            $directory = ABJ_404_Solution_DiagnosticDirectoryResolver::resolve();
            if ($directory === '') {
                return '';
            }
            return ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
                self::supportExcerptPaths($directory),
                self::MAX_SUPPORT_EXCERPT_BYTES,
                "Recent AJAX stage traces (JSONL):\n",
                $knownFailingIds,
                null,
                $fileSelection
            );
        } catch (Throwable $e) {
            self::reportFailure('AJAX trace support excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * What readRecentForSupport() will look at, whether or not any of it
     * exists, so ABJ_404_Solution_DiagnosticCollectionManifest can state what
     * was checked even when the answer is "nothing was there".
     *
     * The candidate list comes from the same private helper the reader itself
     * uses: a manifest that described a DIFFERENT set of files than the read
     * would be worse than no manifest at all.
     *
     * `writer_arming` is reported alongside, because a channel whose WRITER is
     * opt-in has a second way to come back empty that stats of the directory
     * cannot see. Support report 2026-08-27 (Azure App Service, plugin 4.3.4)
     * spent the whole capture on that difference: usable directory, writable
     * directory, zero files, and no way to learn that
     * ABJ_404_Solution_AjaxDiagnosticRequestPolicy had never armed the writer
     * on that site. "Nothing to record" and "recording was off" must not
     * produce the same manifest.
     *
     * @return array{channel: string, directory: string, usable: bool, paths: array<int, string>,
     *   writer_arming: array<string, mixed>}
     */
    public static function supportCollectionSource(): array {
        try {
            $directory = ABJ_404_Solution_DiagnosticDirectoryResolver::resolve();
            return array(
                'channel' => 'ajax_stage_trace',
                'directory' => $directory,
                'usable' => $directory !== '',
                'paths' => $directory === '' ? array() : self::supportExcerptPaths($directory),
                'writer_arming' => self::writerArming(),
            );
        } catch (Throwable $e) {
            self::reportFailure('AJAX trace support source resolution failed: ' . $e->getMessage());
            return array(
                'channel' => 'ajax_stage_trace',
                'directory' => '',
                'usable' => false,
                'paths' => array(),
                'writer_arming' => self::writerArming(),
            );
        }
    }

    /**
     * Existing journal and pending-spool files, for a channel that carries
     * them WHOLE. See CheckpointJournalReader::supportArchivePaths().
     *
     * @return array<int, string>
     */
    public static function supportArchivePaths(): array {
        try {
            $directory = ABJ_404_Solution_DiagnosticDirectoryResolver::resolve();
            if ($directory === '') {
                return array();
            }
            $paths = array();
            foreach (array(
                ABJ_404_Solution_AjaxTraceJournal::JOURNAL_FILE,
                ABJ_404_Solution_AjaxTraceJournal::ROTATED_FILE,
            ) as $name) {
                $exists = ABJ_404_Solution_FileSystemService::captureFilesystemWarnings(
                    static function () use ($directory, $name): bool { return is_file($directory . $name); }
                );
                if ($exists['result']) {
                    $paths[] = $directory . $name;
                }
            }
            return array_merge($paths, self::pendingPaths($directory));
        } catch (Throwable $e) {
            self::reportFailure('AJAX trace archive path resolution failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * What the trace WRITER would do on this site right now. Never throws: a
     * manifest that cannot describe the arming policy still has to describe the
     * files, so an unavailable policy is reported as such rather than removing
     * the field.
     *
     * @return array<string, mixed>
     */
    private static function writerArming(): array {
        if (!class_exists('ABJ_404_Solution_AjaxDiagnosticRequestPolicy')) {
            return array('status' => 'unavailable', 'reason' => 'policy_class_unavailable');
        }
        try {
            return ABJ_404_Solution_AjaxDiagnosticRequestPolicy::armingState();
        } catch (Throwable $e) {
            self::reportFailure('AJAX trace arming state failed: ' . $e->getMessage());
            return array('status' => 'unavailable', 'reason' => 'policy_read_failed');
        }
    }

    /**
     * Rotated file, current journal, then any live pending spools -- oldest
     * first, which is the order the excerpt reader breaks mtime ties on.
     *
     * @param string $directory With a trailing separator.
     * @return array<int, string>
     */
    private static function supportExcerptPaths(string $directory): array {
        $paths = array(
            $directory . ABJ_404_Solution_AjaxTraceJournal::ROTATED_FILE,
            $directory . ABJ_404_Solution_AjaxTraceJournal::JOURNAL_FILE,
        );
        return array_merge($paths, self::pendingPaths($directory));
    }

    /**
     * Live pending spools. Globbed here, from the journal's published pattern,
     * rather than through a method on the journal: during an update a worker
     * can hold the previous release's journal class, which has the constants
     * but not any helper added since, and a support report must still read.
     *
     * @param string $directory With a trailing separator.
     * @return array<int, string>
     */
    private static function pendingPaths(string $directory): array {
        $pattern = $directory . ABJ_404_Solution_AjaxTraceJournal::PENDING_GLOB;
        $result = ABJ_404_Solution_HostFilesystemCall::glob($pattern);
        if ($result['warning'] !== null && $result['warning'] !== '') {
            self::reportFailure('AJAX trace pending glob failed: ' . $pattern
                . ' (' . $result['warning'] . ')');
        }
        return is_array($result['result']) ? $result['result'] : array();
    }

    private static function reportFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
