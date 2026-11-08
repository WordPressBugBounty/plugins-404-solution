<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL and bound values for one redirect insert.
 *
 * Owns the persistence-level distinction between an ordinary insert and the
 * conditional forms used by source-absence evidence capture and automatic
 * redirects. execute() makes those forms atomic with a SERIALIZABLE
 * transaction; a lone INSERT...SELECT is not sufficient under
 * READ COMMITTED isolation.
 *
 * // allow-no-test-found: exercised by RedirectWriteServiceUpdateAtomicityTest
 */
final class ABJ_404_Solution_RedirectInsertStatement {

    /** @var string */
    private $sql;

    /** @var array<int, mixed> */
    private $params;

    /** @var 'any'|'manual'|'none' Source admission determines transaction isolation. */
    private $sourceConflictScope;

    /** @var int Live owner row this statement promotes; zero for a new row. */
    private $promotedId;

    /**
     * @param string $sql
     * @param array<int, mixed> $params
     * @param 'any'|'manual'|'none' $sourceConflictScope
     * @param int $promotedId
     */
    private function __construct(string $sql, array $params, string $sourceConflictScope, int $promotedId = 0) {
        $this->sql = $sql;
        $this->params = $params;
        $this->sourceConflictScope = $sourceConflictScope;
        $this->promotedId = $promotedId;
    }

    /**
     * Convert a domain redirect and its database context into a bound statement.
     *
     * @param ABJ_404_Solution_RedirectSpec $spec
     * @param array{table: string, sourceUrl: string, timestamp: int, canonicalUrl: string,
     *   liveColumns: ABJ_404_Solution_RedirectsLiveColumnSet, sourceConflictScope: 'any'|'manual'|'none', ownershipPredicate?: string, existingId?: int} $context
     * @return self
     */
    public static function fromSpec(ABJ_404_Solution_RedirectSpec $spec, array $context): self {
        $engine = $spec->getEngine();
        $score = $spec->getScore();
        return self::fromRequest($context + array(
            'status' => $spec->getStatus(), 'type' => $spec->getType(),
            'finalDest' => $spec->getFinalDest(), 'code' => $spec->getCode(),
            'disabled' => $spec->getDisabled(), 'queryMode' => $spec->getQueryMode(),
            'engine' => $engine === null ? null : (string)$engine,
            'score' => $score === null ? null : (float)$score,
        ));
    }

    /**
     * @param array{table: string, sourceUrl: string, status: int|string, type: int|string,
     *        finalDest: string, code: int|string, disabled: int, timestamp: int,
     *        canonicalUrl: string,
     *        engine: string|null, score: float|null, liveColumns: ABJ_404_Solution_RedirectsLiveColumnSet,
     *        queryMode?: string|null, sourceConflictScope: 'any'|'manual'|'none', ownershipPredicate?: string, existingId?: int} $request
     */
    public static function fromRequest(array $request): self {
        $table = $request['table'];
        $insertData = array(
            'url' => $request['sourceUrl'],
            'status' => $request['status'],
            'type' => $request['type'],
            'final_dest' => $request['finalDest'],
            'code' => $request['code'],
            'disabled' => $request['disabled'],
            'timestamp' => $request['timestamp'],
        );
        $insertFormats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');
        $liveColumns = $request['liveColumns'];
        $optionalCandidates = array(array(
            'columnName' => 'canonical_url',
            'value' => $request['canonicalUrl'],
            'format' => '%s',
        ));
        if ($request['engine'] !== null) {
            $optionalCandidates[] = array(
                'columnName' => 'engine',
                'value' => substr($request['engine'], 0, 64),
                'format' => '%s',
            );
        }
        if ($request['score'] !== null) {
            $optionalCandidates[] = array(
                'columnName' => 'score',
                'value' => round($request['score'], 2),
                'format' => '%f',
            );
        }
        if (($request['queryMode'] ?? null) !== null) {
            $optionalCandidates[] = array('columnName' => 'query_mode', 'value' => $request['queryMode'], 'format' => '%s');
        }
        foreach ($optionalCandidates as $candidate) {
            $presentCandidate = $liveColumns->candidateIfPresent($candidate);
            if ($presentCandidate === null) {
                continue;
            }
            $insertData[$presentCandidate['columnName']] = $presentCandidate['value'];
            $insertFormats[] = $presentCandidate['format'];
        }

        // Promoting captured evidence preserves its id, hit history and
        // timestamp. The writer holds the same graph lock as ordinary inserts.
        if (($request['existingId'] ?? 0) > 0) {
            unset($insertData['timestamp']);
            $formats = array('url' => '%s', 'status' => '%d', 'type' => '%d',
                'final_dest' => '%s', 'code' => '%d', 'disabled' => '%d',
                'canonical_url' => '%s', 'engine' => '%s', 'score' => '%f', 'query_mode' => '%s');
            $sets = array();
            foreach ($insertData as $column => $value) { $sets[] = '`' . $column . '` = ' . $formats[$column]; }
            $params = array_values($insertData);
            $params[] = $request['existingId'];
            return new self('UPDATE `' . $table . '` SET ' . implode(', ', $sets)
                . ' WHERE id = %d AND disabled = 0 AND ' . ($request['ownershipPredicate'] ?? '1=1'), $params, 'none',
                (int)$request['existingId']);
        }

        $sql = "INSERT INTO `" . $table . "` (`" .
            implode('`, `', array_keys($insertData)) . "`) ";
        $params = array_values($insertData);

        $scope = $request['sourceConflictScope'] ?? 'none';
        $ownership = $request['ownershipPredicate'] ?? '1=1';
        if ($scope !== 'none' || $ownership !== '1=1') {
            // The transaction executor runs this indexed absence check under
            // SERIALIZABLE isolation. Concurrent callers therefore cannot
            // both pass the range check; a retried deadlock loser observes the
            // winner without a UNIQUE(url) constraint, so archived rows can
            // retain the source while the writer admits only one live owner.
            $sql .= "SELECT " . implode(', ', $insertFormats) . " FROM DUAL WHERE ";
            if ($scope !== 'none') {
                $sql .= "NOT EXISTS (SELECT 1 FROM `" . $table . "` " .
                    "WHERE `url` = %s";
                $params[] = $request['sourceUrl'];
                if ($scope === 'manual') {
                    $sql .= " AND `status` = %d AND `disabled` = 0";
                    $params[] = ABJ404_STATUS_MANUAL;
                }
                $sql .= " LIMIT 1)";
                if ($ownership !== '1=1') { $sql .= ' AND ' . $ownership; }
            } else {
                $sql .= $ownership;
            }
        } else {
            $sql .= "VALUES (" . implode(', ', $insertFormats) . ")";
        }

        return new self($sql, $params, $scope);
    }

    /**
     * Execute this insert with the isolation its source-admission predicate needs.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @return array<string, mixed>
     */
    public function execute(ABJ_404_Solution_DatabaseCore $dbCore): array {
        if ($this->sourceConflictScope === 'none') {
            $result = $dbCore->queryAndGetResults($this->sql, array('query_params' => $this->params));
            // Mutable wpdb row/id metadata can survive a refused query. Only
            // its raw receipt authorizes interpreting that metadata as stored.
            if (($result['query_succeeded'] ?? false) !== true) {
                $error = isset($result['last_error']) && is_string($result['last_error'])
                    && $result['last_error'] !== '' ? $result['last_error']
                    : 'Redirect write was not confirmed by the database driver ('
                        . ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED . ').';
                throw new ABJ_404_Solution_DatabaseMutationFailedException(
                    ABJ_404_Solution_DatabaseMutationFailedException::STATEMENT_REFUSED,
                    $error, isset($result['cause']) && $result['cause'] instanceof Throwable ? $result['cause'] : null);
            }
            return $result;
        }
        return $dbCore->transactionExecutor()->executeSerializableMutation(array(
            'sql' => $this->sql, 'params' => $this->params,
            'description' => $this->sourceConflictScope === 'any'
                ? 'atomically recording source-absent redirect evidence'
                : 'atomically admitting automatic redirect without overriding a manual source',
        ));
    }

    /**
     * The id this statement stored, read from its execute() result.
     *
     * A promotion's id is the owner it was built for, but only when the
     * guarded UPDATE changed that row. Promotion always changes the status,
     * so zero affected rows means the owner left the live set (trashed,
     * deleted) or the ownership fence failed between admission and write.
     * A conditional INSERT whose absence check failed reports insert_id 0.
     *
     * @param array<string, mixed> $result
     * @return int Zero when nothing was stored.
     */
    public function storedId(array $result): int {
        if ($this->promotedId > 0) {
            return ABJ_404_Solution_ExactInteger::readOr($result['rows_affected'] ?? null, 0, 0) > 0
                ? $this->promotedId : 0;
        }
        $insertId = $result['insert_id'] ?? 0;
        return is_scalar($insertId) ? (int)$insertId : 0;
    }

    public function sql(): string {
        return $this->sql;
    }

    /** @return array<int, mixed> */
    public function params(): array {
        return $this->params;
    }
}
