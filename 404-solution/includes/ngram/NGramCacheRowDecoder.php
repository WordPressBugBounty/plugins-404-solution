<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decodes stored N-gram JSON at the cache read boundary.
 *
 * Full-cache inspection preserves malformed blobs as null; filtered candidate
 * reads omit unsafe gram collections and report their row ID. Both reads honor
 * the matching time budget and discard partial results when it is exhausted.
 * No queries or cache writes belong here.
 */
class ABJ_404_Solution_NGramCacheRowDecoder {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger Receives corrupt-data and budget diagnostics. */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Decode a single page's N-grams, requiring both gram collections.
     *
     * @param string $json
     * @return array{bi: array<int, string>, tri: array<int, string>}|null
     */
    public function decodePage(string $json): ?array {
        $decoded = json_decode($json, true);
        if (!$this->hasValidGramCollections($decoded)) {
            return null;
        }
        /** @var array{bi: array<int, string>, tri: array<int, string>} $decoded */
        return $decoded;
    }

    /** @param mixed $decoded Decoded cache data, with arbitrary historical array keys. */
    private function hasValidGramCollections($decoded): bool {
        if (!is_array($decoded) || !is_array($decoded['bi'] ?? null) || !is_array($decoded['tri'] ?? null)) {
            return false;
        }
        foreach (array($decoded['bi'], $decoded['tri']) as $collection) {
            foreach ($collection as $gram) {
                if (!is_string($gram)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Decode full-cache inspection rows, accepting object rows as well as arrays.
     *
     * @param array<int, mixed> $rows
     * @param ABJ_404_Solution_MatchingTimeBudget|null $budget
     * @return array<int, array<string, mixed>>
     */
    public function decodeAllRows(array $rows, ?ABJ_404_Solution_MatchingTimeBudget $budget = null): array {
        $output = [];
        $decoded = 0;
        foreach ($rows as $row) {
            if ($decoded % 100 === 0 && $this->decodeBudgetTripped($decoded, $budget)) {
                return [];
            }
            $decoded++;
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $ngramsRaw = isset($row['ngrams']) && is_string($row['ngrams']) ? $row['ngrams'] : '';
            $row['ngrams'] = $this->decodePage($ngramsRaw);
            $output[] = $row;
        }

        return $output;
    }

    /** Shared trip check for the decode loops: a null budget never trips; true means stop and return []. */
    private function decodeBudgetTripped(int $decoded, ?ABJ_404_Solution_MatchingTimeBudget $budget): bool {
        if ($budget === null || (!$budget->isExhausted() && $budget->hasTimeFor(0.05))) {
            return false;
        }
        $budget->markExhausted();
        $this->logger->debugMessage("N-gram decode: time budget exhausted after {$decoded} rows, returning no candidates");
        return true;
    }

    /**
     * Decode filtered candidates, omitting unsafe grams with a row warning.
     *
     * @param array<int, mixed> $rows
     * @param ABJ_404_Solution_MatchingTimeBudget|null $budget Request time budget (null = unbounded).
     * @return array<int, array<string, mixed>>
     */
    public function decodeFilteredRows(array $rows, ?ABJ_404_Solution_MatchingTimeBudget $budget = null): array {
        $validResults = [];
        $rowsDecoded = 0;
        foreach ($rows as $row) {
            if ($rowsDecoded % 100 === 0 && $this->decodeBudgetTripped($rowsDecoded, $budget)) {
                return [];
            }
            $rowsDecoded++;
            if (!is_array($row)) {
                continue;
            }
            $ngramsJson = isset($row['ngrams']) && is_string($row['ngrams']) ? $row['ngrams'] : '';
            $decoded = json_decode($ngramsJson, true);
            $jsonError = json_last_error();
            // Historical JSON null is a safe zero-score candidate. Every
            // non-null payload must provide arrays of string gram members.
            if ($jsonError !== JSON_ERROR_NONE || ($decoded !== null && !$this->hasValidGramCollections($decoded))) {
                $rowId = isset($row['id']) ? $row['id'] : 0;
                $this->logger->warn(sprintf(
                    "Corrupt N-gram JSON for page ID %s: %s",
                    (is_scalar($rowId) ? (string)$rowId : '0'),
                    $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : 'Expected bi and tri arrays of strings.'
                ));
                continue;
            }
            $row['ngrams'] = $decoded;
            $validResults[] = $row;
        }
        return $validResults;
    }

}
