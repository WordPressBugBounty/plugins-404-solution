<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents advanced and general settings section templates.
 */
class ABJ_404_Solution_View_SettingsSections extends ABJ_404_Solution_ViewComponent {

    /**
     * Turn a `name => value` map into the `{name} => value` map the template binders take.
     *
     * @param array<string,string> $vars
     * @return array<string,string>
     */
    private function braceKeys(array $vars): array {
        $bound = array();
        foreach ($vars as $key => $value) {
            $bound['{' . $key . '}'] = (string)$value;
        }
        return $bound;
    }

    /**
     * Bind values into a template that carries no `{msgid}` tokens (its braces may be CSS), in one
     * pass so a bound value is never rescanned as a placeholder. Use renderTemplate for a template
     * whose `{msgid}` tokens must be translated.
     *
     * @param array<string,string> $vars
     */
    private function fillSettingsTemplate(string $templateName, array $vars): string {
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $templateName);
        return strtr((string)$html, $this->braceKeys($vars));
    }

    /**
     * Constants and `{msgid}` translations run over the TEMPLATE; every value is bound last.
     *
     * @param array<string,string> $vars
     */
    private function renderSettingsTemplate(string $templateName, array $vars): string {
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $templateName);
        return $this->f->renderTemplate((string)$html, $this->braceKeys($vars));
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAutoRedirects(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);

        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = $this->f->renderTemplate(
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsDefault404Destination.html"),
            array(
                '{default_404_destination_label}' => esc_html__('Default 404 destination', '404-solution'),
                '{behavior_tiles_html}' => $this->optionsPresenter->getBehaviorTilesHTML($options),
            )
        );

        $selectedAutoRedirects = $this->optionsPresenter->getCheckedAttr($options, 'auto_redirects');
        $selectedAutoSlugs = $this->optionsPresenter->getCheckedAttr($options, 'auto_slugs');
        $selectedAutoCats = $this->optionsPresenter->getCheckedAttr($options, 'auto_cats');
        $selectedAutoTags = $this->optionsPresenter->getCheckedAttr($options, 'auto_tags');
        $selectedAutoTrashRedirect = $this->optionsPresenter->getCheckedAttr($options, 'auto_trash_redirect');

        $html = $this->f->renderTemplate(
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsAutoRedirects.html"),
            array(
                '{selectedAutoRedirects}' => $selectedAutoRedirects,
                '{selectedAutoSlugs}' => $selectedAutoSlugs,
                '{selectedAutoCats}' => $selectedAutoCats,
                '{selectedAutoTags}' => $selectedAutoTags,
                '{selectedAutoTrashRedirect}' => $selectedAutoTrashRedirect,
                '{auto_deletion}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_deletion')),
                '{auto_302_expiration_days}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_302_expiration_days')),
                '{spaces}' => $spaces,
            )
        );
        $content .= $html;

        return $content;
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedContent(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $allPostTypesTemp = $this->viewReadService->getAllPostTypes();
        $allPostTypes = esc_html(implode(', ', $allPostTypesTemp));

        // Every stored option below is admin-authored text that may itself contain `{...}`; the token
        // passes run over the template and these values are bound last, in one pass.
        return $this->f->renderTemplate(
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedContent.html"),
            array(
                '{recognized_post_types}' =>
                    str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'recognized_post_types'))),
                '{all_post_types}' => $allPostTypes,
                '{recognized_categories}' =>
                    str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'recognized_categories'))),
                '{folders_files_ignore}' =>
                    str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'folders_files_ignore'))),
                '{suggest_regex_exclusions}' =>
                    str_replace('\\n', "\n", esc_textarea($this->optionsPresenter->optStr($options, 'suggest_regex_exclusions'))),
                '{add-exclude-page-data-url}' =>
                    "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=false&includeSpecial=false&nonce=" . wp_create_nonce('abj404_ajax'),
                '{TOOLTIP_POPUP_EXPLANATION_EMPTY}' => __('(Type a page name)', '404-solution'),
                '{TOOLTIP_POPUP_EXPLANATION_PAGE}' => __('(A page has been selected.)', '404-solution'),
                '{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}' => __('(A custom string has been entered.)', '404-solution'),
                '{TOOLTIP_POPUP_EXPLANATION_URL}' => __('(An external URL will be used.)', '404-solution'),
                '{loaded-excluded-pages}' => urlencode($this->optionsPresenter->optStr($options, 'excludePages[]')),
            )
        );
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedLogging(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $selectedLogRawIPs = $this->optionsPresenter->getCheckedAttr($options, 'log_raw_ips');
        $selectedDebugLogging = $this->optionsPresenter->getCheckedAttr($options, 'debug_mode');

        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = '?page=' . ABJ404_PP . '&subpage=abj404_debugfile';
        $debugExplanation = $this->f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $this->logger->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        $debugFileSize = sprintf(__('Debug file size: %1$s KB (%2$s MB).', '404-solution'),
                $kbFileSizePretty, $mbFileSizePretty);

        return $this->f->renderTemplate(
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedLogging.html"),
            array(
                'checked="log_raw_ips"' => $selectedLogRawIPs,
                'checked="debug_mode"' => $selectedDebugLogging,
                '{<a>View</a> the debug file.}' => $debugExplanation,
                '{Debug file size: %s KB.}' => $debugFileSize,
                '{ignore_dontprocess}' =>
                    str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'ignore_dontprocess'))),
                '{ignore_doprocess}' =>
                    str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'ignore_doprocess'))),
            )
        );
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedSystem(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $selectedRedirectAllRequests = $this->optionsPresenter->getCheckedAttr($options, 'redirect_all_requests');

        $hideRedirectAllRequests = "false";
        if (array_key_exists('disallow-redirect-all-requests', $options)
                && $options['disallow-redirect-all-requests'] == '1') {
            $hideRedirectAllRequests = "true";
        }

        $pluginAdminUsersRaw2 = $options['plugin_admin_users'];
        if (is_array($pluginAdminUsersRaw2)) {
            $pluginAdminUsers = implode("\n", $pluginAdminUsersRaw2);
        } else {
            $pluginAdminUsers = is_string($pluginAdminUsersRaw2) ? $pluginAdminUsersRaw2 : '';
        }
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($pluginAdminUsers));

        return $this->f->renderTemplate(
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedSystem.html"),
            array(
                '{DATABASE_VERSION}' => esc_html($this->optionsPresenter->optStr($options, 'DB_VERSION')),
                'checked="redirect_all_requests"' => $selectedRedirectAllRequests,
                '{disallow-redirect-all-requests}' => $hideRedirectAllRequests,
                '{OPTION_MIN_AUTO_SCORE}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_score')),
                '{OPTION_AUTO_SCORE_TITLE}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_score_title')),
                '{OPTION_AUTO_SCORE_CATEGORY_TAG}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_score_category_tag')),
                '{OPTION_AUTO_SCORE_CONTENT}' => esc_attr($this->optionsPresenter->optStr($options, 'auto_score_content')),
                '{OPTION_TEMPLATE_REDIRECT_PRIORITY}' => esc_attr($this->optionsPresenter->optStr($options, 'template_redirect_priority')),
                '{days_wait_before_major_update}' => esc_attr($this->optionsPresenter->optStr($options, 'days_wait_before_major_update')),
                '{plugin_admin_users}' => wp_kses_post($pluginAdminUsers),
            )
        );
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageGeneralSettings(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);

        $viewData = array_merge(
            $this->generalSettingsFieldState($options),
            $this->generalSettingsRuntimeDisplayData(),
            array(
                'admin_notification' => esc_attr($this->optionsPresenter->optStr($options, 'admin_notification')),
                'capture_deletion' => esc_attr($this->optionsPresenter->optStr($options, 'capture_deletion')),
                'manual_deletion' => esc_attr($this->optionsPresenter->optStr($options, 'manual_deletion')),
                'maximum_log_disk_usage' => esc_attr($this->optionsPresenter->optStr($options, 'maximum_log_disk_usage')),
                'admin_notification_email' => esc_attr($this->optionsPresenter->optStr($options, 'admin_notification_email')),
                'PHP_VERSION' => PHP_VERSION,
            )
        );

        return $this->renderSettingsTemplate('adminOptionsGeneral.html', $viewData);
    }

    /**
     * Build selected and checked attributes for the general settings template.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function generalSettingsFieldState(array $options): array {
        $viewData = array(
            'selectedDefaultRedirect301' => ($options['default_redirect'] == '301') ? ' selected' : '',
            'selectedDefaultRedirect302' => ($options['default_redirect'] == '302') ? ' selected' : '',
            'selectedDefaultRedirect307' => ($options['default_redirect'] == '307') ? ' selected' : '',
            'selectedDefaultRedirect308' => ($options['default_redirect'] == '308') ? ' selected' : '',
            'selectedCapture404' => $this->optionsPresenter->getCheckedAttr($options, 'capture_404'),
            'selectedSendErrorLogs' => $this->optionsPresenter->getCheckedAttr($options, 'send_error_logs'),
            'selectedRemoveMatches' => $this->optionsPresenter->getCheckedAttr($options, 'remove_matches'),
            'selectedAutoTrashJunk' => $this->optionsPresenter->getCheckedAttr($options, 'auto_trash_junk_urls'),
            'selectedUnderSettings' => ($options['menuLocation'] == 'settingsLevel') ? '' : ' selected',
            'selecteSsettingsLevel' => ($options['menuLocation'] == 'settingsLevel') ? ' selected' : '',
            'theme_default' => __('Default', '404-solution'),
            'disableAutoDarkModeChecked' => isset($options['disable_auto_dark_mode']) && $options['disable_auto_dark_mode'] == '1'
                ? ' checked'
                : '',
        );

        $adminTheme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';
        foreach (array(
            'selectedThemeDefault' => 'default',
            'selectedThemeCalm' => 'calm',
            'selectedThemeMono' => 'mono',
            'selectedThemeNeon' => 'neon',
            'selectedThemeObsidian' => 'obsidian',
        ) as $placeholder => $theme) {
            $viewData[$placeholder] = ($adminTheme == $theme) ? ' selected' : '';
        }

        $pluginLanguage = isset($options['plugin_language_override']) ? $options['plugin_language_override'] : '';
        foreach (array(
            'selectedLanguageDefault' => '',
            'selectedLanguageEnUS' => 'en_US',
            'selectedLanguageDeDE' => 'de_DE',
            'selectedLanguageEsES' => 'es_ES',
            'selectedLanguageFrFR' => 'fr_FR',
            'selectedLanguageItIT' => 'it_IT',
            'selectedLanguagePtBR' => 'pt_BR',
            'selectedLanguageNlNL' => 'nl_NL',
            'selectedLanguageRuRU' => 'ru_RU',
            'selectedLanguageJa' => 'ja',
            'selectedLanguageZhCN' => 'zh_CN',
            'selectedLanguageIdID' => 'id_ID',
            'selectedLanguageSvSE' => 'sv_SE',
        ) as $placeholder => $language) {
            $viewData[$placeholder] = ($pluginLanguage == $language) ? ' selected' : '';
        }

        $notifyFrequencyRaw = $options['admin_notification_frequency'] ?? 'instant';
        $notifyFrequency = is_scalar($notifyFrequencyRaw) ? (string)$notifyFrequencyRaw : 'instant';
        foreach (array(
            'selectedNotifyInstant' => 'instant',
            'selectedNotifyDaily' => 'daily',
            'selectedNotifyWeekly' => 'weekly',
            'selectedNotifyNever' => 'never',
        ) as $placeholder => $frequency) {
            $viewData[$placeholder] = ($notifyFrequency === $frequency) ? ' selected' : '';
        }

        return $viewData;
    }

    /** @return array<string, string> */
    private function generalSettingsRuntimeDisplayData(): array {
        $timeToDisplay = $this->statsRepository->getEarliestLogTimestamp();
        $earliestLogDate = $timeToDisplay >= 0
            ? date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . date('A', $timeToDisplay)
            : 'N/A';
        $adminEmail = get_option('admin_email');

        return array(
            'logCurrentSizeDiskUsage' => (string)round($this->viewReadService->getLogDiskUsage() / (1024 * 1000), 2),
            'logCurrentRowCount' => (string)$this->viewReadService->getLogsCount(0),
            'earliestLogDate' => $earliestLogDate,
            'default_wordpress_admin_email' => is_string($adminEmail) ? $adminEmail : '',
        );
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageDiagnosticData(array $options): string {
        if (!class_exists('ABJ_404_Solution_FeedbackSiteTokenStore')) {
            require_once dirname(__DIR__) . '/feedback/FeedbackSiteTokenStore.php';
        }
        if (!class_exists('ABJ_404_Solution_Ajax_PrivacyExport')) {
            require_once dirname(__DIR__) . '/ajax/Ajax_PrivacyExport.php';
        }
        if (!class_exists('ABJ_404_Solution_Ajax_PrivacyDelete')) {
            require_once dirname(__DIR__) . '/ajax/Ajax_PrivacyDelete.php';
        }

        $rawToken = get_option(ABJ_404_Solution_FeedbackSiteTokenStore::TOKEN_OPTION, '');
        $hasToken = is_string($rawToken) && $rawToken !== '';
        $exportNonce = wp_create_nonce(ABJ_404_Solution_Ajax_PrivacyExport::NONCE_ACTION);
        $deleteNonce = wp_create_nonce(ABJ_404_Solution_Ajax_PrivacyDelete::NONCE_ACTION);

        if (!$hasToken) {
            $body = $this->fillSettingsTemplate('viewSettingsDiagnosticDataNoToken.html', array(
                'emptyDescription' => esc_html__('Diagnostic reporting has never been enabled for this site, so there is nothing stored on the developer\'s server for you to download or delete.', '404-solution'),
            ));
        } else {
            $sendErrorLogs = $options['send_error_logs'] ?? '0';
            $enabled = $sendErrorLogs === '1' || $sendErrorLogs === 1 || $sendErrorLogs === true;
            $state = $enabled ? esc_html__('enabled', '404-solution') : esc_html__('disabled', '404-solution');
            $description = sprintf(
                esc_html__('This site has diagnostic reporting %s. You can download everything stored on the developer\'s server for this site, or permanently delete it.', '404-solution'),
                $state
            );
            $body = $this->fillSettingsTemplate('viewSettingsDiagnosticDataActions.html', array(
                'description'      => $description,
                'exportNonce'      => esc_attr($exportNonce),
                'deleteNonce'      => esc_attr($deleteNonce),
                'downloadLabel'    => esc_html__('Download my data', '404-solution'),
                'deleteLabel'      => esc_html__('Delete my data', '404-solution'),
                'emptyAfterDelete' => esc_html__('Nothing currently stored.', '404-solution'),
                'modalTitle'       => esc_html__('Delete your diagnostic data?', '404-solution'),
                'modalBody'        => esc_html__('This permanently deletes every diagnostic report this site has ever sent. This cannot be undone. Your redirects and settings are not affected -- only diagnostic/telemetry history is removed.', '404-solution'),
                'cancelLabel'      => esc_html__('Cancel', '404-solution'),
                'confirmLabel'     => esc_html__('Yes, delete permanently', '404-solution'),
            ));
        }

        return $this->fillSettingsTemplate('viewSettingsDiagnosticDataSection.html', array(
            'downloadDate' => esc_attr(gmdate('Y-m-d', abj_clock()->now())),
            'exportNonce'  => esc_attr($exportNonce),
            'deleteNonce'  => esc_attr($deleteNonce),
            'hasToken'     => $hasToken ? '1' : '0',
            'body'         => $body,
        ));
    }
}
