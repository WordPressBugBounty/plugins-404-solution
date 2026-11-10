<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The stored-settings migrations a version upgrade runs, keyed by the version
 * the site upgrades from: default lists that later versions extended (the
 * do-not-log user agents, the ignored folders), the dest404page format, the
 * setup-wizard marker for installs that predate it, and the settings keys
 * added after 3.x (suggest_minscore_enabled, dest404_behavior).
 *
 * Each step changes only what its version gate says is missing and persists
 * through the options repository. PluginLogicVersionUpgrader runs them inside
 * its upgrade lock, on the settings row it read there.
 */
class ABJ_404_Solution_SettingsVersionMigrations {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_Functions $f, ABJ_404_Solution_Logging $logger) {
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Run every settings migration, in order, on the options being upgraded.
     *
     * @param array<string, mixed> $options Updated in place.
     * @param string $fromVersion The stored DB_VERSION ('0.0.0' on a fresh install).
     * @return void
     */
    public function apply(array &$options, string $fromVersion): void {
        $this->migrateIgnoredUserAgents($options, $fromVersion);
        $this->migrateIgnoredFolders($options, $fromVersion);
        $this->normalizeDest404Page($options);
        $this->markSetupCompletedForExistingInstall($fromVersion);
        $this->migrateSuggestMinScoreEnabled($options);
        $this->migrateDest404Behavior($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateIgnoredUserAgents(
        array &$options,
        string $currentDBVersion
    ): void {
        if (version_compare($currentDBVersion, '1.9.0') >= 0) {
            return;
        }

        $ignoreDoProcessStr = is_string($options['ignore_doprocess']) ? $options['ignore_doprocess'] : '';
        $userAgents = $this->f->explodeNewline($ignoreDoProcessStr);

        $uasForSearch = $this->f->explodeNewline($ignoreDoProcessStr);

        foreach ($userAgents as &$str) {
            if ($this->f->strtolower(trim($str)) == 'slurp') {
                $str = 'Yahoo! Slurp';
                $this->logger->infoMessage('Changed user agent "Slurp" to "Yahoo! Slurp" in the do not log list.');
            }
        }

        if (!in_array('seznambot', $uasForSearch)) {
            $userAgents[] = 'SeznamBot';
            $this->logger->infoMessage('Added user agent "SeznamBot" to do not log list."');
        }
        if (!in_array('pinterestbot', $uasForSearch)) {
            $userAgents[] = 'Pinterestbot';
            $this->logger->infoMessage('Added user agent "Pinterestbot" to do not log list."');
        }
        if (!in_array('uptimerobot', $uasForSearch)) {
            $userAgents[] = 'UptimeRobot';
            $this->logger->infoMessage('Added user agent "UptimeRobot" to do not log list."');
        }

        $options['ignore_doprocess'] = implode("\n", $userAgents);
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateIgnoredFolders(
        array &$options,
        string $currentDBVersion
    ): void {
        if (version_compare($currentDBVersion, '2.18.0') >= 0) {
            return;
        }

        $foldersIgnoreStr = is_string($options['folders_files_ignore']) ? $options['folders_files_ignore'] : '';
        $originalItems = $this->f->explodeNewline($foldersIgnoreStr);

        $newItems = array('wp-content/plugins/*', 'wp-content/themes/*', '.well-known/acme-challenge/*');
        foreach ($newItems as $newItem) {
            if (array_search($newItem, $originalItems) === false) {
                $originalItems[] = $newItem;
                $this->logger->infoMessage('Added ' . $newItem . ' to the list of folders to ignore."');
            }
        }

        $options['folders_files_ignore'] = implode("\n", $originalItems);
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function normalizeDest404Page(array &$options): void {
        $dest404page = is_string($options['dest404page']) ? $options['dest404page'] : '';
        if ($this->f->strpos($dest404page, '|') !== false) {
            return;
        }

        if ($dest404page == '0') {
            $dest404page .= '|' . ABJ404_TYPE_404_DISPLAYED;
        } else {
            $dest404page .= '|' . ABJ404_TYPE_POST;
        }
        $options['dest404page'] = $dest404page;
        abj_service('options_repository')->updateOptions($options);
    }

    /** @return void */
    private function markSetupCompletedForExistingInstall(string $currentDBVersion): void {
        if ($currentDBVersion === '0.0.0' || version_compare($currentDBVersion, '3.0.7') >= 0) {
            return;
        }

        // @cache-write-audit: opt-out - stores a setup-completion date marker, not a query result
        update_option('abj404_setup_completed', gmdate('Y-m-d', abj_clock()->now()));
        $this->logger->infoMessage('Marked setup wizard as completed for existing user.');
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateSuggestMinScoreEnabled(array &$options): void {
        if (isset($options['suggest_minscore_enabled'])) {
            return;
        }

        if (isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) && intval($options['suggest_minscore']) >= 25) {
            $options['suggest_minscore_enabled'] = '1';
            $this->logger->infoMessage('Enabled minimum score filtering based on existing suggest_minscore setting.');
        } else {
            $options['suggest_minscore_enabled'] = '0';
        }
        abj_service('options_repository')->updateOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return void
     */
    private function migrateDest404Behavior(array &$options): void {
        if (isset($options['dest404_behavior']) && $options['dest404_behavior'] !== 'theme_default') {
            return;
        }

        $dest = is_string($options['dest404page']) ? $options['dest404page'] : '';
        $options['dest404_behavior'] = ABJ_404_Solution_SettingsRedirectPolicy::behaviorFromDestination($dest);
        abj_service('options_repository')->updateOptions($options);
    }
}
