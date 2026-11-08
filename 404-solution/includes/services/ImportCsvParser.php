<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses supported redirect-import CSV shapes into canonical row arrays.
 */
class ABJ_404_Solution_ImportCsvParser {

    /**
     * Stream canonical CSV rows, retaining the data-row index used by resume checkpoints.
     * Blank rows and the format header do not consume an index. Repeated data
     * headers remain visible so each caller retains its existing accounting.
     * The caller owns the handle and closes it even when this stream is discarded.
     * @param resource $fileHandle
     * @return \Generator<int,array{index:int,data:array<string,string>}>
     */
    public function readRows($fileHandle, string $delimiter, int $resumeFromDataRow = 0): \Generator {
        $headers = null;
        $index = 0;
        while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
            $data = is_array($row)
                ? array_map(static function ($value): string { return trim((string)$value); }, $row)
                : array();
            if (count($data) === 1 && $data[0] === '') { continue; }
            if ($headers === null && $this->isCompatibleImportHeaderRow($data)) {
                $headers = $this->normalizeImportHeaders($data);
                continue;
            }
            $currentIndex = $index++;
            if ($currentIndex < $resumeFromDataRow) { continue; }
            $mapped = $headers !== null ? $this->mapImportRowByHeaders($data, $headers)
                : $this->mapImportRowWithoutHeaders($data);
            yield array('index' => $currentIndex, 'data' => $mapped);
            if (isset($mapped['error'])) { return; }
        }
    }

    /**
     * @param mixed $line
     * @return array<string, string>
     */
    function splitCsvLine($line) {
        if (!is_string($line)) {
            $line = is_scalar($line) ? (string)$line : '';
        }

        $data = array_map(function($v) {
            return trim((string)$v);
        }, str_getcsv($line, ',', '"', '\\'));

        if (count($data) === 5) {
            return array(
                'from_url' => $data[0],
                'status'   => $data[1],
                'type'     => $data[2],
                'to_url'   => $data[3],
                'wp_type'  => $data[4]
            );
        } else if (count($data) === 2) {
            return array(
                'from_url' => $data[0],
                'to_url'   => $data[1]
            );
        }

        return array('error' => sprintf(__('Invalid CSV format. %d columns found but 2 or 5 expected.', '404-solution'), count($data)));
    }

    /**
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow($columns) {
        $normalized = $this->normalizeImportHeaders($columns);
        $fromIndex = $this->findImportHeaderIndex($normalized, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalized, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));
        return ($fromIndex !== -1 && $toIndex !== -1);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string|null>
     */
    function normalizeImportHeaders($columns) {
        return array_map(function($value) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
            $value = trim(strtolower((string)$value));
            return preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $value));
        }, $columns);
    }

    /**
     * @param array<int, string> $columns
     * @return string
     */
    function detectImportFormatFromHeaders($columns) {
        $normalized = $this->normalizeImportHeaders($columns);

        if (in_array('source', $normalized, true) &&
                in_array('target', $normalized, true) &&
                in_array('regex', $normalized, true)) {
            return 'redirection';
        }

        if (in_array('redirect_from', $normalized, true) &&
                in_array('redirect_to', $normalized, true)) {
            return 'safe_redirect_manager';
        }

        if (in_array('request', $normalized, true) &&
                in_array('destination', $normalized, true)) {
            return 'simple_301';
        }

        if ((in_array('from_url', $normalized, true) &&
                in_array('to_url', $normalized, true)) ||
                (in_array('from_url', $normalized, true) &&
                in_array('status', $normalized, true) &&
                in_array('type', $normalized, true) &&
                in_array('to_url', $normalized, true))) {
            return 'native';
        }

        return 'unknown';
    }

    /**
     * @param array<int, string> $row
     * @param array<int, string|null> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders($row, $normalizedHeaders) {
        $fromIndex = $this->findImportHeaderIndex($normalizedHeaders, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalizedHeaders, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));

        if ($fromIndex === -1 || $toIndex === -1) {
            return array('error' => __('Invalid CSV format. Could not map source/destination columns.', '404-solution'));
        }

        $from = array_key_exists($fromIndex, $row) ? trim((string)$row[$fromIndex]) : '';
        $to = array_key_exists($toIndex, $row) ? trim((string)$row[$toIndex]) : '';

        if ($from === '' && $to === '') {
            return array('from_url' => '', 'to_url' => '');
        }

        $result = array(
            'from_url' => $from,
            'to_url' => $to,
        );

        foreach (array('query_mode', 'match_data') as $field) {
            $index = $this->findImportHeaderIndex($normalizedHeaders, array($field));
            if ($index !== -1 && array_key_exists($index, $row)) {
                $result[$field] = trim((string)$row[$index]);
            }
        }

        $engineIndex = $this->findImportHeaderIndex($normalizedHeaders, array('engine'));
        if ($engineIndex !== -1 && array_key_exists($engineIndex, $row)) {
            $result['engine'] = trim((string)$row[$engineIndex]);
        }

        $codeIndex = $this->findImportHeaderIndex($normalizedHeaders, array('code', 'redirect_code', 'http_code'));
        if ($codeIndex !== -1 && array_key_exists($codeIndex, $row)) {
            $result['code'] = trim((string)$row[$codeIndex]);
        }

        $statusIndex = $this->findImportHeaderIndex($normalizedHeaders, array('status', 'redirect_status'));
        if ($statusIndex !== -1 && array_key_exists($statusIndex, $row)) {
            $result['status'] = trim((string)$row[$statusIndex]);
        }

        $typeIndex = $this->findImportHeaderIndex($normalizedHeaders, array('type'));
        if ($typeIndex !== -1 && array_key_exists($typeIndex, $row)) {
            $result['type'] = trim((string)$row[$typeIndex]);
        }

        $regexIndex = $this->findImportHeaderIndex($normalizedHeaders, array('regex', 'is_regex'));
        if ($regexIndex !== -1 && array_key_exists($regexIndex, $row)) {
            $result['regex'] = trim((string)$row[$regexIndex]);
        }

        return $result;
    }

    /**
     * @param array<int, string|null> $headers
     * @param array<int, string> $candidates
     * @return int
     */
    private function findImportHeaderIndex($headers, $candidates) {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $headers, true);
            if ($idx !== false) {
                return (int)$idx;
            }
        }
        return -1;
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, string>
     */
    function mapImportRowWithoutHeaders($columns) {
        $columns = array_values($columns);
        if (count($columns) === 8) {
            $row = $this->mapImportRowWithoutHeaders(array_slice($columns, 0, 7));
            $row['query_mode'] = trim((string)$columns[7]);
            return $row;
        }
        if (count($columns) === 7) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
                'engine'   => trim((string)$columns[5]),
                'code'     => trim((string)$columns[6]),
            );
        }
        if (count($columns) === 6) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
                'engine'   => trim((string)$columns[5]),
            );
        }
        if (count($columns) === 5) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
            );
        }
        if (count($columns) === 2) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'to_url'   => trim((string)$columns[1]),
            );
        }
        return array('error' => sprintf(__('Invalid CSV format. %d columns found but 2, 5, 6, or 7 expected.', '404-solution'), count($columns)));
    }

    /**
     * @param resource $fileHandle
     * @return string
     */
    function detectCsvDelimiterFromFile($fileHandle) {
        while (($line = fgets($fileHandle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $comma = count(str_getcsv($line, ',', '"', '\\'));
            $semicolon = count(str_getcsv($line, ';', '"', '\\'));
            $tab = count(str_getcsv($line, "\t", '"', '\\'));

            if ($semicolon > $comma && $semicolon >= $tab) {
                return ';';
            }
            if ($tab > $comma && $tab > $semicolon) {
                return "\t";
            }
            return ',';
        }
        return ',';
    }
}
