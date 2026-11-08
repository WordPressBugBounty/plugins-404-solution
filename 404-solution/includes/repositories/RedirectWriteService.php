<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../redirects/RedirectCanonicalUrl.php';
require_once __DIR__ . '/../redirects/RedirectChainTraversal.php';
require_once __DIR__ . '/RedirectsLiveColumnSet.php';
require_once __DIR__ . '/RedirectInsertStatement.php';
require_once __DIR__ . '/RedirectUpdateStatement.php';
require_once __DIR__ . '/RedirectGraphLock.php';
require_once __DIR__ . '/RedirectLifecycleService.php';
require_once __DIR__ . '/RedirectEditWriter.php';

/**
 * Application service for redirect row mutations.
 *
 * This keeps write validation, status-count invalidation, and regex-cache
 * invalidation out of the frontend redirect lookup repository.
 */
class ABJ_404_Solution_RedirectWriteService {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCacheStore;

    /** @var ABJ_404_Solution_RedirectChainTraversal|null */
    private $chainWalker;

    /** @var string Request-local error for the int-returning insert API. */
    private $lastWriteError = '';

    /** @var ''|'source-owned' Structured admission refusal, separate from database errors. */
    private $lastWriteRefusalReason = '';

    /** @var ABJ_404_Solution_RedirectGraphLock|null Held across admission and persistence. */
    private $graphLock = null;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization|null */
    private $urlNormalization;

    /** @var ABJ_404_Solution_RedirectLifecycleService|null Memoized lifecycle service. */
    private $lifecycle = null;

    /** @var ABJ_404_Solution_RedirectEditWriter|null Memoized admitted-edit persister. */
    private $editWriter = null;

    /** @var ABJ_404_Solution_StatusCountsMutationSync|null Memoized cached-count delta writer. */
    private $countsSync = null;

    /** @var ABJ_404_Solution_RedirectWriteAdmissionPolicy|null Memoized write-admission rules. */
    private $admissionPolicy = null;

    /**
     * Memoized view of the columns the live redirects table actually has, so
     * an install whose upgrade never added engine/score/canonical_url loses
     * those values instead of the whole row.
     *
     * @var ABJ_404_Solution_RedirectsLiveColumnSet|null
     */
    private $liveColumns = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param array{functions?: ABJ_404_Solution_Functions, logging?: ABJ_404_Solution_Logging,
     *   regexCacheStore?: ABJ_404_Solution_RedirectRegexCacheStore,
     *   urlNormalization?: ABJ_404_Solution_PluginLogicUrlNormalization|null,
     *   chainWalker?: ABJ_404_Solution_RedirectChainTraversal|null} $dependencies Omitted or null keys resolve lazily.
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, array $dependencies = array()) {
        $this->dbCore = $dbCore;
        $this->f = $dependencies['functions'] ?? abj_service('functions');
        $this->logger = $dependencies['logging'] ?? abj_service('logging');
        $this->regexCacheStore = $dependencies['regexCacheStore'] ?? new ABJ_404_Solution_RedirectRegexCacheStore();
        $this->urlNormalization = $dependencies['urlNormalization'] ?? null;
        $this->chainWalker = $dependencies['chainWalker'] ?? null;
    }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    private function urlNormalization() {
        if ($this->urlNormalization !== null) {
            return $this->urlNormalization;
        }
        return abj_service('plugin_logic')->urlNormalization();
    }

    /** @param int|string $id */
    public function deleteRedirect($id): void {
        $this->lifecycleService()->deleteRedirect($id);
    }

    public function setupRedirect(ABJ_404_Solution_RedirectSpec $spec): int {
        $this->lastWriteError = '';
        $this->lastWriteRefusalReason = '';
        $status = ABJ_404_Solution_ExactInteger::readOr($spec->getStatus(), PHP_INT_MIN, -1);
        return $this->setupRedirectWithPolicy($spec, array(
            'source_conflict_scope' => $status === ABJ404_STATUS_AUTO ? 'manual' : 'none',
        ));
    }

    public function setupRedirectIfSourceAbsent(ABJ_404_Solution_RedirectSpec $spec): int {
        $this->lastWriteError = '';
        $this->lastWriteRefusalReason = '';
        return $this->setupRedirectWithPolicy($spec, array('source_conflict_scope' => 'any'));
    }

    public function getLastWriteError(): string {
        return $this->lastWriteError;
    }

    /** @return ''|'source-owned' Lets durable delivery distinguish supersession from storage failure. */
    public function getLastWriteRefusalReason(): string {
        return $this->lastWriteRefusalReason;
    }

    /**
     * @param ABJ_404_Solution_RedirectSpec $spec
     * @param array{source_conflict_scope: 'any'|'manual'|'none'} $policy
     * @return int
     */
    private function setupRedirectWithPolicy(ABJ_404_Solution_RedirectSpec $spec, array $policy): int {
        // All writers share source admission, including capture and automatic
        // redirects. Otherwise a capture can race a manual promotion.
        return $this->serializeGraphMutation(function () use ($spec, $policy): int {
            return $this->insertAdmittedRedirect($spec, $policy);
        }, static function (string $error): int { return 0; });
    }

    /** @param array{source_conflict_scope: 'any'|'manual'|'none'} $policy */
    private function insertAdmittedRedirect(ABJ_404_Solution_RedirectSpec $spec, array $policy): int {
        $fromURL = $spec->getFromURL();
        $status = $spec->getStatus();
        $disabled = $spec->getDisabled();
        $statusAsInt = ABJ_404_Solution_ExactInteger::read($status, PHP_INT_MIN);
        // Regex sources are programs, so preserve the raw-input grammar
        // check before URL normalization can erase control bytes. Admission
        // below validates the same pattern again after canonicalization for
        // the pattern that will actually be persisted and matched.
        if (!$this->admissionPolicy()->regexSourceIsAdmissibleForStatus($statusAsInt, $fromURL)) {
            return 0;
        }
        // One write boundary owns generated source identity, including the
        // INSERT...SELECT absence check used by evidence capture. Admission,
        // source ownership, and SQL persistence all share this one key.
        if ($statusAsInt !== null) {
            $fromURL = $this->urlNormalization()->normalizeRedirectSourceForStatus($fromURL, $statusAsInt);
            if ($statusAsInt !== ABJ404_STATUS_REGEX) {
                $fromURL = abj_service('query_string_helper')->redirectSourceKey($fromURL, $spec->getQueryMode(),
                    in_array($statusAsInt, array(ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED), true));
            }
            $spec = $spec->withFromURL($fromURL);
        }
        if (!$this->admissionPolicy()->insertIsAllowed($spec, function (): bool {
            return $this->liveColumns()->has('query_mode');
        })) {
            return 0;
        }
        if ($statusAsInt === null) {
            return 0;
        }
        $insertId = 0;

        if (!abj_service('request_context')->ignore_doprocess) {
            $now = $spec->getTimestamp() ?? abj_clock()->now();
            $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
            $canonicalUrl = ABJ_404_Solution_RedirectCanonicalUrl::compute($fromURL);
            $write = $this->persistAdmittedSource($spec, $policy, array(
                'table' => $redirectsTable, 'sourceUrl' => $fromURL, 'timestamp' => $now, 'canonicalUrl' => $canonicalUrl,
            ));
            if ($write['error'] !== '') { $this->lastWriteError = $write['error']; return 0; }
            $insertId = $write['id'];
            $existingId = $write['existingId'];
            $before = $write['before'];

            // A captured-404 insert is the high-frequency frontend path: on a
            // busy site it fires continuously and only changes the captured +
            // high-impact counts. Use the debounced, captured-scoped invalidation
            // so the SUM(CASE) status-count aggregate is not cold-recomputed on
            // every admin load and the unrelated redirect-count cache stays warm
            // (report.md Finding 4). Admin-driven inserts (manual / auto / regex,
            // e.g. Add Redirect or a slug-change auto-redirect) are low-frequency
            // and keep the immediate full invalidation so the admin sees the
            // count change at once.
            $completion = array('status-count-invalidation' => $statusAsInt === ABJ404_STATUS_CAPTURED
                ? static function (): void { ABJ_404_Solution_ViewCacheInvalidator::invalidateCapturedStatusCountsCacheDebounced(); }
                : static function (): void { abj_service('view_read_service')->invalidateStatusCountsCache(); });
            if ($insertId > 0) {
                $completion['count-sync'] = $existingId > 0
                    ? function () use ($before, $existingId): void { $this->countsSync()->syncSince($before, 'id = %d', array($existingId)); }
                    : function () use ($statusAsInt, $disabled): void { $this->countsSync()->syncInserted($statusAsInt, absint($disabled)); };
                $completion['page-purge'] = static function () use ($fromURL, $statusAsInt): void { ABJ_404_Solution_RedirectPageCachePurge::queueRows(array(array('url' => $fromURL, 'status' => $statusAsInt))); };
            }
            if ($status == ABJ404_STATUS_REGEX || strpbrk($fromURL, '*[]|^\\{}') !== false) {
                $completion['regex-cache'] = function (): void { $this->regexCacheStore->clear(); };
            }

            // Step 3c: keep the new row's denorm display columns (dest_for_view
            // / published_status) current so an off-page sort/filter sees fresh
            // values immediately, not only after the nightly reconcile.
            if ($insertId > 0) { $completion['denorm-recompute'] = function () use ($insertId): void { $this->lifecycleService()->recomputeDenormColumns(array($insertId)); }; }
            $this->dbCore->transactionExecutor()->afterCommit(static function () use ($completion): void {
                ABJ_404_Solution_RedirectMutationCompletion::complete($completion);
            });
        }

        return $insertId;
    }

    /**
     * Store the spec as a new row or as the promotion of its source's live
     * owner, reporting the id the statement wrote, never the id admission
     * read. Trash takes no graph lock, so the owner can leave the live set
     * before the promotion runs; admission is then read once more, which
     * stores the redirect as if the trash had landed first.
     *
     * @param array{source_conflict_scope: 'any'|'manual'|'none'} $policy
     * @param array{table: string, sourceUrl: string, timestamp: int, canonicalUrl: string} $target
     * @return array{id: int, existingId: int, before: array<int, array{status:int,disabled:int,count:int}>|null, error: string}
     */
    private function persistAdmittedSource(ABJ_404_Solution_RedirectSpec $spec, array $policy, array $target): array {
        $refused = array('id' => 0, 'existingId' => 0, 'before' => null);
        for ($attempt = 1; ; $attempt++) {
            $owner = $spec->getDisabled() !== 0 ? array('id' => 0, 'error' => '')
                : $this->sourceOwnerForInsert($spec, $target['sourceUrl'], $policy['source_conflict_scope'] === 'any');
            if ($owner['error'] !== '') { return $refused + array('error' => $owner['error']); }
            $before = $owner['id'] > 0 ? $this->countsSync()->snapshot('id = %d', array($owner['id'])) : null;
            $statement = ABJ_404_Solution_RedirectInsertStatement::fromSpec($spec, $target + array(
                'liveColumns' => $this->liveColumns(), 'sourceConflictScope' => $policy['source_conflict_scope'],
                'ownershipPredicate' => $this->graphOwnershipPredicate(), 'existingId' => $owner['id'],
            ));
            try {
                $result = $statement->execute($this->dbCore);
            } catch (ABJ_404_Solution_DatabaseMutationFailedException $e) {
                // The row is evidence or a convenience, never the answer to the
                // visitor's request: a refused write (a read-only server, a full
                // disk) is reported as "not stored" so the caller can still
                // redirect. The executor has already classified and logged it.
                return $refused + array('error' => $e->getMessage());
            }
            if (isset($result['last_error']) && is_string($result['last_error']) && $result['last_error'] !== '') {
                return $refused + array('error' => $result['last_error']);
            }
            $id = $statement->storedId($result);
            if ($id > 0 || $owner['id'] === 0) {
                return array('id' => $id, 'existingId' => $owner['id'], 'before' => $before, 'error' => '');
            }
            if ($attempt >= 2) {
                return $refused + array('error' => 'The row owning this source URL changed during promotion; nothing was saved: '
                    . $target['sourceUrl']);
            }
        }
    }

    /** Resolve an existing source under the graph lock, preserving read errors.
     * @return array{id: int, error: string, reason?: 'source-owned'} Zero id means a new source.
     */
    private function sourceOwnerForInsert(ABJ_404_Solution_RedirectSpec $spec, string $source, bool $sourceMustBeAbsent): array {
        $lookup = new ABJ_404_Solution_RedirectLookupRepository($this->dbCore, $this->f);
        $live = $lookup->getLiveRowsForNormalizedUrl($source);
        if ($live['last_error'] !== '') { return array('id' => 0, 'error' => $live['last_error']); }
        $owner = $this->admissionPolicy()->sourceOwner($spec, $live['rows'], array('source' => $source, 'mustBeAbsent' => $sourceMustBeAbsent));
        $this->lastWriteRefusalReason = $owner['reason'] ?? '';
        return $owner;
    }

    /**
     * @param array<int, int|string> $types
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function deleteSpecifiedRedirects(array $types, string $purgeType): array {
        return $this->lifecycleService()->trashStatuses(array('types' => $types, 'purgeType' => $purgeType));
    }

    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        $this->lastWriteError = '';
        $type = $update->getType();
        $idForUpdate = $update->getId();
        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html((string)$type) . ", Dest: " . esc_html($update->getDestination()) .
                ", ID(s): " . esc_html((string)$idForUpdate));
            return 'bad_update_request';
        }
        return $this->serializeGraphMutation(function () use ($update): string {
            return $this->editWriter()->write($update);
        }, static function (string $error): string { return $error; });
    }

    /**
     * @param callable(array<string, mixed>): (ABJ_404_Solution_RedirectUpdate|non-empty-string) $buildUpdate Build the same-id update from the locked row.
     */
    public function updateRedirectFromStoredRow(int $id, callable $buildUpdate): string {
        if ($id <= 0) { return 'bad_update_request'; }
        return $this->serializeGraphMutation(function () use ($id, $buildUpdate): string {
            $read = (new ABJ_404_Solution_RedirectLookupRepository($this->dbCore, $this->f))->readRedirectsByIDs(array($id));
            if ($read['last_error'] !== '') { return $read['last_error']; }
            if (empty($read['rows'][0]) || !is_array($read['rows'][0])) { return ABJ_404_Solution_RedirectsRepositoryInterface::ROW_NOT_FOUND; }
            $built = $buildUpdate($read['rows'][0]);
            if (is_string($built)) { return $built !== '' ? $built : 'bad_update_request'; }
            if (!($built instanceof ABJ_404_Solution_RedirectUpdate) || $built->getId() !== $id) { return 'bad_update_request'; }
            return $this->updateRedirect($built);
        }, static function (string $error): string { return $error; });
    }

    private function recordWriteFailure(string $message): string {
        $this->lastWriteError = $message;
        $this->logger->warn($this->lastWriteError);
        return $this->lastWriteError;
    }

    /**
     * @param int $id
     * @param string $newstatus
     */
    public function updateRedirectTypeStatus($id, $newstatus): string {
        return $this->serializeGraphMutation(function () use ($id, $newstatus): string {
            return $this->lifecycleService()->updateStatus(array(
                'id' => $id, 'status' => $newstatus, 'ownership' => $this->graphOwnershipPredicate(),
            ));
        }, static function (string $error): string { return $error; });
    }

    /** Restore a row and optionally reset its captured status in one graph mutation.
     * @param array{id: mixed, status?: int|null} $request Null status preserves the stored status.
     * @return string Empty on success, otherwise the refusal cause. Both fields share one SQL statement.
     */
    public function restoreRedirect(array $request): string {
        $id = ABJ_404_Solution_ExactInteger::read($request['id'] ?? null, 1);
        if ($id === null) { return 'Invalid redirect ID for restore.'; }
        $status = $request['status'] ?? null;
        if ($status !== null && !in_array($status, array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER), true)) {
            return 'Invalid captured status for restore.';
        }
        return $this->serializeGraphMutation(function () use ($id, $status): string {
            return $this->lifecycleService()->changeTrashState(array(
                'id' => $id, 'trash' => 0, 'status' => $status, 'ownership' => $this->graphOwnershipPredicate(),
            ));
        }, static function (string $error): string { return $error; });
    }

    /**
     * @param int|string $id
     * @param int|string $trash
     */
    public function moveRedirectsToTrash($id, $trash): string {
        if (absint($trash) === 0) {
            return $this->serializeGraphMutation(function () use ($id, $trash): string {
                return $this->lifecycleService()->changeTrashState(array(
                    'id' => $id, 'trash' => $trash, 'ownership' => $this->graphOwnershipPredicate(),
                ));
            }, static function (string $error): string { return $error; });
        }
        $this->lastWriteError = '';
        return $this->lifecycleService()->changeTrashState(array(
            'id' => $id, 'trash' => $trash, 'ownership' => $this->graphOwnershipPredicate(),
        ));
    }

    public function moveSourceRedirectsToTrash(int $id): string {
        return $this->serializeGraphMutation(function () use ($id): string {
            return $this->lifecycleService()->trashSource(array('id' => $id, 'ownership' => $this->graphOwnershipPredicate()));
        }, static function (string $error): string { return $error; });
    }

    /**
     * @template T of int|string
     * @param callable(): T $operation
     * @param callable(string): T $onFailure Inserts use 0, string-returning APIs carry the error.
     * @return T
     */
    private function serializeGraphMutation(callable $operation, callable $onFailure) {
        if ($this->graphLock !== null) { return $operation(); }
        $this->lastWriteError = '';
        $lock = new ABJ_404_Solution_RedirectGraphLock($this->dbCore->doTableNameReplacements('{wp_abj404_redirects}'));
        return $lock->withOwnership(function () use ($lock, $operation, $onFailure) {
            $this->graphLock = $lock;
            try {
                try { return $operation(); }
                catch (ABJ_404_Solution_RedirectCycleException $e) { return $onFailure($this->recordWriteFailure($e->getMessage())); }
            } finally {
                $this->graphLock = null;
            }
        }, array(
            'onFailure' => fn(string $error) => $onFailure($this->recordWriteFailure($error)),
            'onReleaseFailure' => function (string $error): void { $this->logger->warn($error); },
        ));
    }

    private function lifecycleService(): ABJ_404_Solution_RedirectLifecycleService {
        return $this->lifecycle ??= new ABJ_404_Solution_RedirectLifecycleService(array(
            'core' => $this->dbCore, 'functions' => $this->f,
            'admission' => $this->admissionPolicy(), 'counts' => $this->countsSync(),
            'regexCache' => $this->regexCacheStore, 'logger' => $this->logger,
            'isOwned' => function (): bool { return $this->graphMutationStillOwned(); },
        ));
    }

    private function editWriter(): ABJ_404_Solution_RedirectEditWriter {
        return $this->editWriter ??= new ABJ_404_Solution_RedirectEditWriter(array(
            'core' => $this->dbCore, 'functions' => $this->f,
            'admission' => $this->admissionPolicy(), 'counts' => $this->countsSync(),
            'lifecycle' => $this->lifecycleService(),
            'urlNormalization' => function () { return $this->urlNormalization(); },
            'hasQueryModeColumn' => function (): bool { return $this->liveColumns()->has('query_mode'); },
            'ownershipPredicate' => function (): string { return $this->graphOwnershipPredicate(); },
            'isOwned' => function (): bool { return $this->graphMutationStillOwned(); },
            'recordFailure' => function (string $message): string { return $this->recordWriteFailure($message); },
        ));
    }

    /** Verify held ownership; disabling mutations that require no lock remain valid. */
    private function graphMutationStillOwned(): bool {
        return $this->graphLock === null || $this->graphLock->isOwned();
    }

    private function graphOwnershipPredicate(): string {
        return $this->graphLock !== null ? $this->graphLock->ownershipPredicate() : '1=1';
    }

    /** @return ABJ_404_Solution_RedirectsLiveColumnSet */
    private function liveColumns(): ABJ_404_Solution_RedirectsLiveColumnSet {
        if (!($this->liveColumns instanceof ABJ_404_Solution_RedirectsLiveColumnSet)) {
            $this->liveColumns = new ABJ_404_Solution_RedirectsLiveColumnSet(array(
                'tableMetadata' => $this->dbCore->tableNameResolver(),
                'logger' => $this->logger,
            ));
        }
        return $this->liveColumns;
    }

    /** @return ABJ_404_Solution_StatusCountsMutationSync */
    private function countsSync() {
        return $this->countsSync ??= new ABJ_404_Solution_StatusCountsMutationSync($this->dbCore);
    }

    /** @return ABJ_404_Solution_RedirectWriteAdmissionPolicy */
    private function admissionPolicy() {
        if ($this->admissionPolicy === null) {
            $this->admissionPolicy = new ABJ_404_Solution_RedirectWriteAdmissionPolicy(
                $this->f, $this->logger, $this->chainWalker
            );
        }
        return $this->admissionPolicy;
    }

}
