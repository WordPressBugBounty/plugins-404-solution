<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standalone Add/Edit redirect form rendering. Owns the legacy
 * "Add Manual Redirect" form, the Edit Redirect form (active-from/until +
 * conditions + cancel/submit row), and the default destination <optgroup>
 * shared by the redirect-to dropdowns. The modern Add Redirect modal lives
 * on View_RedirectsTable (it is rendered as part of the Redirects page).
 *
 * Outside callers (via the View facade __call dispatch):
 *   - PluginLogic admin edit flow (echoEditRedirect)
 *   - Legacy add-redirect paths (echoAddManualRedirect)
 *   - Redirect-to dropdown builders (echoRedirectDestinationOptionsDefaults)
 */
class ABJ_404_Solution_View_RedirectForms extends ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_RedirectEditFormPresenter|null */
    private $editFormPresenter = null;

    /**
     * The builder that owns the shape of this screen's own links.
     *
     * @return ABJ_404_Solution_RedirectEditFormPresenter
     */
    private function editFormPresenter(): ABJ_404_Solution_RedirectEditFormPresenter {
        if ($this->editFormPresenter === null) {
            $this->editFormPresenter = new ABJ_404_Solution_RedirectEditFormPresenter(
                $this->f, new ABJ_404_Solution_RedirectEngineLabeler());
        }
        return $this->editFormPresenter;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * Single-pass render: a value bound into one slot is never rescanned by a later key.
     *
     * @param array<string,string> $vars
     */
    private function fillTpl(string $name, array $vars): string {
        return $this->f->renderTemplate($this->tpl($name), $vars);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    public function echoAddManualRedirect($tableOptions) {

        $options = $this->optionsPresenter->getOptionsWithDefaults();

        $url = "?page=" . ABJ404_PP . "&subpage=abj404_redirects";
        $orderby = array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        if (!($orderby == "url" && $order == "ASC")) {
            $url .= "&orderby=" . sanitize_text_field($orderby) . "&order=" . sanitize_text_field($order);
        }
        $filter = array_key_exists('filter', $tableOptions) && is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        $link = wp_nonce_url($url, "abj404addRedirect");

        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";
        if (isset($_POST['url']) && $_POST['url'] != '') {
            $postedURL = esc_url(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['url']));
        } else {
            $postedURL = $urlPlaceholder;
        }

        $selected301 = ($options['default_redirect'] == '301') ? ' selected ' : '';
        $selected302 = ($options['default_redirect'] == '302') ? ' selected ' : '';
        $selected307 = ($options['default_redirect'] == '307') ? ' selected ' : '';
        $selected308 = ($options['default_redirect'] == '308') ? ' selected ' : '';
        $selected410 = '';
        $selected451 = '';
        $selected0 = '';

        // read the html content.
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/addManualRedirectTop.html");
        $html .= ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) .
                "/html/addManualRedirectPageSearchDropdown.html");
        $html .= ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/addManualRedirectBottom.html");

        // Constants and `{msgid}` translations run over the TEMPLATE; every value below (the posted
        // URL included) is bound last, in one pass, so a value is never scanned as a token.
        echo $this->f->renderTemplate($html, array(
            '{redirect_to_label}' => __('Redirect to', '404-solution'),
            '{TOOLTIP_POPUP_EXPLANATION_EMPTY}' => __('(Type a page name or an external URL)', '404-solution'),
            '{TOOLTIP_POPUP_EXPLANATION_PAGE}' => __('(A page has been selected.)', '404-solution'),
            '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}' => __('(A custom string has been entered.)', '404-solution'),
            '{TOOLTIP_POPUP_EXPLANATION_URL}' => __('(An external URL will be used.)', '404-solution'),
            '{REDIRECT_TO_USER_FIELD_WARNING}' => '',
            '{redirectPageTitle}' => '',
            '{pageIDAndType}' => '',
            '{data-url}' => "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'),
            '{addManualRedirectAction}' => $link,
            '{urlPlaceholder}' => esc_attr($urlPlaceholder),
            '{postedURL}' => esc_attr($postedURL),
            '{301selected}' => $selected301,
            '{302selected}' => $selected302,
            '{307selected}' => $selected307,
            '{308selected}' => $selected308,
            '{410selected}' => $selected410,
            '{451selected}' => $selected451,
            '{0selected}' => $selected0,
        ));
    }

    /** This is used both to add and to edit a redirect.
     * @param ABJ_404_Solution_EditRedirectFormContext $ctx
     * @return void
     */
    public function echoEditRedirect(ABJ_404_Solution_EditRedirectFormContext $ctx) {
        $codeselected = $ctx->codeSelected;
        $label = $ctx->label;
        $source_page = $ctx->sourcePage;
        $filter = $ctx->filter;
        $orderby = $ctx->orderby;
        $order = $ctx->order;
        $startDate = $ctx->startDate;
        $endDate = $ctx->endDate;
        // allow-em-dash: comment-only context describing button grid section
        // Redirect type button grid with hidden input
        $this->redirectTypeUI->echoRedirectTypeButtonGrid((string)$codeselected);

        // Advanced Options: Active From/Until + Conditions (collapsed by default)
        $redirectId = 0;
        $getRedirectId = $_GET['id'] ?? null;
        $postRedirectId = $_POST['id'] ?? null;
        if (is_scalar($getRedirectId) && $this->f->regexMatch('[0-9]+', (string)$getRedirectId)) {
            $redirectId = absint($getRedirectId);
        } elseif (is_scalar($postRedirectId) && $this->f->regexMatch('[0-9]+', (string)$postRedirectId)) {
            $redirectId = absint($postRedirectId);
        }
        $hasExistingConditions = ($redirectId > 0) && !empty($this->redirectsRepository->getRedirectConditions($redirectId));
        $hasAdvancedValues = ($startDate !== '' || $endDate !== '' || $hasExistingConditions);
        $openAttr = $hasAdvancedValues ? ' open' : '';

        ob_start();
        $this->redirectConditions->echoRedirectConditionsSection();
        $conditionsSection = (string)ob_get_clean();

        echo $this->fillTpl('viewRedirectsTableAdvancedOptions.html', array(
            '{open_attr}' => $openAttr,
            '{advanced_options_label}' => esc_html__('Advanced Options', '404-solution'),
            '{active_from_label}' => esc_html__('Active From (optional)', '404-solution'),
            '{start_date_value}' => esc_attr($startDate),
            '{active_from_help}' => esc_html__('Leave blank to activate immediately', '404-solution'),
            '{active_until_label}' => esc_html__('Active Until (optional)', '404-solution'),
            '{end_date_value}' => esc_attr($endDate),
            '{active_until_help}' => esc_html__('Leave blank to never expire', '404-solution'),
            '{conditions_section}' => $conditionsSection,
        ));

        // Cancel button URL, through the one builder that owns this link's
        // shape. This was a second copy that encoded the same four values a
        // different (and also wrong) way -- esc_attr here, nothing at all
        // there -- which is how the two came to disagree about whether an '&'
        // in a filter starts a new parameter.
        $cancelUrl = $this->editFormPresenter()->buildCancelUrl(
            (string)$source_page, (string)$filter, (string)$orderby, (string)$order);

        echo $this->f->str_replace(
            array('{cancel_url}', '{cancel_label}', '{submit_label}'),
            array(esc_url($cancelUrl), esc_html__('Cancel', '404-solution'), esc_html($label)),
            $this->tpl('viewRedirectsTableEditButtonGroup.html')
        );
    }

    /**
     * @param string $currentlySelected
     * @return string
     */
    public function echoRedirectDestinationOptionsDefaults($currentlySelected) {
        $content = "";
        $content .= "\n" . '<optgroup label="' . __('Special', '404-solution') . '">' . "\n";

        $selected = "";
        if ($currentlySelected == ABJ404_TYPE_EXTERNAL) {
            $selected = " selected";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL . "\"" . $selected . ">" .
                __('External Page', '404-solution') . "</option>";

        if ($currentlySelected == ABJ404_TYPE_HOME) {
            $selected = " selected";
        } else {
            $selected = "";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_HOME . "|" . ABJ404_TYPE_HOME . "\"" . $selected . ">" .
                __('Home Page', '404-solution') . "</option>";

        $content .= "\n" . '</optgroup>' . "\n";

        return $content;
    }
}
