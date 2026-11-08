<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL and bound values for one atomic redirect update.
 *
 * Owns placeholder alignment and explicit NULL clearing; policy admission,
 * source identity, execution and cache maintenance remain with the writer.
 * // allow-no-test-found: exercised through RestApiMutationEndToEndCharacterizationTest and RedirectWriteServiceUpdateAtomicityTest
 */
final class ABJ_404_Solution_RedirectUpdateStatement {

    /** @var string */
    private $sql;

    /** @var array<int, mixed> */
    private $params;

    /** @param array<int, mixed> $params */
    private function __construct(string $sql, array $params) {
        $this->sql = $sql;
        $this->params = $params;
    }

    /**
     * @param array{sourceURL: string, table: string, changes: array<string, string|null>} $context
     */
    public static function fromUpdate(ABJ_404_Solution_RedirectUpdate $update, array $context): self {
        $sourceURL = $context['sourceURL'];
        $table = $context['table'];
        $changes = $context['changes'];
        $startTs = $update->getStartTs();
        $endTs = $update->getEndTs();
        $updateData = array(
            'url' => $sourceURL,
            'status' => $update->getStatusType(),
            'type' => absint($update->getType()),
            'final_dest' => $update->getDestination(),
            'code' => esc_attr($update->getCode()),
        );
        $updateFormats = array('%s', '%d', '%d', '%s', '%d');

        if ($startTs !== null) {
            $updateData['start_ts'] = (int)$startTs;
            $updateFormats[] = '%d';
        }
        if ($endTs !== null) {
            $updateData['end_ts'] = (int)$endTs;
            $updateFormats[] = '%d';
        }

        $setFragments = array();
        $idx = 0;
        foreach ($updateData as $col => $unusedValue) {
            $format = isset($updateFormats[$idx]) ? $updateFormats[$idx] : '%s';
            $setFragments[] = '`' . $col . '` = ' . $format;
            $idx++;
        }

        // Explicit NULL clearing folded into the same SET clause as the rest
        // of the row (single merged UPDATE, not a second independent
        // statement): these carry no bound placeholder (NULL is a SQL
        // literal here, never passed through %d, which would coerce it to
        // 0), so appending them after the placeholder fragments does not
        // shift $updateParams' positional alignment. One statement means
        // there is nothing for a mid-request DB failure to leave half
        // applied.
        if ($startTs === null) {
            $setFragments[] = '`start_ts` = NULL';
        }
        if ($endTs === null) {
            $setFragments[] = '`end_ts` = NULL';
        }

        foreach ($changes as $mode) {
            if ($mode === null) {
                $setFragments[] = '`query_mode` = NULL';
            } else {
                $setFragments[] = '`query_mode` = %s';
                $updateData['query_mode'] = $mode;
            }
        }

        $updateSql = "UPDATE `" . $table . "` SET " . implode(', ', $setFragments) .
            " WHERE `id` = %d";
        $updateParams = array_values($updateData);
        $updateParams[] = absint($update->getId());
        return new self($updateSql, $updateParams);
    }

    public function sql(): string {
        return $this->sql;
    }

    /** @return array<int, mixed> */
    public function params(): array {
        return $this->params;
    }
}
