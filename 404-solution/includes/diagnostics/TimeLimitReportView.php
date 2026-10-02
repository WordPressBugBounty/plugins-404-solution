<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own admin presentation of requests PHP's time limit killed
 * inside 404 Solution: the Tools card (a stacked bar of the newest dead
 * request's lifecycle windows with the time limit marked, the same
 * component table and sentences as the recovery email, the slow callbacks
 * the armed window found, and the last few deaths) and the one-line notice
 * that links to it.
 *
 * Presentation only: takes records already read by
 * ABJ_404_Solution_TimeLimitAdminReport, renders external templates from
 * includes/html, escapes every dynamic value. Plain HTML and CSS, no chart
 * library, grey shades only (docs/ui-aesthetic/UI_AESTHETIC.md).
 *
 * The bar shows lifecycle windows, not components: 404 Solution's own code
 * runs inside several windows (its include, its init work, its 404 handling),
 * so its seconds are in the component table under the bar rather than drawn
 * as a segment whose position would be invented.
 *
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type CallbackRow from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-type Segment array{key: string, label: string, ms: int, cpu_ms: int|null}
 */
final class ABJ_404_Solution_TimeLimitReportView {

    /** Anchor of the Tools card; the notice links here. */
    const ANCHOR = 'abj404-timeLimitReport';

    /** Bar segment shades, cycled; theme vars from adminThemes.css. */
    const SHADES = array('var(--abj404-chart-1)', 'var(--abj404-chart-2)', 'var(--abj404-chart-3)', 'var(--abj404-chart-4)', 'var(--abj404-chart-5)', 'var(--abj404-chart-6)');

    /**
     * Which lifecycle window each recorded phase belongs to. A window starts
     * at its key and runs until the next window's first phase.
     */
    const WINDOW_OF_PHASE = array(
        'startup' => 'startup', 'plugins' => 'plugins',
        'plugins_loaded' => 'plugins_loaded', 'setup_theme' => 'plugins_loaded', 'after_setup_theme' => 'plugins_loaded',
        'init' => 'init',
        'wp_loaded' => 'wp_loaded', 'parse_request' => 'wp_loaded', 'send_headers' => 'wp_loaded', 'wp' => 'wp_loaded',
        'template_redirect' => 'template_redirect', 'wp_head' => 'template_redirect', 'wp_footer' => 'template_redirect',
        'admin_init' => 'admin', 'admin_menu' => 'admin', 'current_screen' => 'admin',
        'admin_enqueue_scripts' => 'admin', 'admin_notices' => 'admin',
        'login_init' => 'login',
    );

    /**
     * The newest record's phases folded into lifecycle windows, in firing
     * order, consecutive phases of one window merged.
     *
     * @param Record $record
     * @return list<Segment>
     */
    public static function barSegments(array $record): array {
        $segments = array();
        $current = null;
        foreach ($record['phases'] as $phase) {
            $key = self::WINDOW_OF_PHASE[$phase['name']] ?? $phase['name'];
            if ($current !== null && $current['key'] === $key) {
                $current = array('key' => $key, 'label' => $current['label'], 'ms' => $current['ms'] + $phase['ms'],
                    'cpu_ms' => ($current['cpu_ms'] === null || $phase['cpu_ms'] === null) ? null : $current['cpu_ms'] + $phase['cpu_ms']);
                continue;
            }
            if ($current !== null) {
                $segments[] = $current;
            }
            $current = array('key' => $key, 'label' => self::windowLabel($key), 'ms' => $phase['ms'], 'cpu_ms' => $phase['cpu_ms']);
        }
        if ($current !== null) {
            $segments[] = $current;
        }
        return $segments;
    }

    /**
     * The Tools card body.
     *
     * @param list<array{at: int, path: string, breakdown: Record}> $entries oldest first, as stored.
     * @param list<CallbackRow> $slowCallbacks slow callbacks the armed window found, oldest first.
     * @return string
     */
    public static function card(array $entries, array $slowCallbacks): string {
        if ($entries === array()) {
            return '';
        }
        $newest = $entries[count($entries) - 1];
        $record = $newest['breakdown'];
        $limitMs = $record['limit_s'] !== null ? $record['limit_s'] * 1000 : 0;
        $scale = max(1, $record['total_ms'], $limitMs);

        $segmentTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportSegment.html');
        $legendTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportLegendItem.html');
        $segmentsHtml = '';
        $legendHtml = '';
        foreach (self::barSegments($record) as $i => $segment) {
            $color = self::SHADES[$i % count(self::SHADES)];
            $label = $segment['label'] . ': ' . ABJ_404_Solution_TimeLimitReportRenderer::seconds($segment['ms'])
                . ($segment['cpu_ms'] !== null
                    /* translators: %s: CPU seconds */
                    ? ' ' . sprintf(__('(CPU %s)', '404-solution'), ABJ_404_Solution_TimeLimitReportRenderer::seconds($segment['cpu_ms']))
                    : '');
            $segmentsHtml .= strtr($segmentTemplate, array('{title}' => esc_attr($label),
                '{percent}' => self::percent($segment['ms'], $scale), '{color}' => $color));
            $legendHtml .= strtr($legendTemplate, array('{label}' => esc_html($label), '{color}' => $color));
        }

        $rowTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportRow.html');
        $rowsHtml = '';
        foreach (ABJ_404_Solution_TimeLimitReportRenderer::displayRows($record) as $row) {
            $rowsHtml .= strtr($rowTemplate, array('{label}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::rowLabel($row)),
                '{seconds}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::seconds($row['ms']))));
        }

        $limitCaption = $record['limit_s'] !== null
            /* translators: %d: PHP time limit in seconds */
            ? sprintf(__('The dark line marks PHP\'s %d second time limit. Depending on the server, PHP counts either CPU time or wall-clock time against it (Linux counts CPU time, so a request waiting on the network or the database can run longer than the limit in wall-clock time).', '404-solution'), $record['limit_s'])
            : __('PHP did not report its time limit for this request.', '404-solution');

        return strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportCard.html'), array(
            /* translators: 1: date and time, 2: request path */
            '{intro}' => esc_html(sprintf(__('The last request PHP stopped at its time limit while 404 Solution\'s code was running: %1$s, %2$s', '404-solution'),
                self::when($newest['at']), $newest['path'])),
            '{barLabel}' => esc_attr__('Where the request\'s time went, by WordPress lifecycle window', '404-solution'),
            '{segments}' => $segmentsHtml,
            '{limitPercent}' => self::percent($limitMs, $scale),
            '{limitTitle}' => esc_attr($limitCaption),
            '{limitCaption}' => esc_html($limitCaption),
            '{legend}' => $legendHtml,
            '{componentHeading}' => esc_html__('Component', '404-solution'),
            '{secondsHeading}' => esc_html__('Seconds', '404-solution'),
            '{rows}' => $rowsHtml,
            '{summary}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::summary($record)),
            '{details}' => implode('<br>', array_map('esc_html', ABJ_404_Solution_TimeLimitReportRenderer::details($record))),
            '{method}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::method($record)),
            '{slowSection}' => self::slowSection($slowCallbacks),
            '{recentSection}' => self::recentSection($entries),
        ));
    }

    /**
     * The notice on the plugin's own screens.
     *
     * @param string $url the Tools card URL.
     * @return string
     */
    public static function notice(string $url): string {
        return strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitAdminNotice.html'), array(
            '{message}' => esc_html__('404 Solution: PHP stopped a request on this site at its time limit while 404 Solution\'s code was running. 404 Solution recorded which components used that request\'s time.', '404-solution'),
            '{url}' => esc_url($url),
            '{linkText}' => esc_html__('See where the time went', '404-solution'),
        ));
    }

    /**
     * @param list<CallbackRow> $slowCallbacks
     * @return string
     */
    private static function slowSection(array $slowCallbacks): string {
        if ($slowCallbacks === array()) {
            return '';
        }
        $itemTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportSlowItem.html');
        $items = '';
        foreach (array_reverse($slowCallbacks) as $row) {
            $items .= strtr($itemTemplate, array('{text}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::slowCallbackLine($row)
                . ($row['at'] !== null ? ', ' . self::when($row['at']) : ''))));
        }
        return strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportSlowSection.html'), array(
            '{heading}' => esc_html__('Slow callbacks found while 404 Solution timed each callback', '404-solution'),
            '{items}' => $items,
        ));
    }

    /**
     * @param list<array{at: int, path: string, breakdown: Record}> $entries
     * @return string
     */
    private static function recentSection(array $entries): string {
        $rowTemplate = ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportRecentRow.html');
        $rows = '';
        foreach (array_reverse($entries) as $entry) {
            $record = $entry['breakdown'];
            $died = $record['died_in'];
            $rows .= strtr($rowTemplate, array(
                '{when}' => esc_html(self::when($entry['at'])),
                '{path}' => esc_html($entry['path']),
                '{wall}' => esc_html(ABJ_404_Solution_TimeLimitReportRenderer::seconds($record['total_ms'])),
                '{cpu}' => esc_html($record['cpu_ms'] !== null ? ABJ_404_Solution_TimeLimitReportRenderer::seconds($record['cpu_ms']) : '?'),
                '{died}' => esc_html(($died['hook'] !== null ? $died['hook'] . ', ' : '') . ABJ_404_Solution_TimeLimitReportRenderer::rowLabel($died)),
            ));
        }
        return strtr(ABJ_404_Solution_TimeLimitReportRenderer::template('timeLimitReportRecentSection.html'), array(
            '{heading}' => esc_html__('Recent time-limit deaths', '404-solution'),
            '{whenHeading}' => esc_html__('When', '404-solution'),
            '{pathHeading}' => esc_html__('Path', '404-solution'),
            '{wallHeading}' => esc_html__('Wall time', '404-solution'),
            '{cpuHeading}' => esc_html__('CPU time (after 404 Solution loaded)', '404-solution'),
            '{diedHeading}' => esc_html__('Stopped in', '404-solution'),
            '{rows}' => $rows,
        ));
    }

    /**
     * @param string $key a WINDOW_OF_PHASE value, or an unmapped phase name.
     * @return string
     */
    private static function windowLabel(string $key): string {
        $labels = array(
            'startup' => __('Before 404 Solution loaded', '404-solution'),
            'plugins' => __('Loading plugins', '404-solution'),
            'plugins_loaded' => __('plugins_loaded to init (theme setup)', '404-solution'),
            'init' => __('init to wp_loaded', '404-solution'),
            'wp_loaded' => __('wp_loaded to template_redirect (main query)', '404-solution'),
            'template_redirect' => __('template_redirect to the end (page output)', '404-solution'),
            'admin' => __('admin_init to the end (admin screen)', '404-solution'),
            'login' => __('login_init to the end (login screen)', '404-solution'),
        );
        return $labels[$key] ?? $key;
    }

    /**
     * @param int $ms
     * @param int $scale
     * @return string percent with one decimal, never above 100.
     */
    private static function percent(int $ms, int $scale): string {
        return number_format(min(100, max(0, 100 * $ms / max(1, $scale))), 1, '.', '');
    }

    /**
     * Site-local date and time.
     *
     * @param int $at epoch seconds.
     * @return string
     */
    private static function when(int $at): string {
        $formatted = function_exists('wp_date') ? wp_date('Y-m-d H:i', $at) : false;
        return is_string($formatted) ? $formatted : gmdate('Y-m-d H:i', $at) . ' UTC';
    }
}
