<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RedirectChainTraversal.php';

/**
 * Decides whether a redirect may be written at all.
 *
 * Admission rules describe the redirect itself rather than the SQL storing it:
 *
 *   1. A regex redirect's source must be a pattern the matching engine can
 *      actually compile, or every frontend request pays for a pattern that
 *      can never match.
 *   2. An automatic redirect's destination must resolve to something
 *      published. Auto redirects are created unattended (slug changes,
 *      spell-check promotion), so a destination that is trashed, draft or
 *      simply absent would otherwise be written silently and then send
 *      visitors to a 404 of its own.
 *   3. URL and internal-object destinations must not close a stored redirect
 *      cycle. A pending regex is evaluated by the same matcher as persisted
 *      rules. A check that cannot run refuses the write rather than admitting
 *      it.
 *   4. Explicit query-mode changes must fit the live table's capabilities;
 *      partial updates retain the existing mode and normalize its source key.
 *
 * Kept apart from the write service because it is a decision, not a write:
 * it holds no SQL and touches no cache. Admin, REST, CLI and import writes
 * reach these same decisions through RedirectWriteService.
 */
class ABJ_404_Solution_RedirectWriteAdmissionPolicy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectChainTraversal|null */
    private $chainWalker;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RedirectChainTraversal|null $chainWalker
     */
    public function __construct($functions, $logger, $chainWalker = null) {
        $this->f = $functions;
        $this->logger = $logger;
        $this->chainWalker = $chainWalker;
    }

    /**
     * Whether a regex redirect's source pattern is usable. Logs the specific
     * validation failure, since the admin only sees that the redirect was
     * rejected.
     *
     * @param string $source
     * @return bool
     */
    public function regexSourceIsValid(string $source): bool {
        $validator = new ABJ_404_Solution_RegexSourcePatternValidator($this->f);
        $validation = $validator->validate($source);
        if ($validation['valid']) {
            return true;
        }

        $detail = $validation['detail'] !== '' ? ' ' . $validation['detail'] : '';
        $this->logger->warn('Invalid regex source pattern.' . $detail);
        return false;
    }

    /**
     * Apply regex admission only for regex rows, preserving both raw-input
     * grammar and canonical persisted-pattern validation at their boundaries.
     *
     * @param int|string|null $status
     * @param string $source
     * @return bool
     */
    public function regexSourceIsAdmissibleForStatus($status, string $source): bool {
        return (int)$status !== ABJ404_STATUS_REGEX || $this->regexSourceIsValid($source);
    }

    /**
     * Whether an automatic redirect's destination points at something that is
     * actually published and reachable.
     *
     * Returns true when the WordPress lookup function is unavailable rather
     * than rejecting: this runs during frontend request handling on hosts
     * where a plugin conflict can leave the API unloaded, and refusing every
     * automatic redirect there would be a worse failure than admitting one
     * that a later request will re-validate.
     *
     * @param int $type One of the ABJ404_TYPE_* constants.
     * @param mixed $finalDest Destination id (post, category or tag), or URL for an external type.
     * @return bool
     */
    public function isValidAutomaticRedirectDestination($type, $finalDest): bool {
        $destId = self::exactDestinationId($finalDest);

        if ($type === ABJ404_TYPE_POST) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_post')) {
                return true;
            }
            $ref = ABJ_404_Solution_PostRef::fromWpPost(get_post($destId));
            if ($ref === null) {
                return false;
            }
            return $ref->isPublished();
        }

        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_term')) {
                return true;
            }
            // A category-type redirect resolves any hierarchical taxonomy's
            // term (PermalinkResolver::fillCategory uses get_term_link, so
            // WooCommerce product_cat works), so it is validated the same
            // way: restricting the lookup to 'category' refused every
            // automatic redirect to a custom taxonomy term.
            $taxonomy = ($type === ABJ404_TYPE_CAT) ? '' : 'post_tag';
            $term = get_term($destId, $taxonomy);
            if ($term === null || is_wp_error($term)) {
                return false;
            }
            return is_object($term);
        }

        if ($type === ABJ404_TYPE_HOME) {
            return true;
        }

        if ($type === ABJ404_TYPE_EXTERNAL) {
            return self::isLivePostTypeArchiveUrl($finalDest);
        }

        return false;
    }

    /**
     * Whether an automatic external-type destination is this site's own
     * archive of a registered post type. Post type archives have no redirect
     * type of their own, so a trashed custom post redirected to its archive
     * (RemovedPostParentResolver) stores the archive URL. Any other URL is
     * not something an unattended process can vouch for, so it is refused.
     *
     * @param mixed $finalDest
     * @return bool
     */
    private static function isLivePostTypeArchiveUrl($finalDest): bool {
        if (!is_string($finalDest) || $finalDest === '') {
            return false;
        }
        if (!function_exists('get_post_types') || !function_exists('get_post_type_archive_link')) {
            return true;
        }
        $postTypes = get_post_types(array('has_archive' => true), 'names');
        if (!is_array($postTypes)) {
            return false;
        }
        foreach ($postTypes as $postType) {
            $archiveUrl = get_post_type_archive_link((string)$postType);
            if (is_string($archiveUrl) && $archiveUrl !== '' && $archiveUrl === $finalDest) {
                return true;
            }
        }
        return false;
    }

    /**
     * The destination id exactly as the write will store it, or 0 when the
     * value is not one.
     *
     * absint() answers a different question than the write path asks: it maps
     * -3 to 3 and '12abc' to 12. final_dest is stored verbatim (it is a string
     * column, because an external redirect keeps its URL there), so a coerced
     * id would validate one destination and then write another. Checking a
     * value the write will not use is how an automatic redirect to an
     * unreachable page passes the one gate that exists to stop it -- so the
     * value is parsed rather than coerced, and anything that is not already an
     * id is refused.
     *
     * @param mixed $finalDest
     * @return int Positive id, or 0 when the value is not usable as one.
     */
    private static function exactDestinationId($finalDest): int {
        if (is_int($finalDest)) {
            return $finalDest > 0 ? $finalDest : 0;
        }
        if (is_string($finalDest) && preg_match('/^[0-9]+$/', $finalDest) === 1) {
            return (int)$finalDest;
        }
        return 0;
    }

    /**
     * Apply the insert admission rules before the write service builds SQL.
     * The metadata callback is invoked only when an explicit query mode needs it.
     *
     * @param ABJ_404_Solution_RedirectSpec $spec
     * @param callable(): bool $queryModeColumnExists
     * @return bool
     * @throws ABJ_404_Solution_RedirectCycleException
     */
    public function insertIsAllowed(ABJ_404_Solution_RedirectSpec $spec, callable $queryModeColumnExists): bool {
        $fromURL = $spec->getFromURL();
        $status = $spec->getStatus();
        $type = $spec->getType();
        $finalDest = $spec->getFinalDest();
        // Admission and %d persistence must describe the same row. An
        // unreadable discriminator is not the legitimate -1 sentinel: '1.5'
        // would bypass manual-cycle admission as -1, then persist as status 1.
        // Preserve exact signed integers, but refuse values SQL would truncate.
        $statusAsInt = ABJ_404_Solution_ExactInteger::read($status, PHP_INT_MIN);
        $typeAsInt = ABJ_404_Solution_ExactInteger::read($type, PHP_INT_MIN);
        if ($statusAsInt === null || $typeAsInt === null) {
            $this->logger->warn('Redirect refused: status and type must be exact integers. From: ' .
                esc_url($fromURL) . ' to: ' . esc_url($finalDest) . ', Type: ' . esc_html((string)$type) .
                ', Status: ' . esc_html((string)$status));
            return false;
        }
        if ($statusAsInt === ABJ404_STATUS_REGEX && !$this->regexSourceIsValid($fromURL)) {
            return false;
        }
        $this->assertRedirectTerminates(array(
            'status' => $statusAsInt, 'type' => $typeAsInt, 'source' => $fromURL,
            'destination' => $finalDest, 'disabled' => $spec->getDisabled(), 'code' => (int)$spec->getCode(),
            'queryMode' => $spec->getQueryMode(),
        ));
        if ($statusAsInt === ABJ404_STATUS_AUTO && !$this->isValidAutomaticRedirectDestination($typeAsInt, $finalDest)) {
            $this->logger->debugMessage("Skipping automatic redirect with invalid destination. " .
                "From: " . esc_url($fromURL) . ", Dest: " . esc_html((string)$finalDest) .
                ", Type: " . esc_html((string)$type) . ", Status: " . esc_html((string)$status));
            return false;
        }
        if ($spec->getQueryMode() !== null && !$queryModeColumnExists()) {
            $this->logger->warn('Redirect refused: query_mode column is unavailable; complete the database upgrade before using an explicit query mode.');
            return false;
        }
        return true;
    }

    /**
     * Decide which live row, if any, a new redirect for this source becomes.
     * A redirect may promote one evidence row, but cannot overwrite a rule.
     *
     * @param array<int, array<string, mixed>> $rows Existing live source owners.
     * @param array{source: string, mustBeAbsent: bool} $request
     * @return array{id: int, error: string, reason?: 'source-owned'} Zero id with no error means a new source.
     */
    public function sourceOwner(ABJ_404_Solution_RedirectSpec $spec, array $rows, array $request): array {
        if ($rows === array()) { return array('id' => 0, 'error' => ''); }
        $promotable = !$request['mustBeAbsent'] && count($rows) === 1
            && in_array(ABJ_404_Solution_ExactInteger::readOr($spec->getStatus(), 0, 0),
                array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX), true)
            && in_array(ABJ_404_Solution_ExactInteger::readOr($rows[0]['status'] ?? null, 0, 0),
                array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER), true);
        if (!$promotable) {
            return array('id' => 0, 'error' => self::sourceConflictMessage($request['source']),
                'reason' => 'source-owned');
        }
        $id = ABJ_404_Solution_ExactInteger::readOr($rows[0]['id'] ?? null, 1, 0);
        return array('id' => $id, 'error' => $id > 0 ? '' : 'Stored source row has an invalid ID.');
    }

    /**
     * Explain a source ownership conflict and give the operator a recovery
     * action that applies to inserts, edits, and restores alike.
     *
     * @param string $source The normalized source URL already shown in the error.
     * @return string
     */
    public static function sourceConflictMessage(string $source): string {
        return sprintf(
            __('A live row already owns this source URL: %s [ABJ404_SOURCE_CONFLICT] '
                . 'Edit or move the existing redirect to the trash before trying again.', '404-solution'),
            $source
        );
    }

    /**
     * Refuse destinations that could close a stored redirect cycle. Captured
     * and ignored rows remain outside this admission check because they are
     * evidence rather than graph edges; automatic rows are checked too, but a
     * refusal is returned by the normal write boundary rather than thrown to
     * the frontend caller.
     *
     * Fails closed: a walker that cannot be resolved, or a walk that could
     * not consult every rule, refuses the write rather than admitting a
     * redirect nobody checked. Existing redirects are untouched either way.
     *
     * @param array{status: int, type: int, source: string, destination: string, disabled?: int, code?: int, excludedRedirectId?: int, queryMode?: string|null} $rule
     * @return void
     * @throws ABJ_404_Solution_RedirectCycleException
     */
    public function assertRedirectTerminates(array $rule): void {
        $normalized = $rule + array('disabled' => 0, 'code' => 301, 'excludedRedirectId' => 0, 'queryMode' => null);
        if (!$this->isCycleAdmissionCandidate($normalized)) {
            return;
        }
        $walker = $this->chainWalker();
        if (is_string($walker)) {
            throw ABJ_404_Solution_RedirectCycleException::unverifiable(array($normalized['source']), $walker);
        }
        $excludedRedirectId = $normalized['excludedRedirectId'];
        $context = array('excluded_redirect_id' => $excludedRedirectId);
        $visited = array($normalized['source']);
        if ($normalized['status'] === ABJ404_STATUS_REGEX) {
            $context['pending_regex'] = array(
                'id' => $excludedRedirectId, 'url' => $normalized['source'],
                'type' => $normalized['type'], 'final_dest' => $normalized['destination'],
                'code' => $normalized['code'], 'query_mode' => $normalized['queryMode'],
            );
            $visited = array();
        }
        if ($excludedRedirectId > 0 || isset($context['pending_regex'])) {
            $lookupContext = $context;
        } else {
            $lookupContext = array();
        }
        $result = $walker->walkFromDestination(array(
            'type' => (int)$normalized['type'],
            'destination' => $normalized['destination'],
            'source' => (string)$normalized['source'],
            'code' => (int)$normalized['code'],
        ), $visited, $lookupContext);
        if ($result['status'] === 'cycle') {
            throw new ABJ_404_Solution_RedirectCycleException($result['path']);
        }
        if ($result['status'] === 'unverifiable') {
            throw ABJ_404_Solution_RedirectCycleException::unverifiable($result['path'], $result['reason'] ?? 'unknown');
        }
    }

    /** @return ABJ_404_Solution_RedirectChainTraversal|string The injected or registered traversal, or why neither is available. */
    private function chainWalker() {
        if ($this->chainWalker instanceof ABJ_404_Solution_RedirectChainTraversal) {
            return $this->chainWalker;
        }
        try {
            $walker = abj_service('redirect_chain_walker');
        } catch (\Throwable $e) {
            return sprintf('redirect_chain_walker unavailable: %s (code %s): %s',
                get_class($e), (string)$e->getCode(), $e->getMessage());
        }
        return $walker instanceof ABJ_404_Solution_RedirectChainTraversal ? $walker
            : 'redirect_chain_walker resolved to ' . (is_object($walker) ? get_class($walker) : gettype($walker));
    }

    /**
     * Stored-row cycle admission: convert a persisted row plus overrides, then delegate.
     *
     * @param array{row: array<string, mixed>, excludedRedirectId: int, status?: int|null, disabled?: int|null} $request
     * @return void
     * @throws ABJ_404_Solution_RedirectCycleException
     */
    public function assertStoredRedirectTerminates(array $request): void {
        $row = isset($request['row']) && is_array($request['row']) ? $request['row'] : array();
        $excludedRedirectId = isset($request['excludedRedirectId']) ? (int)$request['excludedRedirectId'] : 0;
        $statusOverride = array_key_exists('status', $request) ? $request['status'] : null;
        $disabledOverride = array_key_exists('disabled', $request) ? $request['disabled'] : null;
        $status = $statusOverride !== null ? (int)$statusOverride : $this->rowInt($row, 'status', 0);
        $disabled = $disabledOverride !== null ? (int)$disabledOverride : $this->rowInt($row, 'disabled', 0);
        $this->assertRedirectTerminates(array(
            'status' => $status,
            'type' => $this->rowInt($row, 'type', 0),
            'source' => $this->rowString($row, 'url'),
            'destination' => $this->rowString($row, 'final_dest'),
            'disabled' => $disabled,
            'code' => $this->rowInt($row, 'code', 301),
            'excludedRedirectId' => $excludedRedirectId,
            'queryMode' => ABJ_404_Solution_QueryStringHelper::storedQueryMode($row),
        ));
    }

    /**
     * Resolve partial-update semantics and schema admission before any write.
     *
     * @param array{update: ABJ_404_Solution_RedirectUpdate, sourceURL: string, existingRow: array<string, mixed>, hasQueryModeColumn: bool} $request
     * @return array{sourceURL: string, changes: array<string, string|null>, error: string}
     */
    public function queryPolicyForUpdate(array $request): array {
        $update = $request['update'];
        $fromURL = isset($request['sourceURL']) && is_string($request['sourceURL']) ? $request['sourceURL'] : '';
        $existingRow = isset($request['existingRow']) && is_array($request['existingRow']) ? $request['existingRow'] : array();
        $hasQueryModeColumn = !empty($request['hasQueryModeColumn']);
        $changes = $update->getQueryModeChange();
        $mode = $changes['query_mode'] ?? null;
        if ($changes === array()) {
            $mode = ABJ_404_Solution_QueryStringHelper::storedQueryMode($existingRow);
        } elseif (!$hasQueryModeColumn) {
            if ($mode !== null) {
                $this->logger->warn('Redirect update refused: query_mode column is unavailable.');
                return array('sourceURL' => $fromURL, 'changes' => array(), 'error' => 'query_mode_schema_unavailable');
            }
            $changes = array();
        }
        $status = (int)$update->getStatusType();
        if ($status !== ABJ404_STATUS_REGEX) {
            $fromURL = abj_service('query_string_helper')->redirectSourceKey($fromURL, $mode,
                in_array($status, array(ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED), true));
        }
        return array('sourceURL' => $fromURL, 'changes' => $changes, 'error' => '');
    }

    /**
     * @param array{status: int, type: int, destination: string, disabled: int, code: int} $rule
     * @return bool
     */
    private function isCycleAdmissionCandidate(array $rule): bool {
        $status = (int)$rule['status'];
        $type = (int)$rule['type'];
        $destination = is_string($rule['destination']) ? $rule['destination'] : (string)$rule['destination'];
        $disabled = (int)$rule['disabled'];
        $code = (int)$rule['code'];
        if (!in_array($status, array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX), true)) {
            return false;
        }
        if ($disabled !== 0) {
            return false;
        }
        if (in_array($code, array(410, 451), true)) {
            return false;
        }
        $supportedTypes = array(
            (int)ABJ404_TYPE_EXTERNAL,
            (int)ABJ404_TYPE_POST,
            (int)ABJ404_TYPE_CAT,
            (int)ABJ404_TYPE_TAG,
            (int)ABJ404_TYPE_HOME,
        );
        if (!in_array($type, $supportedTypes, true)) {
            return false;
        }
        if ($type === (int)ABJ404_TYPE_EXTERNAL && trim($destination) === '') {
            return false;
        }
        // A `$N` destination depends on the requested URL, so there is no
        // single path to walk; RedirectLoopGuard checks the substituted URL at
        // request time. Any other `$` is literal and the walk must run.
        return $type !== (int)ABJ404_TYPE_EXTERNAL || $status !== ABJ404_STATUS_REGEX
            || !(new ABJ_404_Solution_RegexDestinationSubstitutor())->hasCaptureTokens($destination);
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key, int $default): int {
        return ABJ_404_Solution_ExactInteger::readOr($row[$key] ?? null, PHP_INT_MIN, $default);
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string {
        $value = $row[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}
