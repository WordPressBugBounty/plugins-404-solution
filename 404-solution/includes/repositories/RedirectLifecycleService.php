<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stored-row status and trash transitions, one row or every row of the
 * selected statuses, including count/cache coherence.
 * Cycle decisions belong to RedirectWriteAdmissionPolicy; callers supply the
 * graph lock's ownership predicate and hold that lock across this workflow.
 * // allow-no-test-found: real lifecycle entry points covered by BulkActionsFromTableUITest and RestApiRedirectRefusedWriteTest
 */
final class ABJ_404_Solution_RedirectLifecycleService {
    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;
    /** @var ABJ_404_Solution_Functions */
    private $functions;
    /** @var ABJ_404_Solution_RedirectWriteAdmissionPolicy */
    private $admission;
    /** @var ABJ_404_Solution_StatusCountsMutationSync */
    private $counts;
    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCache;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var callable(): bool Whether the writer still owns its graph mutation. */
    private $isOwned;
    /** @var ABJ_404_Solution_RedirectsDenormMaintenanceService|null Memoized Step 3c maintenance service. */
    private $denormMaintenance = null;

    /** @param array{core: ABJ_404_Solution_DatabaseCore, functions: ABJ_404_Solution_Functions,
     * admission: ABJ_404_Solution_RedirectWriteAdmissionPolicy, counts: ABJ_404_Solution_StatusCountsMutationSync,
     * regexCache: ABJ_404_Solution_RedirectRegexCacheStore, logger: ABJ_404_Solution_Logging,
     * isOwned?: callable(): bool} $dependencies */
    public function __construct(array $dependencies) {
        $this->core = $dependencies['core'];
        $this->functions = $dependencies['functions'];
        $this->admission = $dependencies['admission'];
        $this->counts = $dependencies['counts'];
        $this->regexCache = $dependencies['regexCache'];
        $this->logger = $dependencies['logger'];
        $this->isOwned = $dependencies['isOwned'] ?? static function (): bool { return true; };
    }

    /** @param int|string $id */
    public function deleteRedirect($id): void {
        $cleanedID = absint(sanitize_text_field((string)$id));

        if (is_numeric($id)) {
            $before = $this->counts->snapshot('id = %d', array($cleanedID));
            // Deleting is the admin's explicit act and needs no admission, so a
            // refused read does not block it; it only loses the purge target.
            $stored = $this->readStoredRow($cleanedID);
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $result = $this->core->queryAndGetResults($query, array('query_params' => array($cleanedID)));
            $mutationError = $this->mutationError($result, 'Redirect delete did not confirm success.');
            if ($mutationError !== '') {
                // The statement may have committed before the driver returned
                // false. Purge caches, but do not apply a guessed count delta.
                ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                    'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                    'page-purge' => static function () use ($stored): void {
                        ABJ_404_Solution_RedirectPageCachePurge::queueRead($stored);
                    },
                ));
                return;
            }
            ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                'page-purge' => static function () use ($stored): void {
                    ABJ_404_Solution_RedirectPageCachePurge::queueRead($stored);
                },
                'count-sync' => function () use ($before, $cleanedID): void {
                    $this->counts->syncSince($before, 'id = %d', array($cleanedID));
                },
            ));
        }
    }

    /** Trash every live row sharing a source while the caller holds the graph lock.
     * @param array{id: int, ownership: string} $request
     */
    public function trashSource(array $request): string {
        $read = $this->readStoredRow($request['id']);
        if ($read['last_error'] !== '') { return $read['last_error']; }
        $stored = $read['rows'][0] ?? array();
        if (empty($stored['url']) || !is_string($stored['url'])) { return 'Source redirect not found.'; }
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->core, $this->functions);
        $result = $lookup->getLiveRowsForNormalizedUrl($stored['url']);
        if ($result['last_error'] !== '') { return $result['last_error']; }
        foreach ($result['rows'] as $row) {
            $rowId = ABJ_404_Solution_ExactInteger::readOr($row['id'] ?? null, 1, 0);
            if ($rowId === 0) { return 'Stored source row has an invalid ID.'; }
            $error = $this->changeTrashState(array('id' => $rowId, 'trash' => 1, 'ownership' => $request['ownership']));
            if ($error !== '') { return $error; }
        }
        return '';
    }

    /** @param array{id: int, status: string, ownership: string} $request */
    public function updateStatus(array $request): string {
        $id = absint($request['id']);
        $read = $this->readStoredRow($id);
        // A refused read proves nothing about the row. Treating it as "no row"
        // would skip source-ownership and cycle admission and still run the UPDATE.
        if ($read['last_error'] !== '') { return $read['last_error']; }
        $row = $read['rows'][0] ?? array();
        if ($row !== array()) {
            if (ABJ_404_Solution_ExactInteger::readOr($row['disabled'] ?? null, 0, 1) === 0) {
                $error = $this->sourceConflict($row, $id);
                if ($error !== '') { return $error; }
            }
            $this->admission->assertStoredRedirectTerminates(array(
                'row' => $row, 'excludedRedirectId' => $id,
                'status' => (int)$request['status'], 'disabled' => null,
            ));
        }
        $before = $this->counts->snapshot('id = %d', array($id));
        $result = $this->core->queryAndGetResults(
            'UPDATE {wp_abj404_redirects} SET status = %s WHERE id = %d AND ' . $request['ownership'],
            array('query_params' => array($request['status'], $id))
        );
        $error = $this->mutationError($result, 'Redirect status update did not confirm success.');
        if ($error !== '') {
            if (!array_key_exists('query_succeeded', $result) || $result['query_succeeded'] !== true) {
                ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                    'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                    'page-purge' => static function () use ($row, $request): void {
                        if ($row !== array()) {
                            ABJ_404_Solution_RedirectPageCachePurge::queueRows(array(
                                $row, array_merge($row, array('status' => $request['status']))
                            ));
                        }
                    },
                ));
            }
            return $error;
        }
        if (!($this->isOwned)()) {
            return 'Redirect graph lock lost: database connection changed during admission or mutation.';
        }
        ABJ_404_Solution_RedirectMutationCompletion::complete(array(
            'invalidate-caches' => function (): void { $this->invalidateCaches(); },
            'count-sync' => function () use ($before, $id): void {
                $this->counts->syncSince($before, 'id = %d', array($id));
            },
            'page-purge' => static function () use ($row, $request): void {
                if ($row !== array()) {
                    ABJ_404_Solution_RedirectPageCachePurge::queueRows(array($row, array_merge($row, array('status' => $request['status']))));
                }
            },
        ));
        return is_string($result['last_error']) ? $result['last_error'] : '';
    }

    /**
     * Complete the post-write coherence workflow for an admitted redirect edit.
     * The caller holds the outer graph lock across this workflow and any
     * restore admission.
     *
     * @param array{id: int, before: array<int, array{status:int,disabled:int,count:int}>|null,
     *   storedRow: array<string, mixed>, newSourceUrl: string, status: int|string,
     *   ownership: string} $request
     * @return string Empty on success, otherwise the restore refusal cause.
     */
    public function completeAdmittedEdit(array $request): string {
        $id = absint($request['id']);
        // Editing a trashed row restores it; report a refused restore, don't drop it.
        $restoreError = '';
        ABJ_404_Solution_RedirectMutationCompletion::complete(array(
            'invalidate-caches' => function (): void { $this->invalidateCaches(); },
            'count-sync' => function () use ($request, $id): void {
                $this->counts->syncSince($request['before'], 'id = %d', array($id));
            },
            'page-purge' => static function () use ($request): void {
                ABJ_404_Solution_RedirectPageCachePurge::queueRows(array(
                    $request['storedRow'], array('url' => $request['newSourceUrl'], 'status' => $request['status']),
                ));
            },
            'restore' => function () use (&$restoreError, $id, $request): void {
                $restoreError = $this->changeTrashState(array(
                    'id' => $id, 'trash' => 0, 'ownership' => $request['ownership'],
                ));
            },
            'denorm-recompute' => function () use ($id): void {
                // Step 3c: an edit can change the destination type/target, so recompute
                // the row's denorm display columns from the new final_dest.
                $this->recomputeDenormColumns(array($id));
            },
        ));

        return $restoreError;
    }

    /** @param array{id: int|string, trash: int|string, ownership: string, status?: int|null} $request
     *   Presence of status selects an atomic restore; null preserves the stored status.
     */
    public function changeTrashState(array $request): string {
        $id = $request['id'];
        $trash = $request['trash'];
        $error = '';
        if ($this->functions->regexMatch('[0-9]+', '' . $id)) {
            // Read on trash too: the row is the page-cache purge target either way.
            $read = $this->readStoredRow(absint($id));
            $row = $read['rows'][0] ?? array();
            if (absint($trash) === 0) {
                // Restore is an admission decision: a refused read is not "no row to check".
                // Trash is not (disabling a row cannot close a cycle), so it proceeds
                // and falls back to a whole-site purge below.
                if ($read['last_error'] !== '') { return $read['last_error']; }
                if ($row === array() && array_key_exists('status', $request)) { return 'Redirect not found for restore.'; }
                if ($row !== array()) {
                    $error = $this->sourceConflict($row, absint($id));
                    if ($error !== '') { return $error; }
                    $this->admission->assertStoredRedirectTerminates(array(
                        'row' => $row, 'excludedRedirectId' => absint($id),
                        'status' => $request['status'] ?? null, 'disabled' => 0,
                    ));
                }
            }
            $before = $this->counts->snapshot('id = %d', array(absint($id)));
            $table = $this->core->doTableNameReplacements('{wp_abj404_redirects}');
            // One statement is atomic on both transactional and legacy engines.
            // Admission above examines the final status, never an intermediate enabled row.
            $statusSql = isset($request['status']) ? ', status = %d' : '';
            $params = array(absint(esc_html((string)$trash)));
            if (isset($request['status'])) { $params[] = $request['status']; }
            $params[] = absint($id);
            $result = $this->core->queryAndGetResults(
                'UPDATE `' . $table . '` SET disabled = %d' . $statusSql . ' WHERE id = %d AND ' . $request['ownership'],
                array('query_params' => $params)
            );
            $error = $this->mutationError($result, 'Redirect trash-state update did not confirm success.');
            if ($error !== '') {
                // A false native return does not prove that the row stayed
                // unchanged. Invalidate conservatively, but do not publish a
                // count delta or report the transition as successful.
                ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                    'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                    'page-purge' => static function () use ($read): void {
                        ABJ_404_Solution_RedirectPageCachePurge::queueRead($read);
                    },
                ));
                return $error;
            }
            // A fenced write may affect no rows after a reconnect. Do not publish
            // cache/count effects against another writer's state without ownership.
            if (!($this->isOwned)()) {
                return 'Redirect graph lock lost: database connection changed during admission or mutation.';
            }
            ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                'count-sync' => function () use ($before, $id): void {
                    $this->counts->syncSince($before, 'id = %d', array(absint($id)));
                },
                'page-purge' => static function () use ($read): void {
                    ABJ_404_Solution_RedirectPageCachePurge::queueRead($read);
                },
            ));
        } else {
            $error = 'Invalid redirect ID: ' . (string)$id;
        }
        return $error;
    }

    /**
     * Trash every row of the selected statuses (the admin "purge redirects"
     * action). Disabling rows cannot close a cycle, so no graph lock is needed.
     *
     * @param array{types: array<int, int|string>, purgeType: string} $request
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function trashStatuses(array $request): array {
        $selection = (new ABJ_404_Solution_RedirectsRetentionPolicy())->purgeSelection($request);
        if ($selection['status'] === 'invalid_purge_type') {
            $this->logger->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post((string)json_encode($request['purgeType'])));
            return $selection;
        }
        if ($selection['status'] === 'no_valid_types') {
            $this->logger->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post((string)json_encode($selection['redirect_types'])));
            return $selection;
        }
        if ($selection['status'] === 'logs_only') {
            return $selection;
        }

        $redirectTypes = $selection['redirect_types'];
        $affectedRows = "status in (" . implode(',', $redirectTypes) . ")";
        $before = $this->counts->snapshot($affectedRows);
        $purgeResult = $this->core->queryAndGetResults("update {wp_abj404_redirects} set disabled = 1 where " . $affectedRows);
        $mutationError = $this->mutationError($purgeResult, 'Redirect purge did not confirm success.');
        if ($mutationError !== '') {
            ABJ_404_Solution_RedirectMutationCompletion::complete(array(
                'invalidate-caches' => function (): void { $this->invalidateCaches(); },
                'page-purge' => static function () use ($redirectTypes): void {
                    ABJ_404_Solution_RedirectPageCachePurge::queueStatuses($redirectTypes);
                },
            ));
            $selection['status'] = 'unknown';
            $selection['rows_affected'] = 0;
            $this->logger->warn($mutationError);
            return $selection;
        }
        $rowsAffectedRaw = $purgeResult['rows_affected'] ?? 0;

        ABJ_404_Solution_RedirectMutationCompletion::complete(array(
            'invalidate-caches' => function (): void { $this->invalidateCaches(); },
            'count-sync' => function () use ($before, $affectedRows): void {
                $this->counts->syncSince($before, $affectedRows);
            },
            'page-purge' => static function () use ($redirectTypes): void {
                ABJ_404_Solution_RedirectPageCachePurge::queueStatuses($redirectTypes);
            },
        ));

        $selection['status'] = 'redirects_purged';
        $selection['rows_affected'] = is_scalar($rowsAffectedRaw) ? (int)$rowsAffectedRaw : 0;
        return $selection;
    }

    /**
     * The stored row of one id with the read's error kept: empty `rows` plus an
     * empty `last_error` is a row that does not exist, empty `rows` plus an
     * error is a row nobody has seen.
     *
     * @return array{rows: array<int, array<string, mixed>>, last_error: string}
     */
    private function readStoredRow(int $id): array {
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->core, $this->functions);
        return $lookup->readRedirectsByIDs(array($id));
    }

    /** Fail closed when another row owns the source or the read failed.
     * @param array<string, mixed> $row
     */
    private function sourceConflict(array $row, int $id): string {
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->core, $this->functions);
        $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
        $other = $lookup->getExistingRedirectForNormalizedUrl($url, $id);
        if (isset($other['last_error']) && is_string($other['last_error']) && $other['last_error'] !== '') { return $other['last_error']; }
        return ABJ_404_Solution_ExactInteger::readOr($other['id'] ?? null, 1, 0) > 0
            ? ABJ_404_Solution_RedirectWriteAdmissionPolicy::sourceConflictMessage($url) : '';
    }

    /**
     * Recompute the dest_for_view / published_status denorm columns for the
     * given redirect ids via the Step 3c maintenance service, built from this
     * service's own injected core + logger. The maintenance write degrades
     * gracefully on a schema-drifted / read-only host, so no extra guard is
     * needed here.
     *
     * @param array<int, int> $ids
     * @return void
     */
    public function recomputeDenormColumns(array $ids): void {
        if ($this->denormMaintenance === null) {
            $this->denormMaintenance = new ABJ_404_Solution_RedirectsDenormMaintenanceService(
                $this->core,
                $this->logger
            );
        }
        $this->denormMaintenance->recomputeByRedirectIds($ids);
    }

    /** Invalidate shared redirect mutation caches after a row changes. */
    public function invalidateCaches(): void {
        ABJ_404_Solution_RedirectMutationCompletion::complete(array(
            'status-count-caches' => static function (): void {
                abj_service('view_read_service')->invalidateStatusCountsCache();
            },
            'regex-cache' => function (): void { $this->regexCache->clear(); },
        ));
    }

    /**
     * Return the native error, or reject any mutation without an explicit
     * successful driver result. Empty last_error is not an acknowledgement.
     *
     * @param array<string, mixed> $result
     * @param string $fallback
     * @return string Empty only for a confirmed successful mutation.
     */
    private function mutationError(array $result, string $fallback): string {
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? trim((string)$result['last_error']) : '';
        if ($lastError !== '') {
            return $lastError;
        }
        return array_key_exists('query_succeeded', $result) && $result['query_succeeded'] === true
                && (!array_key_exists('timed_out', $result) || $result['timed_out'] === false)
            ? '' : $fallback;
    }
}
