<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything one request left behind in a diagnostic journal, and what those
 * records say about how it ended.
 *
 * The journals are append-only streams of independent records, but the unit a
 * developer reads -- and the unit a bounded support payload has to keep whole
 * or drop whole -- is the request. This is that unit: which lines belong to a
 * request, what they cost in bytes, and the three facts that decide whether
 * the request is worth budget (did it reach a terminal record, did it record a
 * failure, and which attempt was it retrying).
 *
 * It holds no ordering and no budget policy; that is
 * ABJ_404_Solution_DiagnosticEvidencePriority's. It reads no files; that is
 * ABJ_404_Solution_DiagnosticJournalExcerpt's.
 */
final class ABJ_404_Solution_DiagnosticRequestGroup {

    /** @var string */
    private $requestId;

    /** @var bool Whether these records carry a usable request id at all. */
    private $joinable;

    /** @var array<int, int> Line indexes into the caller's stream, in stream order. */
    private $indexes = array();

    /** @var int Bytes these lines cost, newlines included. */
    private $bytes = 0;

    /** @var bool */
    private $terminal = false;

    /** @var bool */
    private $failure = false;

    /** @var int Records folded in that are not a checkpoint intent. */
    private $nonIntentRecords = 0;

    /** @var array<string, int> Unclosed steps by key, valued by line index. */
    private $open = array();

    /** @var int Last line index added, or -1 when the group holds no lines. */
    private $lastIndex = -1;

    /** @var array<string, bool> Request ids this one recorded as its retry parent. */
    private $parents = array();

    public function __construct(string $requestId, bool $joinable) {
        $this->requestId = $requestId;
        $this->joinable = $joinable;
    }

    public function requestId(): string {
        return $this->requestId;
    }

    /** @return array<int, int> */
    public function indexes(): array {
        return $this->indexes;
    }

    public function bytes(): int {
        return $this->bytes;
    }

    public function recordCount(): int {
        return count($this->indexes);
    }

    public function hasRecords(): bool {
        return $this->indexes !== array();
    }

    /** @return array<int, string> */
    public function parentIds(): array {
        return array_keys($this->parents);
    }

    /**
     * Whether this request is one the investigation is about.
     *
     * A condemned request (a failure finding names it) counts, and so does a
     * suspected hang (no terminal record plus an unclosed step or at least
     * two records). A single post-hoc record with no terminal event is
     * neither: an uninstrumented request that wrote once and left, not a
     * stall. Unjoinable lines are never failing -- they belong to no request,
     * so they cannot be one that failed.
     */
    public function isFailing(): bool {
        return $this->isCondemned() || $this->isSuspectedHang();
    }

    /**
     * Whether a failure finding names this request.
     *
     * Failure arrives as a failure-branch event, a non-complete status, a
     * failed selftest, a client verdict, or a cross-journal verdict. Terminal
     * state is irrelevant: a request that failed and then finished is still
     * the request the investigation is about.
     */
    public function isCondemned(): bool {
        return $this->joinable && $this->failure;
    }

    /**
     * Whether this request looks stalled mid-flight.
     *
     * No terminal record alone is not enough: a lone post-hoc size record has
     * none either. A hang needs an unclosed start/end step or at least two
     * records, proving the request did work and then stopped.
     */
    public function isSuspectedHang(): bool {
        return $this->joinable && !$this->failure && !$this->terminal
            && ($this->open !== array() || $this->nonIntentRecords >= 2);
    }

    /**
     * The line indexes that must survive even a tiny budget share.
     *
     * The last unclosed step (where the request stopped) and the last record
     * (the newest fact about it). Unique, non-negative, oldest first.
     *
     * @return array<int, int>
     */
    public function anchorIndexes(): array {
        $anchors = array();
        if ($this->open !== array()) {
            $anchors[] = max($this->open);
        }
        $anchors[] = $this->lastIndex;
        $anchors = array_unique($anchors);
        $kept = array();
        foreach ($anchors as $anchor) {
            if ($anchor >= 0) {
                $kept[] = $anchor;
            }
        }
        sort($kept);
        return $kept;
    }

    /**
     * Whether this request's record run must survive INTACT rather than
     * being trimmed to its two ends.
     *
     * A completed request's head and tail really are its two most
     * informative records, because a "how it ended" record exists at the
     * tail. A request with no terminal event never wrote one: every record
     * it still holds, including the middle, is the only account of what it
     * was doing while it stalled (report 193: the 165-second holder had no
     * request_end, and trimming it to head+tail dropped exactly the seven
     * records that showed it was stuck).
     */
    public function isMaximallyDecisive(): bool {
        return $this->joinable && !$this->terminal;
    }

    public function addLine(int $index, int $bytes): void {
        $this->indexes[] = $index;
        $this->bytes += $bytes;
        $this->lastIndex = $index;
    }

    /**
     * Fold one of this request's own records into the classification.
     *
     * Besides the terminal and failure facts, every record updates the
     * hang shape: checkpoint intents open until their own record closes them,
     * every other record counts against the lone-post-hoc floor, and
     * start/end pairs open and close by name.
     *
     * @param array<array-key, mixed> $record
     */
    public function applyRecord(array $record): void {
        $event = isset($record['event']) && is_scalar($record['event']) ? (string)$record['event'] : '';
        if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::TERMINAL_EVENTS, true)) {
            $this->terminal = true;
        }
        if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::FAILURE_EVENTS, true)) {
            $this->markFailed();
        }
        // Any status other than 'complete' -- 'error', a truncated status
        // string, anything a future boundary invents -- is treated as a
        // failure. An allowlist rather than a deny-list, so a new failure
        // status cannot be silently filed as healthy.
        $status = isset($record['status']) && is_scalar($record['status']) ? (string)$record['status'] : '';
        if ($status !== '' && $status !== 'complete') {
            $this->markFailed();
        }
        if ($event === 'selftest' && array_key_exists('ok', $record) && $record['ok'] === false) {
            $this->markFailed();
        }
        if (isset($record['retry_parent_id']) && is_scalar($record['retry_parent_id'])
                && (string)$record['retry_parent_id'] !== '') {
            $this->parents[(string)$record['retry_parent_id']] = true;
        }
        $this->trackHangShape($event, $record);
    }

    /**
     * Fold one record into the hang shape: intent open/close, the
     * lone-post-hoc count, and start/end pairs by name.
     *
     * @param array<array-key, mixed> $record
     */
    private function trackHangShape(string $event, array $record): void {
        $currentIndex = $this->indexes === array()
            ? -1 : $this->indexes[count($this->indexes) - 1];
        $checkpointId = isset($record['checkpoint_id']) && is_scalar($record['checkpoint_id'])
            ? (string)$record['checkpoint_id'] : '';
        if ($event === 'checkpoint_intent') {
            $this->open['intent:' . $checkpointId] = $currentIndex;
        } else {
            $this->nonIntentRecords++;
            unset($this->open['intent:' . $checkpointId]);
        }
        if (substr($event, -6) === '_start') {
            if ($event === 'stage_start') {
                $stage = isset($record['stage']) && is_scalar($record['stage'])
                    ? (string)$record['stage'] : '';
                $this->open['stage:' . $stage] = $currentIndex;
            } else {
                $this->open[substr($event, 0, -6)] = $currentIndex;
            }
        } elseif (substr($event, -4) === '_end') {
            if ($event === 'stage_end') {
                $stage = isset($record['stage']) && is_scalar($record['stage'])
                    ? (string)$record['stage'] : '';
                unset($this->open['stage:' . $stage]);
            } else {
                unset($this->open[substr($event, 0, -4)]);
            }
        }
    }

    public function markFailed(): void {
        $this->failure = true;
    }
}
