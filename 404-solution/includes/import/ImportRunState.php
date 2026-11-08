<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accumulates the outcome of one redirect-import run.
 *
 * ImportService streams an uploaded CSV row by row; this object is the single
 * place that remembers how many rows were processed, how many were valid,
 * invalid or overwrote an existing redirect, which diagnostics were kept, and
 * whether the run has been told to stop. It also knows how to resume from, and
 * how to describe itself as, the checkpoint persisted by ImportProgressStore,
 * so the checkpoint keys live next to the counters they are built from.
 */
class ABJ_404_Solution_ImportRunState {

    /** @var int */
    private $processed = 0;

    /** @var int */
    private $valid = 0;

    /** @var int */
    private $invalid = 0;

    /** @var int */
    private $overwritten = 0;

    /** @var int Data rows already handled by an earlier, interrupted upload of the same file. */
    private $resumeFrom = 0;

    /** @var ABJ_404_Solution_ImportIssueSummary */
    private $issueSummary;

    /** @var string|null Why the run stopped early, or null while it may continue. */
    private $abortMessage = null;

    private function __construct() {
        $this->issueSummary = new ABJ_404_Solution_ImportIssueSummary();
    }

    /**
     * A run that starts at the first data row with nothing counted yet.
     *
     * @return self
     */
    public static function fresh(): self {
        return new self();
    }

    /**
     * A run that continues an interrupted import from its persisted checkpoint.
     *
     * @param array<string, mixed> $progress A checkpoint written by progressSnapshot().
     * @return self
     */
    public static function fromProgress(array $progress): self {
        $state = new self();
        $state->resumeFrom = ABJ_404_Solution_ImportProgressStore::progressInt($progress, 'rows_processed', 0);
        $state->processed = ABJ_404_Solution_ImportProgressStore::progressInt($progress, 'processed_count', $state->resumeFrom);
        $state->valid = ABJ_404_Solution_ImportProgressStore::progressInt($progress, 'valid_count', 0);
        $state->invalid = ABJ_404_Solution_ImportProgressStore::progressInt($progress, 'invalid_count', 0);
        $state->overwritten = ABJ_404_Solution_ImportProgressStore::progressInt($progress, 'overwritten_count', 0);
        /** @var array<int, string> $persistedIssues */
        $persistedIssues = array();
        if (isset($progress['issues']) && is_array($progress['issues'])) {
            $persistedIssues = $progress['issues'];
        }
        $persistedOmitted = max(0, ABJ_404_Solution_ImportProgressStore::progressInt(
            $progress,
            'issues_omitted',
            0
        ));
        $state->issueSummary = new ABJ_404_Solution_ImportIssueSummary(
            $persistedIssues,
            $persistedOmitted
        );
        return $state;
    }

    /** @return int Data rows to skip because an earlier upload already handled them. */
    public function resumeFrom(): int {
        return $this->resumeFrom;
    }

    /** @return int */
    public function processed(): int {
        return $this->processed;
    }

    /** @return int */
    public function valid(): int {
        return $this->valid;
    }

    /** @return int */
    public function invalid(): int {
        return $this->invalid;
    }

    /** @return int */
    public function overwritten(): int {
        return $this->overwritten;
    }

    /** @return ABJ_404_Solution_ImportIssueSummary */
    public function issueSummary(): ABJ_404_Solution_ImportIssueSummary {
        return $this->issueSummary;
    }

    /** @return string|null */
    public function abortMessage(): ?string {
        return $this->abortMessage;
    }

    /**
     * Count one more row as handled, before its outcome is known.
     *
     * @return void
     */
    public function beginRow(): void {
        $this->processed++;
    }

    /**
     * Record the diagnostics of the row that was just handled and classify it.
     *
     * A row with any diagnostic is invalid; otherwise it is valid, and it also
     * counts as an overwrite when it replaced an existing redirect.
     *
     * @param array<int, string> $issues
     * @param bool $wasOverwrite
     * @return void
     */
    public function recordRowOutcome(array $issues, bool $wasOverwrite): void {
        foreach ($issues as $issue) {
            $this->issueSummary->add($issue);
        }
        if (count($issues) > 0) {
            $this->invalid++;
            return;
        }
        $this->valid++;
        if ($wasOverwrite) {
            $this->overwritten++;
        }
    }

    /**
     * Stop the run and keep the message that is shown instead of a result.
     *
     * @param string $message
     * @return void
     */
    public function abort(string $message): void {
        $this->abortMessage = $message;
    }

    /**
     * The checkpoint stored so a re-upload of the same file can resume.
     *
     * @param int $rowsProcessed Data rows to skip on resume.
     * @param int $processedCount Rows counted as processed at that point.
     * @return array<string, mixed>
     */
    public function progressSnapshot(int $rowsProcessed, int $processedCount): array {
        return array(
            'rows_processed'    => $rowsProcessed,
            'processed_count'   => $processedCount,
            'valid_count'       => $this->valid,
            'invalid_count'     => $this->invalid,
            'overwritten_count' => $this->overwritten,
            'issues'            => $this->issueSummary->details(),
            'issues_omitted'    => $this->issueSummary->omittedCount(),
        );
    }
}
