<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectUpdateStatement.php';
require_once __DIR__ . '/RedirectLifecycleService.php';

/**
 * Admits and persists one edit of an existing redirect row.
 *
 * The edit counterpart to the insert path in RedirectWriteService: it reads
 * the stored row, canonicalizes the edited source, asks the admission policy
 * whether the result may be written (regex grammar, cycles, source
 * ownership), runs the ownership-guarded UPDATE, and hands the tail of the
 * workflow (cache invalidation, count sync, page-cache purge, denorm
 * recompute) to RedirectLifecycleService::completeAdmittedEdit().
 *
 * The caller holds the redirect graph lock across the whole call and
 * supplies the lock's ownership predicate and still-owned check; this class
 * never takes or releases the lock itself.
 */
final class ABJ_404_Solution_RedirectEditWriter {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_RedirectWriteAdmissionPolicy */
    private $admission;

    /** @var ABJ_404_Solution_StatusCountsMutationSync */
    private $counts;

    /** @var ABJ_404_Solution_RedirectLifecycleService */
    private $lifecycle;

    /** @var callable(): ABJ_404_Solution_PluginLogicUrlNormalization Resolved only when an edit reaches canonicalization. */
    private $urlNormalization;

    /** @var callable(): bool Whether the live redirects table has the query_mode column. */
    private $hasQueryModeColumn;

    /** @var callable(): string SQL predicate proving the caller still owns the graph lock. */
    private $ownershipPredicate;

    /** @var callable(): bool Whether the caller still owns its graph mutation. */
    private $isOwned;

    /** @var callable(string): string Records a refused write on the caller and returns the message. */
    private $recordFailure;

    /**
     * @param array{core: ABJ_404_Solution_DatabaseCore, functions: ABJ_404_Solution_Functions,
     *   admission: ABJ_404_Solution_RedirectWriteAdmissionPolicy,
     *   counts: ABJ_404_Solution_StatusCountsMutationSync,
     *   lifecycle: ABJ_404_Solution_RedirectLifecycleService,
     *   urlNormalization: callable(): ABJ_404_Solution_PluginLogicUrlNormalization,
     *   hasQueryModeColumn: callable(): bool, ownershipPredicate: callable(): string,
     *   isOwned: callable(): bool, recordFailure: callable(string): string} $dependencies
     */
    public function __construct(array $dependencies) {
        $this->core = $dependencies['core'];
        $this->functions = $dependencies['functions'];
        $this->admission = $dependencies['admission'];
        $this->counts = $dependencies['counts'];
        $this->lifecycle = $dependencies['lifecycle'];
        $this->urlNormalization = $dependencies['urlNormalization'];
        $this->hasQueryModeColumn = $dependencies['hasQueryModeColumn'];
        $this->ownershipPredicate = $dependencies['ownershipPredicate'];
        $this->isOwned = $dependencies['isOwned'];
        $this->recordFailure = $dependencies['recordFailure'];
    }

    /**
     * Admit and persist the edit while the caller holds the graph lock.
     *
     * @param ABJ_404_Solution_RedirectUpdate $update
     * @return string Empty on success, otherwise the refusal cause.
     */
    public function write(ABJ_404_Solution_RedirectUpdate $update): string {
        $idForUpdate = $update->getId();

        $statusType = $update->getStatusType();
        // Keep the raw regex grammar contract at the public write boundary;
        // normalization may remove control bytes before canonical validation.
        if (!$this->admission->regexSourceIsAdmissibleForStatus($statusType, $update->getFromUrl())) {
            return 'invalid_regex_source';
        }
        // The stored row is also the page-cache purge target: its old source
        // stops answering once the update lands. A refused read is not "no
        // row": the query mode and purge target are unknown, so refuse.
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->core, $this->functions);
        $storedRead = $lookup->readRedirectsByIDs(array($idForUpdate));
        if ($storedRead['last_error'] !== '') { return $storedRead['last_error']; }
        $storedRow = $storedRead['rows'][0] ?? array();
        $prepared = $this->prepareCanonicalUpdateSource($update, $storedRow);
        if ($prepared['error'] !== '') { return $prepared['error']; }
        if (!$this->admission->regexSourceIsAdmissibleForStatus($statusType, $prepared['sourceURL'])) {
            return 'invalid_regex_source';
        }

        try {
            $this->admission->assertRedirectTerminates(array(
                'status' => (int)$statusType,
                'type' => (int)$update->getType(),
                'source' => $prepared['sourceURL'],
                'destination' => $update->getDestination(),
                'disabled' => 0,
                'code' => (int)$update->getCode(),
                'excludedRedirectId' => $idForUpdate,
                'queryMode' => $prepared['queryMode'],
            ));
        } catch (ABJ_404_Solution_RedirectCycleException $e) {
            return ($this->recordFailure)($e->getMessage());
        }

        $redirectsTable = $this->core->doTableNameReplacements("{wp_abj404_redirects}");
        $before = $this->counts->snapshot('id = %d', array(absint($idForUpdate)));

        $other = $lookup->getExistingRedirectForNormalizedUrl($prepared['sourceURL'], $idForUpdate);
        if (isset($other['last_error']) && is_string($other['last_error']) && $other['last_error'] !== '') { return $other['last_error']; }
        if (ABJ_404_Solution_ExactInteger::readOr($other['id'] ?? null, 1, 0) > 0) {
            return ABJ_404_Solution_RedirectWriteAdmissionPolicy::sourceConflictMessage($prepared['sourceURL']);
        }
        $statement = ABJ_404_Solution_RedirectUpdateStatement::fromUpdate(
            $update, array(
                'sourceURL' => $prepared['sourceURL'],
                'table' => $redirectsTable,
                'changes' => $prepared['changes'],
            )
        );
        $updateResult = $this->core->queryAndGetResults($statement->sql() . ' AND ' . ($this->ownershipPredicate)(), array('query_params' => $statement->params()));
        $updateError = isset($updateResult['last_error']) && is_string($updateResult['last_error']) ? $updateResult['last_error'] : '';
        if ($updateError !== '') {
            // queryAndGetResults() already logged this (centralized DAO error
            // handler) -- reject before any downstream cache invalidation /
            // denorm recompute runs against a row that may not have changed.
            return 'db_update_failed';
        }
        if (!array_key_exists('query_succeeded', $updateResult) || $updateResult['query_succeeded'] !== true
                || (array_key_exists('timed_out', $updateResult) && $updateResult['timed_out'] !== false)) {
            // A native false return can follow a committed write. Invalidate
            // conservatively, but do not acknowledge the edit or run count /
            // denormalization work from an unconfirmed mutation.
            $this->lifecycle->invalidateCaches();
            ABJ_404_Solution_RedirectPageCachePurge::queueRows(array($storedRow));
            return 'db_update_failed';
        }
        if (!($this->isOwned)()) {
            return 'Redirect graph lock lost: database connection changed during admission or mutation.';
        }

        return $this->lifecycle->completeAdmittedEdit(array(
            'id' => absint($idForUpdate),
            'before' => $before,
            'storedRow' => $storedRow,
            'newSourceUrl' => $prepared['sourceURL'],
            'status' => $statusType,
            'ownership' => ($this->ownershipPredicate)(),
        ));
    }

    /**
     * Prepare the one source identity and query policy an edit will use for
     * cycle admission, source ownership, and the eventual UPDATE statement.
     *
     * @param ABJ_404_Solution_RedirectUpdate $update
     * @param array<string, mixed> $storedRow
     * @return array{sourceURL: string, changes: array<string, string|null>, queryMode: string|null, error: string}
     */
    private function prepareCanonicalUpdateSource(ABJ_404_Solution_RedirectUpdate $update, array $storedRow): array {
        $queryModeChanges = $update->getQueryModeChange();
        $existingRow = $queryModeChanges === array() ? $storedRow : array();
        $sourceURL = ($this->urlNormalization)()->normalizeRedirectSourceForStatus(
            $update->getFromUrl(),
            $update->getStatusType()
        );
        $queryPolicy = $this->admission->queryPolicyForUpdate(array(
            'update' => $update,
            'sourceURL' => $sourceURL,
            'existingRow' => $existingRow,
            'hasQueryModeColumn' => $queryModeChanges === array() || ($this->hasQueryModeColumn)(),
        ));
        return array(
            'sourceURL' => $queryPolicy['sourceURL'],
            'changes' => $queryPolicy['changes'],
            'queryMode' => $queryModeChanges === array()
                ? ABJ_404_Solution_QueryStringHelper::storedQueryMode($existingRow)
                : ($queryModeChanges['query_mode'] ?? null),
            'error' => $queryPolicy['error'],
        );
    }
}
