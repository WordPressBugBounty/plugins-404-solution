<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects and ranks the two durable AJAX diagnostic journal channels.
 *
 * Support reports retain the broader failing-session view. Automatic AJAX
 * error reports name one or more requests and scope byte-budget competition
 * to those requests, while retaining carrier records whose client verdict
 * identifies a pinned request.
 */
final class ABJ_404_Solution_DiagnosticChannelEvidence {

    /**
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     * @param array<int, string> $pinned
     * @return array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection: array<string, mixed>}>
     */
    public static function collect(array $clientAttempts, array $pinned = array()): array {
        // The same reader identity controls availability and invocation: a
        // related writer being present says nothing about its journal reader.
        $sources = array();
        foreach (array(
            'ajax_stage_trace' => ABJ_404_Solution_AjaxTraceJournalReader::class,
            'ajax_checkpoints' => ABJ_404_Solution_CheckpointJournalReader::class,
        ) as $channel => $reader) {
            $sources[$channel] = class_exists($reader)
                ? $reader::supportCollectionSource()
                : array('channel' => $channel, 'directory' => '', 'usable' => false,
                    'paths' => array(), 'unavailable_reason' => $reader . ' could not be loaded');
        }

        // Build the failure index independently per channel. Each read has its
        // own file and byte budgets, so a merged path list could evict files
        // from one channel before that channel applies its own limits.
        $failingIds = array();
        foreach ($pinned as $requestId) {
            if (is_string($requestId) && $requestId !== '') {
                $failingIds[$requestId] = true;
            }
        }
        if (class_exists('ABJ_404_Solution_DiagnosticJournalExcerpt')) {
            foreach ($sources as $source) {
                if (!isset($source['unavailable_reason'])) {
                    $failingIds += ABJ_404_Solution_DiagnosticJournalExcerpt::failureIndex($source['paths']);
                }
            }
        }
        $clientOutcomes = isset($clientAttempts['outcomes']) && is_array($clientAttempts['outcomes'])
            ? $clientAttempts['outcomes'] : array();
        foreach ($clientOutcomes as $requestId => $healthy) {
            if ($healthy === false) {
                $failingIds[(string)$requestId] = true;
            }
        }
        if (class_exists('ABJ_404_Solution_StrandedRequestLedger')) {
            $failingIds += ABJ_404_Solution_StrandedRequestLedger::condemnedRequestIds();
        }

        $channels = array();
        foreach ($sources as $channel => $source) {
            $source['collected'] = '';
            $source['file_selection'] = array();
            if (!isset($source['unavailable_reason'])) {
                if (!class_exists('ABJ_404_Solution_DiagnosticJournalExcerpt')) {
                    $source['unavailable_reason'] = 'ABJ_404_Solution_DiagnosticJournalExcerpt could not be loaded';
                } else {
                    $source = self::collectSource($source, $failingIds, $pinned, $channel === 'ajax_stage_trace');
                }
            }
            if (!class_exists('ABJ_404_Solution_StrandedRequestLedger')) {
                $source['unavailable_reason'] = (isset($source['unavailable_reason'])
                    ? $source['unavailable_reason'] . '; ' : '')
                    . 'ABJ_404_Solution_StrandedRequestLedger could not be loaded';
            }
            $channels[] = $source;
        }
        return $channels;
    }

    /**
     * @param array{channel: string, directory: string, usable: bool, paths: array<int, string>} $source
     * @param array<string, bool> $failingIds
     * @param array<int, string> $pinned
     * @return array{channel: string, directory: string, usable: bool, paths: array<int, string>, collected: string, file_selection: array<string, mixed>}
     */
    private static function collectSource(
        array $source,
        array $failingIds,
        array $pinned,
        bool $isTrace
    ): array {
        $selection = ABJ_404_Solution_DiagnosticJournalFileSelector::select(
            $source['paths'],
            $failingIds
        );
        $source['file_selection'] = $selection['manifest'];
        if ($pinned !== array()) {
            $source['collected'] = self::collectPinned($source, $pinned, $selection, $isTrace);
        } elseif ($isTrace) {
            $source['collected'] = ABJ_404_Solution_AjaxTraceJournalReader::readRecentForSupport(
                $failingIds,
                $selection
            );
        } else {
            $source['collected'] = ABJ_404_Solution_CheckpointJournalReader::readRecentForSupport(
                $failingIds,
                $selection
            );
        }
        return $source;
    }

    /**
     * Restrict automatic failure evidence before allocating journal bytes so
     * older condemned request groups cannot crowd out the named failure.
     *
     * @param array{channel: string, paths: array<int, string>} $source
     * @param array<int, string> $pinned
     * @param array{paths: array<int, string>, manifest: array<string, mixed>} $selection
     */
    private static function collectPinned(
        array $source,
        array $pinned,
        array $selection,
        bool $isTrace
    ): string {
        $ids = array();
        foreach ($pinned as $requestId) {
            if (is_string($requestId) && $requestId !== '') {
                $ids[$requestId] = true;
            }
        }
        if ($ids === array()) {
            return '';
        }

        $budget = $isTrace
            ? ABJ_404_Solution_AjaxTraceJournalReader::MAX_SUPPORT_EXCERPT_BYTES
            : ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES;
        $header = $isTrace
            ? "Recent AJAX stage traces for the reported failure (JSONL):\n"
            : "Recent AJAX request checkpoints for the reported failure (JSONL):\n";
        return ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
            $source['paths'],
            $budget,
            $header,
            $ids,
            static function (array $lines) use ($ids, $isTrace): array {
                $scoped = array_values(array_filter(
                    $lines,
                    static function (string $line) use ($ids): bool {
                        $record = json_decode($line, true);
                        $requestId = is_array($record) && is_scalar($record['request_id'] ?? null)
                            ? (string)$record['request_id'] : '';
                        if (isset($ids[$requestId])) {
                            return true;
                        }
                        $reportedId = is_array($record)
                            ? ABJ_404_Solution_DiagnosticClientVerdict::condemnedRequestId($record)
                            : '';
                        return isset($ids[$reportedId]);
                    }
                ));
                return $isTrace
                    ? $scoped
                    : ABJ_404_Solution_CheckpointJournalReader::compactForSupport($scoped);
            },
            $selection
        );
    }

}
