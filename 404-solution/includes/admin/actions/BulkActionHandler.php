<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles any action whose verb starts with 'bulk' (bulktrash,
 * bulk_trash_delete_permanently, bulk_trash_restore, bulkignore, bulklater,
 * bulkcaptured). Validates that $_POST['idnum'] is present (echoes an error
 * and returns '' if not, matching pre-refactor behavior) and that it does not
 * exceed MAX_BULK_ACTION_IDS (echoes an error and returns '' without running
 * any mutation if it does), then iterates ids and applies the per-row
 * status/trash/delete operation.
 *
 * Registered in the dispatcher's prefix-match map (not the exact-match map).
 *
 * The per-row dispatch logic previously lived on
 * PluginLogicAdminActions::doBulkAction() (M201, design-audit-2026-06-02).
 * That parent method is now a thin shim that calls doBulkAction() here so
 * existing tests/callers do not break.
 */
class ABJ_404_Solution_BulkActionHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /**
     * Maximum number of ids accepted in a single bulk action POST. The
     * admin redirects/captured tables only ever render bulk-select
     * checkboxes for rows on the CURRENT page (see
     * includes/html/tableRowPageRedirects.html and
     * tableRowCapturedURLs.html) -- there is no "select all across pages"
     * feature -- so the largest legitimate submission is bounded by the
     * table's own page-size cap (ABJ404_OPTION_MAX_PERPAGE = 500, defined
     * in includes/Loader.php). This constant sits well above that (2x
     * headroom) so a legitimate max-page-size selection always passes,
     * while a forged or accidental oversized POST (e.g. 100,000 ids)
     * cannot monopolize PHP execution time or hammer the database with
     * that many mutation queries in a single request.
     */
    const MAX_BULK_ACTION_IDS = 1000;

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_bulkProcess';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $rawIds = $_POST['idnum'] ?? null;
        if (!is_array($rawIds)) {
            $this->parent->getLogger()->debugMessage(
                "No ID(s) specified for bulk action: " . esc_html($action));
            echo sprintf(__("Error: No ID(s) specified for bulk action. (%s)", '404-solution'),
                esc_html($action));
            return '';
        }
        $requestedCount = count($rawIds);
        if ($requestedCount > self::MAX_BULK_ACTION_IDS) {
            $this->parent->getLogger()->debugMessage(
                "Too many ID(s) specified for bulk action (" . $requestedCount .
                ", max " . self::MAX_BULK_ACTION_IDS . "): " . esc_html($action));
            echo sprintf(
                __("Error: Too many ID(s) specified for bulk action (maximum %d). (%s)", '404-solution'),
                self::MAX_BULK_ACTION_IDS, esc_html($action));
            return '';
        }
        $ids = array();
        foreach ($rawIds as $rawId) {
            if (is_scalar($rawId)) {
                $id = ABJ_404_Solution_ExactInteger::readOr($rawId, 1, 0);
                if ($id > 0) { $ids[] = $id; }
            }
        }
        $message = $this->doBulkAction($action, $ids);
        return $message;
    }

    /**
     * Iterate the given ids and apply the per-row operation implied by the
     * bulk action verb. Returns a human-readable summary. Public so the
     * legacy PluginLogicAdminActions::doBulkAction() shim and existing tests
     * can call it directly without going through handle()'s POST gate.
     *
     * @param string $action
     * @param array<int, int> $ids
     * @return string
     */
    public function doBulkAction(string $action, array $ids): string {
        $message = "";
        $logger = $this->parent->getLogger();
        $redirectsRepo = $this->parent->getRedirectsRepo();

        $logger->debugMessage("In doBulkAction. Action: " .
                esc_html($action == '' ? '(none)' : $action) . ", ids: " . wp_kses_post((string)json_encode($ids)));

        if ($this->isStatusOrRestoreAction($action)) {
            $status = $this->statusForBulkAction($action);
            $count = 0;
            $cycleIssues = array();
            foreach ($ids as $id) {
                $s = $this->restoreAndSetStatus(array('id' => $id, 'status' => $status));
                if ($s == "") {
                    $count++;
                } else {
                    $cycleIssues[] = $s;
                }
            }
            $message = $this->bulkStatusMessage($action, $count);
            if (!empty($cycleIssues)) {
                $message .= ' ' . implode(' | ', array_map('esc_html', $cycleIssues));
            }

        } else if ($action == "bulk_trash_delete_permanently") {
            $count = 0;
            foreach ($ids as $id) {
                $redirectsRepo->deleteRedirect(absint($id));
                $count ++;
            }
            $message = $count . " " . __('URL(s) deleted', '404-solution');

        } else if ($action == "bulktrash") {
            $count = 0;
            foreach ($ids as $id) {
                $s = $redirectsRepo->moveRedirectsToTrash($id, 1);
                if ($s == "") {
                    $count ++;
                }
            }
            $message = $count . " " . __('URL(s) moved to trash', '404-solution');

        } else {
            $logger->errorMessage("Unrecognized bulk action: " . esc_html($action));
            echo sprintf(__("Error: Unrecognized bulk action. (%s)", '404-solution'), esc_html($action));
        }
        return $message;
    }

    private function isStatusOrRestoreAction(string $action): bool {
        return in_array($action, array(
            'bulkignore', 'bulkcaptured', 'bulklater', 'bulk_trash_restore'
        ), true);
    }

    private function statusForBulkAction(string $action): ?int {
        $statuses = array(
            'bulkignore' => ABJ404_STATUS_IGNORED,
            'bulkcaptured' => ABJ404_STATUS_CAPTURED,
            'bulklater' => ABJ404_STATUS_LATER,
        );
        return isset($statuses[$action]) ? $statuses[$action] : null;
    }

    private function bulkStatusMessage(string $action, int $count): string {
        $labels = array(
            'bulkignore' => __('URL(s) marked as Ignored.', '404-solution'),
            'bulkcaptured' => __('URL(s) marked as Captured.', '404-solution'),
            'bulklater' => __('URL(s) marked as Later.', '404-solution'),
            'bulk_trash_restore' => __('URL(s) restored.', '404-solution'),
        );
        return $count . ' ' . $labels[$action];
    }

    /**
     * Admit the final status and untrash together. Captured or Ignored is
     * non-redirecting, so no intermediate enabled redirect is needed. A pure
     * restore preserves the stored status and its cycle admission.
     *
     * @param array{id: int, status: int|null} $request
     * @return string
     */
    private function restoreAndSetStatus(array $request): string {
        $id = ABJ_404_Solution_ExactInteger::readOr($request['id'], 1, 0);
        $status = $request['status'];
        $repository = $this->parent->getRedirectsRepo();
        // Custom implementations of the legacy interface can omit this additive
        // capability. Refuse safely; two legacy writes would reintroduce partial state.
        if (!method_exists($repository, 'restoreRedirect') || !is_callable(array($repository, 'restoreRedirect'))) {
            return 'Atomic redirect restore is unavailable for this repository.';
        }
        try {
            $message = $repository->restoreRedirect(array('id' => $id, 'status' => $status));
            return is_string($message) ? $message : 'Atomic redirect restore returned an invalid response (' . gettype($message) . ').';
        } catch (ABJ_404_Solution_RedirectCycleException $e) {
            return $e->getMessage();
        }
    }
}
