<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Budgeted admin reads with an index-ordered, bounded candidate-page fallback.
 * This request-scoped repository retains the original failure independently of
 * later successful queries. It never writes redirects or runs schema repair.
 * @phpstan-import-type SqlFragment from ABJ_404_Solution_DatabaseQueryBuilderInterface
 */
final class ABJ_404_Solution_AdminTableReadRepository {
    public const CANDIDATE_LIMIT = 1000;
    public const PAGE_LIMIT = 500;
    /** @var callable(): ABJ_404_Solution_DatabaseCore */
    private $databaseProvider;
    private ?ABJ_404_Solution_DatabaseCore $dbCore = null;
    /** @var array<string, mixed> */
    private array $degradation = array();
    /** @var array<string, int> Exact counts reused by the table and pagination in this request. */
    private array $counts = array();
    /** @var array<string,true> Sort capabilities unavailable in this request. */
    private array $pendingSorts = array();

    /** @param callable(): ABJ_404_Solution_DatabaseCore $databaseProvider */
    public function __construct(callable $databaseProvider) {
        $this->databaseProvider = $databaseProvider;
    }

    /** State inspection and reset must not initialize the database graph. */
    private function database(): ABJ_404_Solution_DatabaseCore {
        if ($this->dbCore === null) { $this->dbCore = ($this->databaseProvider)(); }
        return $this->dbCore;
    }

    /** Start an authorized request; degradation never changes a saved preference. */
    public function begin(): void {
        $this->degradation = array();
        $this->counts = array();
        $this->pendingSorts = array();
    }

    public function isDegraded(): bool { return $this->degradation !== array(); }

    /** @param array<int,string> $columns Readiness measured by the owning query repository. */
    public function setPendingSortColumns(array $columns): void {
        $this->pendingSorts = array_fill_keys($columns, true);
    }

    public function sortIsPending(string $column): bool { return isset($this->pendingSorts[$column]); }

    public function hasPendingSorts(): bool { return $this->pendingSorts !== array(); }

    /** @return array<string, mixed> Versioned, additive admin-only response metadata. */
    public function metadata(): array { return $this->degradation; }

    /**
     * @param array{statement: SqlFragment, fallback: SqlFragment, subpage: string,
     *     tableOptions: array<string, mixed>, sortPending?: bool} $request
     * @return array<int, array<string, mixed>>
     */
    public function read(array $request): array {
        $statement = $request['statement'];
        $timeout = ABJ_404_Solution_ExactInteger::readOr(
            $request['tableOptions']['_abj404_query_timeout'] ?? null, 1, 20);
        if (!$this->isDegraded()) {
            $plan = $this->explain($statement);
            if (empty($request['sortPending']) && ($this->indexOrdered($plan) || $this->withinCandidateWindow($plan))) {
                $result = $this->execute($statement, $timeout);
                if (!$this->failed($result)) { return $this->rows($result); }
                $failure = $this->failure($request, $result, $plan);
                if (empty($result['timed_out'])) { throw $failure; }
                $reason = 'query_timeout';
            } else {
                $result = array('last_error' => 'Requested admin table read has no index-ordered plan.',
                    'timed_out' => !empty($plan['timed_out']), 'elapsed_time' => $plan['elapsed_time'] ?? null);
                $failure = $this->failure($request, $result, $plan);
                $reason = 'query_plan';
            }
            $this->recordDegradation($request['subpage'], $failure, $reason);
        }
        $result = $this->execute($request['fallback'], 1);
        if ($this->failed($result)) {
            $diagnostics = $this->degradation['diagnostics'];
            $diagnostics = is_array($diagnostics) ? $diagnostics : array();
            $diagnostics['fallback_failure'] = array('last_error' => $result['last_error'] ?? '',
                'timed_out' => !empty($result['timed_out']), 'elapsed_time' => $result['elapsed_time'] ?? null);
            $this->degradation['diagnostics'] = $diagnostics;
            throw new ABJ_404_Solution_ViewQueryFailureException(
                (new ABJ_404_Solution_ViewDiagnostics($this->database()))->formatViewQueryFailureMessage(
                    'Bounded admin table read', $request['fallback']['sql'], $result), $diagnostics);
        }
        $rows = $this->rows($result);
        if ($request['subpage'] === 'abj404_logs' && empty($request['tableOptions']['logsid'])) {
            // The unfiltered fallback is id-DESC. Its first row is the same
            // MAX(id) used by the existing counter cache, without another query.
            $this->degradation['log_count_max_id'] = $rows[0]['log_id'] ?? 0;
        }
        return $rows;
    }

    /**
     * An aggregate shares the row read's state: after a timeout, only existing
     * counters may answer pagination. No replacement COUNT is attempted.
     * @param array{statement: SqlFragment, subpage: string,
     *     tableOptions: array<string, mixed>, operation: string} $request
     */
    public function count(array $request): int {
        if ($this->isDegraded()) { return $this->cachedCount($request['subpage'], $request['tableOptions']); }
        $key = hash('sha256', serialize($request['statement']));
        if (array_key_exists($key, $this->counts)) { return $this->counts[$key]; }
        $timeout = ABJ_404_Solution_ExactInteger::readOr(
            $request['tableOptions']['_abj404_query_timeout'] ?? null, 1, 20);
        $result = $this->execute($request['statement'], $timeout);
        if ($this->failed($result)) {
            $plan = $this->explain($request['statement']);
            $failure = $this->failure($request, $result, $plan);
            if (empty($result['timed_out'])) { throw $failure; }
            $this->recordDegradation($request['subpage'], $failure, 'query_timeout');
            return $this->cachedCount($request['subpage'], $request['tableOptions']);
        }
        $rows = $this->rows($result);
        $value = isset($rows[0]) && $rows[0] !== array() ? reset($rows[0]) : null;
        $count = ABJ_404_Solution_ExactInteger::read($value, 0);
        if ($count === null) {
            throw new ABJ_404_Solution_ViewQueryFailureException(
                $request['operation'] . ' returned no nonnegative integer aggregate.', $result);
        }
        $this->counts[$key] = $count;
        return $count;
    }

    private function recordDegradation(string $subpage, ABJ_404_Solution_ViewQueryFailureException $failure,
        string $reason): void {
        $this->degradation = array('v' => 1, 'state' => 'degraded', 'page' => 1,
            'subpage' => $subpage, 'reason' => $reason,
            'sort_readiness' => ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_BACKFILL_PENDING,
            'candidate_limit' => self::CANDIDATE_LIMIT, 'scope' => 'bounded_matching_rows',
            'diagnostics' => $failure->getDiagnostics());
    }

    /**
     * @param SqlFragment $statement
     * @return array<string, mixed>
     */
    private function execute(array $statement, int $timeout): array {
        return $this->database()->queryAndGetResults($statement['sql'], array(
            'query_params' => $statement['params'], 'timeout' => $timeout,
            'skip_repair' => true,
        ));
    }

    /** @param array<string, mixed> $result */
    private function failed(array $result): bool {
        return !empty($result['timed_out']) || !empty($result['last_error'])
            || ($result['query_succeeded'] ?? true) === false || !is_array($result['rows'] ?? null);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $result): array {
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $records = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ABJ_404_Solution_ViewQueryFailureException('Admin query returned a malformed row.', $result);
            }
            $record = array();
            foreach ($row as $column => $value) {
                if (!is_string($column)) {
                    throw new ABJ_404_Solution_ViewQueryFailureException('Admin query returned an unnamed column.', $result);
                }
                $record[$column] = $value;
            }
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Request the tabular contract parsed below instead of inheriting a MySQL
     * session's TREE/JSON default. MariaDB's bare EXPLAIN is already tabular.
     * @param SqlFragment $statement
     * @return array<string,mixed>
     */
    private function explain(array $statement): array {
        $prefix = $this->database()->queryTimeoutManager()->isMariaDB()
            ? 'EXPLAIN ' : 'EXPLAIN FORMAT=TRADITIONAL ';
        return $this->execute(array('sql' => $prefix . $statement['sql'], 'params' => $statement['params']), 1);
    }

    /** @param array<string, mixed> $plan */
    private function indexOrdered(array $plan): bool {
        if ($this->failed($plan) || $this->rows($plan) === array()) { return false; }
        foreach ($this->rows($plan) as $raw) {
            $row = array_change_key_case($raw, CASE_LOWER);
            $extra = is_string($row['extra'] ?? null) ? $row['extra'] : '';
            if (preg_match('/impossible|optimized away|no matching/i', $extra)) { continue; }
            $accessType = is_string($row['type'] ?? null) ? strtolower($row['type']) : 'all';
            if (empty($row['key']) || $accessType === 'all'
                || preg_match('/filesort|temporary/i', $extra)) { return false; }
        }
        return true;
    }

    /**
     * Whether the optimizer expects to touch no more rows than the fallback's own
     * candidate window. A full scan with a filesort over that few rows is cheaper
     * than the fallback and exact, so it needs no index: a tiny table's genuine
     * plan is exactly this, because the optimizer ignores indexes for it. The
     * estimates of a multi-step plan multiply (a join or dependent subquery
     * repeats its inner step per outer row), and any estimate that is missing or
     * not a whole number leaves the plan unproven, never assumed small.
     * @param array<string, mixed> $plan
     */
    private function withinCandidateWindow(array $plan): bool {
        if ($this->failed($plan)) { return false; }
        $estimate = 1;
        $examined = false;
        foreach ($this->rows($plan) as $raw) {
            $row = array_change_key_case($raw, CASE_LOWER);
            $extra = is_string($row['extra'] ?? null) ? $row['extra'] : '';
            if (preg_match('/impossible|optimized away|no matching/i', $extra)) { continue; }
            $rows = ABJ_404_Solution_ExactInteger::read($row['rows'] ?? null, 0);
            if ($rows === null) { return false; }
            $estimate *= max(1, $rows);
            if ($estimate > self::CANDIDATE_LIMIT) { return false; }
            $examined = true;
        }
        return $examined;
    }

    /**
     * @param array{statement: SqlFragment, subpage: string, tableOptions: array<string, mixed>, operation?: string} $request
     * @param array<string, mixed> $result
     * @param array<string, mixed> $plan
     */
    private function failure(array $request, array $result, array $plan): ABJ_404_Solution_ViewQueryFailureException {
        $result['explain'] = $this->failed($plan)
            ? array('error' => $plan['last_error'] ?? 'EXPLAIN unavailable') : $this->rows($plan);
        $diagnostics = (new ABJ_404_Solution_ViewDiagnostics($this->database()))->captureViewQueryFailureDiagnostics(
            $request['subpage'], $request['statement']['sql'],
            array_replace($request['tableOptions'], array('_abj404_diagnostics_budget' => 2)), $result);
        if (isset($request['operation'])) {
            $diagnostics['operation'] = $request['operation'];
            $diagnostics['failed_query_label'] = $request['operation'];
        }
        return new ABJ_404_Solution_ViewQueryFailureException(
            (new ABJ_404_Solution_ViewDiagnostics($this->database()))->formatViewQueryFailureMessage(
                'Admin table query', $request['statement']['sql'], $result), $diagnostics);
    }

    /**
     * Read only existing status counters, never a replacement aggregate scan.
     * Search and score totals have no cached equivalent and remain unknown.
     * @param array<string, mixed> $options
     */
    public function cachedCount(string $subpage, array $options): int {
        if ($subpage === 'abj404_logs') { return $this->cachedLogsCount($options); }
        if (!empty($options['filterText']) || ($options['score_range'] ?? 'all') !== 'all') { return -1; }
        $scope = $subpage === 'abj404_redirects' ? 'redirects' : 'captured';
        $key = $scope === 'redirects' ? ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS
            : ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
        $counts = get_transient($key);
        if (!is_array($counts)) { $counts = get_transient($key . '_last_known'); }
        if (!is_array($counts)) { return -1; }
        $policy = new ABJ_404_Solution_ViewQueryPolicy();
        if ($policy->resolveTrashValue($options) === 1) {
            return ABJ_404_Solution_ExactInteger::readOr($counts['trash'] ?? null, 0, -1);
        }
        $buckets = ABJ_404_Solution_StatusCountBuckets::bucketsByStatus($scope);
        $total = 0;
        foreach (explode(',', $policy->resolveStatusTypeList($subpage, $options)) as $status) {
            $bucket = $buckets[(int)trim($status)] ?? '';
            $count = ABJ_404_Solution_ExactInteger::read($counts[$bucket] ?? null, 0);
            if ($count === null) { return -1; }
            $total += $count;
        }
        return $total;
    }

    /** @param array<string, mixed> $options */
    private function cachedLogsCount(array $options): int {
        if (!empty($options['logsid'])) { return -1; }
        $maxId = ABJ_404_Solution_ExactInteger::read($this->degradation['log_count_max_id'] ?? null, 0);
        if ($maxId === null) { return -1; }
        $key = 'abj404_logs_count_v1_' . max(1, get_current_blog_id()) . '_' . $maxId;
        return ABJ_404_Solution_ExactInteger::readOr(get_transient($key), 0, -1);
    }
}
