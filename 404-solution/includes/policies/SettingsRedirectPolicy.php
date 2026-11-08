<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies redirect-destination settings from the settings form payload.
 *
 * Owns validation for redirect status codes, 404 behavior selection,
 * custom/legacy destination fields, and template_redirect hook priority.
 */
class ABJ_404_Solution_SettingsRedirectPolicy {

    /**
     * Apply the initial 404 destination for a fresh install or setup submission.
     * Page creation is optional: keep a working theme 404 when WordPress cannot
     * create the page, without producing a settings validation error.
     *
     * @param array<string, mixed> $options
     * @return void
     */
    public static function initializeSuggestionDestination(array &$options): void {
        // wp_insert_post() runs cache listeners before returning, including
        // during WP-CLI's plugin sandbox load before the activation hook.
        // Prepare their tables for every suggestion-initialization caller;
        // full activation/upgrade still owns schema convergence.
        abj_service('database_upgrades')->components()
            ->bootstrapUpgrade()->repairMissingTables();
        $policy = new self();
        $message = $policy->apply($options, array('dest404_behavior' => 'suggest'));
        if ($message !== '') {
            $policy->apply($options, array('dest404_behavior' => 'theme_default'));
            // Options initialization must use the inert sink, not the runtime
            // logger, which reads options itself and would re-enter initialization.
            abj404_logPhpFallback('early-boot',
                'Warning: Could not create the initial suggestion page; using theme_default.');
        }
    }

    /**
     * Derive the 404 behavior a stored destination implies: the read-side
     * inverse of applyBehaviorToDest404Page(). Used to backfill `dest404_behavior`
     * for settings written before that key existed, before defaults are merged
     * (a default would otherwise overwrite the owner's choice).
     *
     * Lives here, not on the version upgrader, because the boot-time settings
     * read needs it on every request and must not load the upgrade service graph.
     *
     * @param string $dest A stored `dest404page` value ("<id>|<type>"), or "".
     * @return string One of 'theme_default', 'homepage', 'suggest', 'custom'.
     */
    public static function behaviorFromDestination(string $dest): string {
        if ($dest === '0|' . ABJ404_TYPE_404_DISPLAYED || $dest === (string)ABJ404_TYPE_404_DISPLAYED || $dest === '') {
            return 'theme_default';
        }
        if ($dest === '0|' . ABJ404_TYPE_HOME) {
            return 'homepage';
        }

        $parts = explode('|', $dest);
        $pageId = isset($parts[0]) ? (int)$parts[0] : 0;
        if ($pageId > 0 && ABJ_404_Solution_SystemPage::isSystemPage($pageId)) {
            return 'suggest';
        }
        return 'custom';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    public function apply(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['default_redirect'])) {
            $message .= $this->applyDefaultRedirect($options, $postData['default_redirect']);
        }

        if (isset($postData['dest404_behavior'])) {
            $message .= $this->applyBehavior($options, $postData);
        } else {
            $message .= $this->applyLegacyDestination($options, $postData);
        }

        if (isset($postData['template_redirect_priority'])) {
            $message .= $this->applyTemplateRedirectPriority($options, $postData['template_redirect_priority']);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawDefaultRedirect
     */
    private function applyDefaultRedirect(array &$options, $rawDefaultRedirect): string {
        $validDefaultCodes = array('301', '302', '307', '308');
        $redirectCode = (string)(is_scalar($rawDefaultRedirect) ? $rawDefaultRedirect : '');
        if (in_array($redirectCode, $validDefaultCodes, true)) {
            $options['default_redirect'] = intval($redirectCode);
            return "";
        }

        return __('Error: Invalid value specified for default redirect type', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyBehavior(array &$options, array $postData): string {
        $validBehaviors = array('suggest', 'homepage', 'custom', 'theme_default');
        $behavior = sanitize_text_field(is_string($postData['dest404_behavior']) ? $postData['dest404_behavior'] : '');
        if (!in_array($behavior, $validBehaviors, true)) {
            return __('Error: Invalid 404 behavior selected', '404-solution') . ".<BR/>";
        }

        $options['dest404_behavior'] = $behavior;
        return $this->applyBehaviorToDest404Page($options, $behavior, $postData);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyLegacyDestination(array &$options, array $postData): string {
        $candidateUrlLegacy = null;
        if (isset($postData['redirect_to_data_field_title'])) {
            $candidateUrlLegacy = sanitize_text_field(
                is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : ''
            );
            if (strlen($candidateUrlLegacy) > ABJ404_MAX_URL_LENGTH) {
                return sprintf(
                    __('Error: 404 destination URL exceeds the maximum length of %d characters', '404-solution'),
                    ABJ404_MAX_URL_LENGTH
                ) . ".<BR/>";
            }
        }

        if ($candidateUrlLegacy !== null) {
            if (isset($postData['redirect_to_data_field_id'])) {
                $options['dest404page'] = sanitize_text_field(
                    is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : ''
                );
            }
            $options['dest404pageURL'] = $candidateUrlLegacy;
            $this->rewriteExternalDestinationOption($options);
        } else if (isset($postData['redirect_to_data_field_id'])) {
            $options['dest404page'] = sanitize_text_field(
                is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : ''
            );
        }

        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawPriority
     */
    private function applyTemplateRedirectPriority(array &$options, $rawPriority): string {
        if (is_numeric($rawPriority) && $rawPriority >= 0 && $rawPriority <= 999) {
            $options['template_redirect_priority'] = absint($rawPriority);
            return "";
        }

        return __('Error: Template redirect priority value must be a number between 0 and 999', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param string $behavior
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    private function applyBehaviorToDest404Page(array &$options, string $behavior, array $postData): string {
        switch ($behavior) {
            case 'suggest':
                $systemPage = ABJ_404_Solution_SystemPage::getInstance();
                $pageId = $systemPage->ensureSystemPage();
                if ($pageId > 0) {
                    $options['dest404page'] = $pageId . '|' . ABJ404_TYPE_POST;
                    return "";
                }
                return __('Error: Could not create the suggestion page', '404-solution') . ".<BR/>";

            case 'homepage':
                $options['dest404page'] = '0|' . ABJ404_TYPE_HOME;
                return "";

            case 'custom':
                return $this->applyLegacyDestination($options, $postData);

            case 'theme_default':
            default:
                $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
                return "";
        }
    }

    /** @param array<string, mixed> $options */
    private function rewriteExternalDestinationOption(array &$options): void {
        if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $destinationUrl = isset($options['dest404pageURL']) && is_scalar($options['dest404pageURL']) ?
                (string)$options['dest404pageURL'] : '';
            $options['dest404page'] = $destinationUrl . '|' . ABJ404_TYPE_EXTERNAL;
        }
    }
}
