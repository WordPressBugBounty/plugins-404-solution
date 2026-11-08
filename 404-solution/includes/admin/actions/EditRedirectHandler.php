<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the Edit Redirect form: $_POST['action']=='editRedirect'.
 *
 * Triggered by the admin's edit form on the Redirects and Captured-404 tabs.
 * Verifies the abj404editRedirect link nonce, then writes through to
 * redirectsRepo->updateRedirect()/saveRedirectConditions() via the
 * shared RedirectFormResolver. On success attempts a PRG redirect to the
 * caller's source page (so the post-update view does not re-render the edit
 * form), and rewrites $sub/$action by reference as a defense-in-depth
 * in-request route when headers are already sent.
 *
 * Extracted from PluginLogicAdminActions::handleActionEdit() +
 * updateRedirectData() (148 lines) (M201, design-audit-2026-06-02). Called
 * from View.php's admin-page render and from PluginLogicAdminActions's
 * thin compat shims (used by tests).
 */
class ABJ_404_Solution_EditRedirectHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    /** @var ABJ_404_Solution_RedirectFormResolver */
    private $resolver;

    /**
     * The cardinality cap on the conditions[] payload is enforced by
     * SubmittedRedirectConditions; this is the handler's public name for the
     * same value (EditRedirectConditionsCapTest reads it here) so the two can
     * never disagree.
     */
    const MAX_REDIRECT_CONDITIONS = ABJ_404_Solution_SubmittedRedirectConditions::MAX_CONDITIONS;

    public function __construct(
        ABJ_404_Solution_PluginLogicAdminActions $parent,
        ABJ_404_Solution_RedirectFormResolver $resolver
    ) {
        $this->parent = $parent;
        $this->resolver = $resolver;
    }

    /**
     * Process the editRedirect POST. Returns a human-readable message and
     * rewrites $sub/$action by reference on success (defense-in-depth route
     * when wp_safe_redirect() can no longer fire).
     *
     * @param string $sub admin subpage tab key (by ref)
     * @param string $action admin action verb (by ref)
     * @return string
     */
    public function handle(&$sub, &$action): string {
        if (!array_key_exists('action', $_POST) || $_POST['action'] != "editRedirect") {
            return '';
        }
        if (!is_admin() || !$this->parent->verifyLinkNonce('abj404editRedirect')) {
            return '';
        }

        $message = $this->updateRedirectData();
        if ($message != "") {
            return $message . __('Error: Unable to update redirect data.', '404-solution');
        }

        $redirect = $this->buildPostEditRedirect();

        if (!headers_sent()) {
            $destination = admin_url($this->getMenuParentScript() . $redirect['redirect_url']);
            if (!is_callable(array('ABJ_404_Solution_RedirectLoopGuard', 'permitsDestination'))
                || ABJ_404_Solution_RedirectLoopGuard::permitsDestination($destination)) {
                wp_safe_redirect($destination);
            }
        }

        $sub = $redirect['source_page'];
        $action = '';
        return __('Redirect Information Updated Successfully!', '404-solution');
    }

    /**
     * Parse the Edit Redirect POST form, validate, and write through to
     * redirectsRepo. Returns the error message (or '' on success).
     *
     * Public so the legacy PluginLogicAdminActions::updateRedirectData()
     * shim and existing tests can call it directly.
     *
     * @return string
     */
    public function updateRedirectData(): string {
        try {
            if (array_key_exists('query_mode', $_POST)) {
                ABJ_404_Solution_QueryStringHelper::queryMode(wp_unslash($_POST['query_mode']));
            }
        } catch (InvalidArgumentException $e) {
            return esc_html($e->getMessage());
        }
        $target = $this->resolveUpdateTarget();
        $message = $target['message'];
        $logger = $this->parent->getLogger();
        if ($message !== '') {
            return $message;
        }

        $statusTypeForValidation = ABJ404_STATUS_MANUAL;
        if (isset($_POST['is_regex_url']) && $_POST['is_regex_url'] != '0') {
            $statusTypeForValidation = ABJ404_STATUS_REGEX;
        }
        $sourceResolution = array(
            'statusType' => $statusTypeForValidation,
            'url' => $target['fromURL'],
            'autoPromoted' => false,
            'urlRewritten' => false,
        );
        $originalFromURL = $target['fromURL'];
        if ($target['fromURL'] !== '') {
            $sourceResolution = $this->resolver->resolveSource(
                $statusTypeForValidation,
                $target['fromURL']
            );
            $statusTypeForValidation = $sourceResolution['statusType'];
            $target['fromURL'] = $sourceResolution['url'];
        }

        $typeAndDest = $this->resolver->getRedirectTypeAndDest(array(
            'isRegex' => $statusTypeForValidation === ABJ404_STATUS_REGEX,
            'sourcePattern' => $target['fromURL'],
        ));
        $typeAndDestMessage = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($typeAndDestMessage != "") {
            return $typeAndDestMessage;
        }

        $context = $this->buildUpdateContext($typeAndDest, $statusTypeForValidation);
        if (!$this->contextHasDestination($context)) {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $logger->errorMessage("Update redirect data issue. Type: " . esc_html((string)$context['tdType']) .
                    ", dest: " . esc_html($context['tdDest']));
            return $message;
        }

        if ($target['fromURL'] != "") {
            return $message . $this->updateSingleRedirect(
                $target['fromURL'],
                $context,
                $sourceResolution,
                $originalFromURL
            );
        }

        if (!empty($target['ids_multiple'])) {
            return $message . $this->updateMultipleRedirects($target['ids_multiple'], $context);
        }

        $logger->errorMessage("Issue determining which redirect(s) to update. " .
            "fromURL: " . $target['fromURL'] . ", ids_multiple: " . implode(',', $target['ids_multiple']));
        return $message;
    }

    /**
     * @return array{fromURL: string, ids_multiple: array<int, int>, message: string}
     */
    private function resolveUpdateTarget(): array {
        $message = "";
        $fromURL = "";
        $idsMultiple = array();

        if (
            (!array_key_exists('url', $_POST) || $_POST['url'] == "") &&
            (array_key_exists('ids_multiple', $_POST) && $_POST['ids_multiple'] != "")) {
            $rawIds = $_POST['ids_multiple'];
            if (is_string($rawIds)) {
                $requestedCount = substr_count($rawIds, ',') + 1;
                if ($requestedCount > ABJ_404_Solution_RedirectEditRequest::MAX_SELECTED_IDS) {
                    $message .= sprintf(
                        /* translators: 1: number of redirects selected. 2: maximum allowed. */
                        __('You selected %1$s redirects. At most %2$s can be edited at once, so nothing was changed. Please select fewer and try again.', '404-solution'),
                        number_format_i18n($requestedCount),
                        number_format_i18n(ABJ_404_Solution_RedirectEditRequest::MAX_SELECTED_IDS)
                    ) . "<BR/>";
                } else {
                    $idsMultiple = array_values(array_filter(array_map(
                        static fn(string $value): int => ABJ_404_Solution_ExactInteger::readOr($value, 1, 0),
                        explode(',', $rawIds)
                    ), static fn(int $value): bool => $value > 0));
                }
            }
            if (empty($idsMultiple) && $message === '') {
                $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            }

        } else if (array_key_exists('url', $_POST) && $_POST['url'] != "" &&
            (!array_key_exists('ids_multiple', $_POST) || $_POST['ids_multiple'] == "")) {

            $fromURL = stripslashes((string)$_POST['url']);
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
        }

        return array('fromURL' => $fromURL, 'ids_multiple' => $idsMultiple, 'message' => $message);
    }

    /**
     * @param array<string, mixed> $typeAndDest
     * @return array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null}
     */
    private function buildUpdateContext(array $typeAndDest, int $statusType): array {
        $tdTypeRaw = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdType = ($tdTypeRaw !== '') ? (int)$tdTypeRaw : -1;
        $tdDest = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';

        $startDateRaw = isset($_POST['redirect_start_date']) && is_string($_POST['redirect_start_date']) ? trim($_POST['redirect_start_date']) : '';
        $endDateRaw = isset($_POST['redirect_end_date']) && is_string($_POST['redirect_end_date']) ? trim($_POST['redirect_end_date']) : '';
        $startTs = ABJ_404_Solution_RedirectScheduleTimezone::toEpoch($startDateRaw, '00:00:00');
        $endTs = ABJ_404_Solution_RedirectScheduleTimezone::toEpoch($endDateRaw, '23:59:59');

        return array(
            'tdTypeRaw' => $tdTypeRaw,
            'tdType' => $tdType,
            'tdDest' => $tdDest,
            'code' => $code,
            'statusType' => $statusType,
            'startTs' => $startTs,
            'endTs' => $endTs,
        );
    }

    /**
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     */
    private function contextHasDestination(array $context): bool {
        $isGoneCode = $context['code'] === '410' || $context['code'] === '451';
        return $context['tdTypeRaw'] !== '' && ($context['tdDest'] !== "" || $isGoneCode);
    }

    /**
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     * @param array{statusType: int, url: string, autoPromoted: bool, urlRewritten: bool} $sourceResolution
     */
    private function updateSingleRedirect(
        string $fromURL,
        array $context,
        array $sourceResolution,
        string $originalFromURL
    ): string {
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $id = ABJ_404_Solution_ExactInteger::readOr($_POST['id'] ?? null, 1, 0);
        if ($id === 0) {
            return __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
        }
        $updateError = $redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::fromArray((array_key_exists('query_mode', $_POST)
            ? array('query_mode' => ABJ_404_Solution_QueryStringHelper::queryMode(wp_unslash($_POST['query_mode']))) : array()) + array(
            'id' => $id,
            'type' => $context['tdType'],
            'fromUrl' => (string)$fromURL,
            'destination' => $context['tdDest'],
            'code' => $context['code'],
            'statusType' => (string)$context['statusType'],
            'startTs' => $context['startTs'],
            'endTs' => $context['endTs'],
        )));
        $errorCode = is_scalar($updateError) ? (string)$updateError : '';
        if ($errorCode !== '') {
            return $this->formatUpdateRedirectError($errorCode) . "<BR/>";
        }
        if ($sourceResolution['autoPromoted']) {
            $this->resolver->saveRegexAutoPromoteNotice(
                $id,
                $originalFromURL,
                $fromURL,
                $sourceResolution['urlRewritten']
            );
        }

        if ($id > 0) {
            $submitted = ABJ_404_Solution_SubmittedRedirectConditions::fromRawConditions(
                (isset($_POST['conditions']) && is_array($_POST['conditions'])) ? $_POST['conditions'] : []
            );
            $conditionsError = $redirectsRepo->saveRedirectConditions($id, $submitted->conditions());
            if ($conditionsError !== '') {
                return $this->formatSaveConditionsError($conditionsError) . "<BR/>";
            }
            // After the save, not instead of it: the conditions that fit were
            // stored and the rest of the edit stands. This reports what did
            // not fit so the admin is not left believing all of it was kept.
            if ($submitted->droppedOverMaximum() > 0) {
                return $this->formatConditionsCappedNotice($submitted->droppedOverMaximum()) . "<BR/>";
            }
        }
        return '';
    }

    /**
     * @param array<int, int> $idsMultiple
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     */
    private function updateMultipleRedirects(array $idsMultiple, array $context): string {
        $message = "";
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $redirectsRead = $redirectsRepo->readRedirectsByIDs($idsMultiple);
        // A refused read does not mean the rows are gone: report it, write nothing.
        if ($redirectsRead['last_error'] !== '') { return $this->formatUpdateRedirectError($redirectsRead['last_error']) . "<BR/>"; }
        $redirectsMultiple = $redirectsRead['rows'];
        if (empty($redirectsMultiple)) {
            // Every selected row is gone: another admin deleted them, or
            // deleteOldRedirectsCron did, while the list page holding the
            // checkboxes was still open. Without this branch the loop below
            // runs zero times and returns "", which handleActionEdit() reads
            // as success and answers "Redirect Information Updated
            // Successfully!" after writing nothing. Same "the id no longer has
            // a row" condition the edit screen reports (production report 349),
            // on the write side. Below error level for the same reason: the
            // plugin worked correctly, the selection did not survive.
            $this->parent->getLogger()->debugMessage("Bulk redirect update: no redirect rows exist " .
                    "for requested id(s): " . esc_html(implode(', ', array_map('strval', $idsMultiple))));
            return sprintf(
                /* translators: %s is a comma-separated list of redirect id numbers. */
                _n(
                    'Redirect %s was not found. It may have been deleted since this page was opened.',
                    'Redirects %s were not found. They may have been deleted since this page was opened.',
                    count($idsMultiple),
                    '404-solution'
                ),
                implode(', ', array_map('strval', $idsMultiple))
            ) . "<BR/>";
        }
        foreach ($redirectsMultiple as $redirect) {
            $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
            $updateError = $redirectsRepo->updateRedirectFromStoredRow($redirectId, function (array $row) use ($context, $redirectId) {
                return ABJ_404_Solution_RedirectUpdate::fromArray(array(
                    'id' => $redirectId,
                    'type' => $context['tdType'],
                    'fromUrl' => isset($row['url']) && is_string($row['url']) ? $row['url'] : '',
                    'destination' => $context['tdDest'],
                    'code' => $context['code'],
                    'statusType' => (string)$context['statusType'],
                ));
            });
            $errorCode = is_scalar($updateError) ? (string)$updateError : '';
            if ($errorCode !== '') {
                $message .= $this->formatUpdateRedirectError($errorCode) . "<BR/>";
                continue;
            }
        }
        return $message;
    }

    private function formatUpdateRedirectError(string $errorCode): string {
        if (strpos($errorCode, 'Redirect loop:') === 0) {
            return __('Error:', '404-solution') . ' ' . esc_html($errorCode);
        }
        if ($errorCode === 'bad_update_request') {
            return __('Error: Bad data passed for update redirect request.', '404-solution');
        }

        return sprintf(
            __('Error: Unable to update redirect data. Repository result: %s', '404-solution'),
            esc_html($errorCode)
        );
    }

    /**
     * @param string $errorMessage Underlying DB/transaction error text (per
     *     the Error visibility philosophy, surfaced rather than genericized).
     */
    private function formatSaveConditionsError(string $errorMessage): string {
        return sprintf(
            __('Error: Unable to save redirect conditions. Repository result: %s', '404-solution'),
            esc_html($errorMessage)
        );
    }

    /**
     * Build the post-edit PRG redirect querystring + the source page that
     * the in-request render should target.
     *
     * @return array{source_page: string, redirect_url: string}
     */
    private function buildPostEditRedirect(): array {
        // Through RedirectEditRequest's list, not a second copy of it: the read
        // side answers the same question for the back link and the hidden
        // inputs, and two lists of "which subpage is valid" drift apart.
        $source_page = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_page');
        if (!ABJ_404_Solution_RedirectEditRequest::isListSubpage($source_page)) {
            $source_page = ABJ_404_Solution_RedirectEditRequest::DEFAULT_SUBPAGE;
        }

        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page . "&updated=1";

        $source_filter = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_filter', '');
        if ($source_filter !== '' && $source_filter !== '0') {
            $redirect_url .= "&filter=" . urlencode($source_filter);
        }

        $source_orderby = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_orderby', '');
        $source_order = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_order', '');
        if ($source_orderby !== '' && $source_order !== ''
                && !($source_orderby === "url" && $source_order === "ASC")) {
            $redirect_url .= "&orderby=" . urlencode($source_orderby);
            $redirect_url .= "&order=" . urlencode($source_order);
        }

        $source_paged = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_paged', '');
        if ($source_paged !== '' && (int)$source_paged > 1) {
            $redirect_url .= "&paged=" . urlencode($source_paged);
        }

        return array('source_page' => $source_page, 'redirect_url' => $redirect_url);
    }

    /**
     * Resolve the admin parent script the plugin's menu page is registered
     * under. Used to build correct admin_url() after a successful edit.
     *
     * @return string
     */
    private function getMenuParentScript(): string {
        $options = abj_service('options_repository')->getOptions(true);
        return ABJ_404_Solution_AdminPageUrlBuilder::pageFile(is_array($options) ? $options : array());
    }

    /**
     * @param int $droppedCount How many valid conditions were dropped for
     *     arriving past MAX_REDIRECT_CONDITIONS.
     */
    private function formatConditionsCappedNotice(int $droppedCount): string {
        return sprintf(
            /* translators: 1: number of conditions that were not saved. 2: the maximum allowed. */
            _n(
                'Note: %1$s condition was not saved. A redirect may have at most %2$s conditions.',
                'Note: %1$s conditions were not saved. A redirect may have at most %2$s conditions.',
                $droppedCount,
                '404-solution'
            ),
            number_format_i18n($droppedCount),
            number_format_i18n(self::MAX_REDIRECT_CONDITIONS)
        );
    }
}
