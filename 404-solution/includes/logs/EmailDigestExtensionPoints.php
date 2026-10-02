<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The email digest's public extension points: the contract other plugins hook
 * into. Neither hook names a particular plugin, and with nothing hooked the
 * digest is exactly what it was before they existed.
 *
 * `abj404_digest_sections` (filter): lets a plugin add HTML sections to the
 * digest body. Receives `array()` and the digest's date-range label
 * (string), and returns a list of HTML strings. Each string becomes one
 * full-width row between the top-URLs table and the call-to-action buttons,
 * in list order. Every string passes through `wp_kses_post()` (a misbehaving
 * callback cannot inject script into an admin's inbox); a value that is not a
 * list, and entries that are not non-blank strings, are ignored. Pass a
 * fragment, not a `<tr>`/`<td>`: the digest supplies the row around it.
 *
 * `abj404_digest_sent` (action): fires once per digest that `wp_mail()`
 * accepted, after the cooldown timestamp is stored, with that timestamp (int,
 * Unix seconds) as its only argument. It never fires for a skipped or failed
 * send.
 *
 * Both hooks are third-party code running inside the plugin's own cron, so a
 * callback that throws is logged as a warning and otherwise ignored: an
 * optional section must not stop the digest, and a subscriber must not turn a
 * delivered digest into a reported failure.
 *
 * These names and argument lists are a public contract. Extend them only
 * additively.
 */
class ABJ_404_Solution_EmailDigestExtensionPoints {

    public const FILTER_SECTIONS = 'abj404_digest_sections';

    public const ACTION_SENT = 'abj404_digest_sent';

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging $logger Receives the warning when a third-party callback throws.
     */
    public function __construct(ABJ_404_Solution_Logging $logger) {
        $this->logger = $logger;
    }

    /**
     * Apply the `abj404_digest_sections` filter and render the result as
     * digest rows.
     *
     * @param string $dateRange The digest's date-range label, passed to the filter.
     * @return string Table rows to place in the digest body, or '' when no
     *     plugin contributed a section.
     */
    public function renderSections(string $dateRange): string {
        try {
            $sections = apply_filters(self::FILTER_SECTIONS, array(), $dateRange);
        } catch (\Throwable $e) {
            $this->warnCallbackThrew(self::FILTER_SECTIONS, $e);
            return '';
        }

        if (!is_array($sections)) {
            $this->logger->debugMessage(self::FILTER_SECTIONS . ' must return an array of HTML strings, got '
                . gettype($sections) . '; ignoring it.');
            return '';
        }

        $rowTemplate = '';
        $rows = '';
        foreach ($sections as $section) {
            if (!is_string($section)) {
                continue;
            }
            $safeHtml = wp_kses_post($section);
            if (trim($safeHtml) === '') {
                continue;
            }
            if ($rowTemplate === '') {
                $rowTemplate = ABJ_404_Solution_FileSystemService::readFileContents(
                    dirname(__DIR__) . '/html/emailDigestExtraSection.html',
                    false
                );
            }
            $rows .= str_replace('{sectionHtml}', $safeHtml, $rowTemplate);
        }
        return $rows;
    }

    /**
     * Fire `abj404_digest_sent`.
     *
     * @param int $sentAt Unix timestamp stored as the digest's last-sent time.
     * @return void
     */
    public function notifySent(int $sentAt): void {
        try {
            do_action(self::ACTION_SENT, $sentAt);
        } catch (\Throwable $e) {
            $this->warnCallbackThrew(self::ACTION_SENT, $e);
        }
    }

    /**
     * A third-party callback failing is a warning, not a plugin bug, so it is
     * logged below the level that triggers a developer error-report email.
     */
    private function warnCallbackThrew(string $hook, \Throwable $e): void {
        $this->logger->warn('A callback on the ' . $hook . ' hook threw ' . get_class($e) . ': '
            . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . '). The digest is unaffected.');
    }
}
