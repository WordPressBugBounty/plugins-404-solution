<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/../redirects/RedirectsRetentionService.php';
require_once __DIR__ . '/RedirectConditionsRepository.php';
require_once __DIR__ . '/RedirectExportReader.php';
require_once __DIR__ . '/RedirectLookupRepository.php';
require_once __DIR__ . '/RedirectRegexCacheStore.php';
require_once __DIR__ . '/../redirects/RedirectCanonicalUrl.php';
require_once __DIR__ . '/../redirects/RedirectLookupService.php';
require_once __DIR__ . '/RedirectWriteService.php';

/**
 * Redirect lookup repository and compatibility facade.
 *
 * Extracted from the DataAccess monolith (Phase 2 of the DataAccess refactor).
 * Methods originate from two sources:
 *   - DataAccessTrait_Redirects (entirely absorbed)
 *   - DataAccessTrait_Stats (redirect update/query methods relocated)
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_RedirectsRepository implements ABJ_404_Solution_RedirectsRepositoryInterface {

    /** Maximum number of regex redirects to cache per-request (memory guard) */
    const REGEX_CACHE_MAX_COUNT = ABJ_404_Solution_RedirectRegexCacheStore::MAX_COUNT;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectConditionsRepository */
    private $conditionsRepository;

    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCacheStore;

    /** @var ABJ_404_Solution_RedirectWriteService */
    private $writeService;

    /** @var ABJ_404_Solution_RedirectExportReader */
    private $exportReader;

    /** @var ABJ_404_Solution_RedirectLookupService */
    private $lookupService;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null,
        ?ABJ_404_Solution_RedirectConditionsRepository $conditionsRepository = null,
        ?ABJ_404_Solution_RedirectRegexCacheStore $regexCacheStore = null,
        ?ABJ_404_Solution_RedirectWriteService $writeService = null,
        ?ABJ_404_Solution_RedirectExportReader $exportReader = null,
        ?ABJ_404_Solution_RedirectLookupService $lookupService = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->conditionsRepository = $conditionsRepository !== null
            ? $conditionsRepository
            : new ABJ_404_Solution_RedirectConditionsRepository($dbCore, $this->logger);
        $this->regexCacheStore = $regexCacheStore !== null
            ? $regexCacheStore
            : new ABJ_404_Solution_RedirectRegexCacheStore();
        $this->writeService = $writeService !== null
            ? $writeService
            : new ABJ_404_Solution_RedirectWriteService($dbCore, array(
                'functions' => $this->f,
                'logging' => $this->logger,
                'regexCacheStore' => $this->regexCacheStore,
                'urlNormalization' => $urlNormalization,
            ));
        $this->exportReader = $exportReader !== null
            ? $exportReader
            : new ABJ_404_Solution_RedirectExportReader($dbCore);
        $this->lookupService = $lookupService !== null
            ? $lookupService
            : new ABJ_404_Solution_RedirectLookupService(
                $this->f,
                new ABJ_404_Solution_RedirectLookupRepository($dbCore, $this->f),
                $urlNormalization
            );
    }

    /**
     * Expose the underlying database-query service. Collaborators that accept a
     * repository and resolve their own query service (e.g.
     * ABJ_404_Solution_ForeignRedirectSourceReader::resolveDatabaseQuery())
     * probe for this accessor; without it they fall back to a null query
     * service and silently read zero rows. DatabaseCore implements
     * ABJ_404_Solution_DatabaseQueryInterface, so callers can use the result
     * directly. Mirrors ABJ_404_Solution_DataAccess::getDbCore().
     *
     * @return ABJ_404_Solution_DatabaseCore
     */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_RedirectsRetentionService */
    private function retentionService() {
        return new ABJ_404_Solution_RedirectsRetentionService($this->dbCore, $this, $this->f, $this->logger);
    }

    /**
     * Legacy facade retained for integrations that still call RedirectsRepository directly.
     * @param mixed $passId
     */
    function deleteOldRedirectsCron($passId = null) {
        return $this->retentionService()->deleteOldRedirectsCron($passId);
    }

    /**
     * @param array<string, mixed> $options
     * @param int $now
     * @param string $optionKey
     * @param string $statusList
     * @param string $debugMessageType
     * @return int
     */
    private function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        return $this->retentionService()->deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType);
    }

    private function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        return $this->retentionService()->deleteOldLogsByAge($daysToKeep, $now);
    }

    // =========================================================================
    // Regex cache accessors (static state moved from DataAccess)
    // =========================================================================

    /** @inheritDoc */
    public function clearRegexRedirectsCache(): void {
        $this->regexCacheStore->clear();
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        return ABJ_404_Solution_RedirectRegexCacheStore::getRegexRedirectsCache();
    }

    /** @param array<int, array<string, mixed>>|null $cache @return void */
    public static function setRegexRedirectsCache($cache): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexRedirectsCache($cache);
    }

    /** @return bool */
    public static function isRegexCacheDisabled(): bool {
        return ABJ_404_Solution_RedirectRegexCacheStore::isRegexCacheDisabled();
    }

    /** @param bool $disabled @return void */
    public static function setRegexCacheDisabled(bool $disabled): void {
        ABJ_404_Solution_RedirectRegexCacheStore::setRegexCacheDisabled($disabled);
    }

    // =========================================================================
    // Static utilities (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public static function computeRedirectsCanonicalUrl($url): string {
        return ABJ_404_Solution_RedirectCanonicalUrl::compute($url);
    }

    /** @inheritDoc */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string {
        return ABJ_404_Solution_RedirectCanonicalUrl::hitsSqlExpression($columnExpr);
    }

    // =========================================================================
    // Redirect CRUD (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    function deleteRedirect($id) {
        $this->writeService->deleteRedirect($id);
    }

    /** @inheritDoc */
    public function getExportableRedirectRows(): array {
        return $this->exportReader->getExportableRedirectRows();
    }

    /** @inheritDoc */
    function setupRedirect(ABJ_404_Solution_RedirectSpec $spec) {
        return $this->writeService->setupRedirect($spec);
    }

    public function getLastWriteError(): string {
        return $this->writeService->getLastWriteError();
    }

    /** @return ''|'source-owned' Distinguishes source admission from a transient storage refusal. */
    public function getLastWriteRefusalReason(): string {
        return $this->writeService->getLastWriteRefusalReason();
    }

    /** @inheritDoc */
    public function moveSourceRedirectsToTrash(int $id): string {
        return $this->writeService->moveSourceRedirectsToTrash($id);
    }

    /** @inheritDoc */
    public function setupRedirectIfSourceAbsent(ABJ_404_Solution_RedirectSpec $spec): int {
        return $this->writeService->setupRedirectIfSourceAbsent($spec);
    }

    /** @inheritDoc */
    public function runWithImportBatch(callable $operation): string {
        $started = false;
        $error = $this->runWithGraphOwnership(function () use ($operation, &$started): void {
            $started = true;
            ABJ_404_Solution_RedirectRegexCacheStore::runWithManualSnapshot($operation);
        });
        if (!$started) {
            // Grouping is optional. Every row still acquires and verifies its
            // ordinary mutex when the shared scope could not be established.
            $operation();
            return '';
        }
        return $error;
    }

    /**
     * Require rollback-capable redirect storage before an intent transaction
     * writes anything. Engine recovery belongs to normal safe-request upgrades;
     * doing ALTER here would commit the very transaction we must preserve.
     *
     * @throws ABJ_404_Solution_DatabaseMutationFailedException
     */
    public function requireTransactionalStorage(ABJ_404_Solution_DatabaseTransactionConnection $connection): void {
        global $wpdb;
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; leased connection executes the query.
        $sql = $wpdb->prepare('SHOW TABLE STATUS WHERE `Name` = %s', $table);
        if (!is_string($sql) || $sql === '') {
            throw new ABJ_404_Solution_StatementPreparationException('redirect storage engine verification');
        }
        $result = $connection->execute($sql);
        if (!$result['query_succeeded'] || $result['last_error'] !== '') {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                $result['last_error'] !== '' ? $result['last_error'] : 'The redirect storage engine could not be verified.',
                $result['cause']);
        }
        $row = isset($result['rows'][0]) && is_array($result['rows'][0])
            ? array_change_key_case($result['rows'][0], CASE_LOWER) : array();
        if (!isset($row['engine']) || !is_string($row['engine'])
                || strcasecmp($row['engine'], 'InnoDB') !== 0) {
            throw new ABJ_404_Solution_DatabaseMutationFailedException(
                ABJ_404_Solution_DatabaseMutationFailedException::SETUP_FAILED,
                'Deferred redirect delivery requires InnoDB redirect storage; the intent remains pending until normal engine recovery succeeds.');
        }
    }

    /**
     * Hold graph ownership through the entire operation, including a caller's
     * owned commit. Unlike import batching, this does not preload every manual
     * rule. Failed acquisition refuses the operation without a fallback write.
     *
     * @param callable(callable():bool):void $operation Receives a guard to check before committing dependent writes.
     * @return string Empty on success, the actual lock error on refusal.
     */
    public function runWithGraphOwnership(callable $operation): string {
        $lock = new ABJ_404_Solution_RedirectGraphLock($this->dbCore->doTableNameReplacements('{wp_abj404_redirects}'));
        return $lock->withOwnership(function () use ($operation, $lock): string {
            $operation(static function () use ($lock): bool { return $lock->isOwned(); });
            return '';
        }, array(
            'onFailure' => static fn(string $error): string => $error,
            'onReleaseFailure' => function (string $error): void { $this->logger->warn($error); },
        ));
    }

    /** @inheritDoc */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        return $this->lookupService->getActiveRedirectForURL($url, $degradedMode);
    }

    /** @inheritDoc */
    function getExistingRedirectForURL($url) {
        return $this->lookupService->getExistingRedirectForURL($url);
    }

    /** @inheritDoc */
    function deleteSpecifiedRedirects(array $types, string $purgeType): array {
        return $this->writeService->deleteSpecifiedRedirects($types, $purgeType);
    }

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @inheritDoc */
    public function getRedirectConditions(int $redirectId): array {
        return $this->conditionsRepository->getRedirectConditions($redirectId);
    }

    /** @inheritDoc */
    public function readRedirectConditions(int $redirectId): array {
        return $this->conditionsRepository->readRedirectConditions($redirectId);
    }

    /** @inheritDoc */
    public function saveRedirectConditions(int $redirectId, array $conditions): string {
        return $this->conditionsRepository->saveRedirectConditions($redirectId, $conditions);
    }

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /** @inheritDoc */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        return $this->writeService->updateRedirect($update);
    }

    /** @inheritDoc */
    public function updateRedirectFromStoredRow(int $id, callable $buildUpdate): string {
        return $this->writeService->updateRedirectFromStoredRow($id, $buildUpdate);
    }

    /** @inheritDoc */
    function getRedirectsByIDs($ids) {
        return (new ABJ_404_Solution_RedirectLookupRepository($this->dbCore, $this->f))->getRedirectsByIDs($ids);
    }

    /** @inheritDoc */
    public function readRedirectsByIDs($ids) {
        return (new ABJ_404_Solution_RedirectLookupRepository($this->dbCore, $this->f))->readRedirectsByIDs($ids);
    }

    /** @inheritDoc */
    function updateRedirectTypeStatus($id, $newstatus) {
        return $this->writeService->updateRedirectTypeStatus($id, $newstatus);
    }

    /** @inheritDoc */
    function moveRedirectsToTrash($id, $trash) {
        return $this->writeService->moveRedirectsToTrash($id, $trash);
    }

    /** Atomic restore capability, additive to the legacy repository interface.
     * @param array{id: mixed, status?: int|null} $request Null/omitted status preserves it;
     *   otherwise use Captured, Ignored or Later. Invalid/missing IDs refuse the write.
     * @return string Empty on success or the underlying refusal cause.
     */
    public function restoreRedirect(array $request): string {
        return $this->writeService->restoreRedirect($request);
    }

}
