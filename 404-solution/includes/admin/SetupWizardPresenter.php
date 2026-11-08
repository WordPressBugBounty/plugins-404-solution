<?php

// allow-no-test-found: covered by tests/SetupWizardTest.php through setup wizard admin render entry points.

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/core/SetupWizard.php';

/**
 * Renders setup wizard admin presentation assets.
 */
class ABJ_404_Solution_SetupWizardPresenter {

    /**
     * Output modal CSS styles.
     *
     * @return void
     */
    public static function outputStyles(): void {
        $cssPath = self::filteredAssetPath(
            'abj404_setup_wizard_stylesheet_path',
            dirname(__DIR__) . '/html/setupWizardStyles.css'
        );
        $wrapperPath = self::filteredAssetPath(
            'abj404_setup_wizard_styles_template_path',
            dirname(__DIR__) . '/html/setupWizardStyles.html'
        );

        echo self::fillTpl($wrapperPath, 'setup wizard style wrapper', array(
            'css' => self::readSetupWizardAsset($cssPath, 'setup wizard stylesheet'),
        ));
    }

    /**
     * Output the modal HTML structure.
     *
     * @return void
     */
    public static function outputModalHTML(): void {
        $templatePath = self::filteredAssetPath(
            'abj404_setup_wizard_modal_template_path',
            dirname(__DIR__) . '/html/setupWizardModal.html'
        );
        $answers = ABJ_404_Solution_SetupWizardAnswerPolicy::defaultAnswers();
        $alertsAvailable = ABJ_404_Solution_SetupWizardAnswerPolicy::captures404s($answers);
        $adminEmail = ABJ_404_Solution_SetupWizardOptionStore::adminEmail();

        echo self::fillTpl($templatePath, 'setup wizard modal template', array(
            'nonce_field' => self::renderNonceField(),
            'welcome_heading' => esc_html__('Welcome to 404 Solution', '404-solution'),
            'intro' => esc_html__('Choose how missing pages are handled. You can change these settings later in Options.', '404-solution'),
            'q1_heading' => esc_html__('When a page is not found, what should happen?', '404-solution'),
            'q1_redirect_checked' => self::checkedAttribute($answers['q1'], 'redirect'),
            'q1_redirect_label' => esc_html__('Redirect to the closest match (recommended)', '404-solution'),
            'q1_redirect_desc' => esc_html__('When no close match is found, show a 404 page that suggests similar pages.', '404-solution'),
            'q1_default_checked' => self::checkedAttribute($answers['q1'], 'default'),
            'q1_default_label' => esc_html__('Show a 404 page that suggests similar pages', '404-solution'),
            'q1_default_desc' => esc_html__('Visitors are never redirected automatically. Redirects you create yourself still work.', '404-solution'),
            'q2_heading' => esc_html__('Log 404 errors for review?', '404-solution'),
            'q2_yes_checked' => self::checkedAttribute($answers['q2'], 'yes'),
            'q2_yes_label' => esc_html__('Yes, log 404 errors', '404-solution'),
            'q2_yes_desc' => esc_html__('Missing URLs are listed under Captured 404s so you can create redirects for them.', '404-solution'),
            'q2_no_checked' => self::checkedAttribute($answers['q2'], 'no'),
            'q2_no_label' => esc_html__("No, don't log 404s", '404-solution'),
            'q2_no_desc' => esc_html__('Missing URLs are not recorded. This does not change how visitors are redirected.', '404-solution'),
            'q3_heading' => esc_html__('Email me about missing pages?', '404-solution'),
            'q3_group_disabled' => $alertsAvailable ? '' : ' disabled',
            'q3_yes_checked' => self::checkedAttribute($answers['q3'], 'yes'),
            'q3_yes_label' => esc_html(sprintf(
                /* translators: %d is the number of captured 404 URLs that triggers an email. */
                __('Yes, email me when %d or more 404s have been captured (recommended)', '404-solution'),
                ABJ_404_Solution_SetupWizardAnswerPolicy::ALERT_THRESHOLD
            )),
            'q3_yes_desc' => esc_html($adminEmail === ''
                ? __("Checked weekly. The email is sent to the site administrator.", '404-solution')
                : sprintf(
                    /* translators: %s is the site administrator's email address. */
                    __('Checked weekly. The email is sent to %s.', '404-solution'),
                    $adminEmail
                )),
            'q3_no_checked' => self::checkedAttribute($answers['q3'], 'no'),
            'q3_no_label' => esc_html__("No, I'll check manually", '404-solution'),
            'q3_no_desc' => esc_html__('You can always enable email alerts later in Options', '404-solution'),
            'q3_unavailable_hidden' => $alertsAvailable ? ' hidden' : '',
            'q3_unavailable_note' => esc_html__('Email alerts need the list of missing pages, so they are off while logging is off.', '404-solution'),
            'skip_label' => esc_html__('Skip Setup', '404-solution'),
            'save_label' => esc_html__('Save & Get Started', '404-solution'),
        ));
    }

    /**
     * Render a template with string placeholders.
     *
     * @param string $path Absolute template path.
     * @param string $assetLabel Human-readable asset label for diagnostics.
     * @param array<string,string> $vars Escaped placeholder values.
     * @return string Rendered template.
     */
    private static function fillTpl(string $path, string $assetLabel, array $vars): string {
        $template = self::readSetupWizardAsset($path, $assetLabel);
        $replacements = array();
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Read a setup wizard asset and preserve the failed path in diagnostics.
     *
     * @param string $path Absolute asset path.
     * @param string $assetLabel Human-readable asset label.
     * @return string Asset contents.
     */
    private static function readSetupWizardAsset(string $path, string $assetLabel): string {
        try {
            return ABJ_404_Solution_FileSystemService::readFileContents($path, false);
        } catch (Throwable $e) {
            throw new ABJ_404_Solution_SetupWizardAssetException(
                'Could not load ' . $assetLabel . ' from ' . $path . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Apply a string-valued asset path filter.
     *
     * @param non-empty-string $hook Filter hook name.
     * @param string $defaultPath Default absolute path.
     * @return string Filtered path when valid, otherwise the default.
     */
    private static function filteredAssetPath(string $hook, string $defaultPath): string {
        if (!function_exists('apply_filters')) {
            return $defaultPath;
        }

        $filtered = apply_filters($hook, $defaultPath);
        if (!is_string($filtered) || $filtered === '') {
            return $defaultPath;
        }

        return $filtered;
    }

    /**
     * Return a leading-space checked attribute for selected radio options.
     *
     * @param string $current Current option value.
     * @param string $candidate Candidate option value.
     * @return string Attribute fragment.
     */
    private static function checkedAttribute(string $current, string $candidate): string {
        return $current === $candidate ? ' checked' : '';
    }

    /**
     * Capture WordPress nonce field output so it can be inserted into the template.
     *
     * @return string Nonce input HTML.
     */
    private static function renderNonceField(): string {
        ob_start();
        wp_nonce_field('abj404_setup_wizard', 'abj404_setup_wizard_nonce');
        return (string)ob_get_clean();
    }

    /**
     * Output JavaScript for dismiss and save functionality.
     *
     * @return void
     */
    public static function outputScript(): void {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/setupWizardScript.html',
            false
        );
        echo str_replace(
            array(
                '{missing_nonce_message}',
                '{dismissal_failed_message}',
                '{dismissal_prefix}',
                '{dismissal_suffix}',
                '{network_error_message}',
                '{close_label}',
                '{saving_label}',
            ),
            array(
                self::jsonStringLiteral(__('Could not save settings - missing security token. The wizard may appear again on next visit.', '404-solution')),
                self::jsonStringLiteral(__('Could not save dismissal. The wizard may appear again on next visit.', '404-solution')),
                self::jsonStringLiteral(__('Could not save dismissal: ', '404-solution')),
                self::jsonStringLiteral(__(' The wizard may appear again on next visit.', '404-solution')),
                self::jsonStringLiteral(__('Network error - could not save dismissal. The wizard may appear again on next visit.', '404-solution')),
                self::jsonStringLiteral(__('Close', '404-solution')),
                self::jsonStringLiteral(__('Saving...', '404-solution')),
            ),
            $template
        );
    }

    /**
     * @param string $text
     * @return string
     */
    private static function jsonStringLiteral(string $text): string {
        $encoded = wp_json_encode($text);
        return is_string($encoded) ? $encoded : '""';
    }
}
