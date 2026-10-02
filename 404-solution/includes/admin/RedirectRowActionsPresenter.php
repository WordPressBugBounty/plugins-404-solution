<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the edit, logs, trash, restore, and delete actions for a redirect row.
 */
class ABJ_404_Solution_RedirectRowActionsPresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_View_Shared */
    private $shared;

    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_View_Shared $shared) {
        $this->functions = $functions;
        $this->shared = $shared;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tableOptions
     * @return array{
     *   edit: string,
     *   logs: string,
     *   trash: string,
     *   delete: string
     * }
     */
    public function render(array $row, string $sub, array $tableOptions): array {
        $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, false);
        $links = array(
            'logslink' => $links['logslink'],
            'trashlink' => $links['trashlink'],
            'ajaxTrashLink' => $links['ajaxTrashLink'],
            'trashtitle' => $links['trashtitle'],
            'deletelink' => $links['deletelink'],
            'editlink' => $links['editlink'],
        );
        $currentFilter = $tableOptions['filter'] ?? 0;
        $logsId = is_scalar($row['logsid'] ?? 0) ? (int)($row['logsid'] ?? 0) : 0;

        return array(
            'edit' => $this->editActionLink($currentFilter, $links),
            'logs' => $this->logsActionLink($logsId, $links),
            'trash' => $this->trashActionLink($currentFilter, $links),
            'delete' => $this->deleteActionLink($currentFilter, $links),
        );
    }

    /**
     * @param mixed $currentFilter
     * @param array<string, string> $links
     */
    private function editActionLink($currentFilter, array $links): string {
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLink.html', array(
            'href' => esc_url($links['editlink']),
            'class' => 'abj404-action-link',
            'title' => __('Edit Redirect Details', '404-solution'),
            'svg_path' => $this->tpl('viewRedirectsTableSvgEdit.html'),
            'label' => __('Edit', '404-solution'),
        ));
    }

    /** @param array<string, string> $links */
    private function logsActionLink(int $logsId, array $links): string {
        if ($logsId <= 0) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLink.html', array(
            'href' => $links['logslink'],
            'class' => 'abj404-action-link',
            'title' => __('View Redirect Logs', '404-solution'),
            'svg_path' => $this->tpl('viewRedirectsTableSvgLogs.html'),
            'label' => __('Logs', '404-solution'),
        ));
    }

    /**
     * @param mixed $currentFilter
     * @param array<string, string> $links
     */
    private function trashActionLink($currentFilter, array $links): string {
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            return $this->actionLink('viewRedirectsTableActionLink.html', array(
                'href' => $links['trashlink'],
                'class' => 'abj404-action-link',
                'title' => __('Restore', '404-solution'),
                'svg_path' => $this->tpl('viewRedirectsTableSvgRestore.html'),
                'label' => __('Restore', '404-solution'),
            ));
        }

        return $this->actionLink('viewRedirectsTableActionLinkAjax.html', array(
            'data_url' => $links['ajaxTrashLink'],
            'class' => 'abj404-action-link danger ajax-trash-link',
            'title' => __('Trash Redirected URL', '404-solution'),
            'svg_path' => $this->tpl('viewRedirectsTableSvgTrash.html'),
            'label' => __('Trash', '404-solution'),
        ));
    }

    /**
     * @param mixed $currentFilter
     * @param array<string, string> $links
     */
    private function deleteActionLink($currentFilter, array $links): string {
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLinkSeparated.html', array(
            'href' => $links['deletelink'],
            'class' => 'abj404-action-link danger',
            'title' => __('Delete Redirect Permanently', '404-solution'),
            'svg_path' => $this->tpl('viewRedirectsTableSvgTrash.html'),
            'label' => __('Delete', '404-solution'),
        ));
    }

    /**
     * Renders one fully finished action link. The href, title and label are bound as values in a
     * single pass, so nothing in them (a URL query value included) is ever scanned for `{msgid}`
     * tokens again.
     *
     * @param array<string, string> $vars
     */
    private function actionLink(string $tplName, array $vars): string {
        $replacements = array();
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }
        return $this->functions->renderTemplate($this->tpl($tplName), $replacements);
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
