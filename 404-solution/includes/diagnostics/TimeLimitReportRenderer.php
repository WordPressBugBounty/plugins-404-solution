<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presentation of a time-limit breakdown record (ABJ_404_Solution_TimeLimitRecord)
 * for each surface where the blame for a timed-out request lands: the
 * WordPress recovery-mode email (HTML block and its plain-text twin), the
 * sentence on the "critical error" page, and the one-line PHP error-log
 * entry. The admin screen renders the same record through
 * ABJ_404_Solution_TimeLimitReportView, reusing rowLabel(), displayRows(),
 * slowCallbackLine() and the sentences here.
 *
 * Honesty rules for the copy, enforced here:
 * - "before 404 Solution loaded" appears only when the record carries a
 *   measured startup (request start is known).
 * - CPU is stated only when both CPU readings exist.
 * - The unattributed remainder is always listed.
 * - The method line says what was measured on this request.
 *
 * @phpstan-import-type Row from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type CallbackRow from ABJ_404_Solution_TimeLimitRecord
 * @phpstan-import-type Record from ABJ_404_Solution_TimeLimitRecord
 */
final class ABJ_404_Solution_TimeLimitReportRenderer {

    /** Rows shown individually; the rest fold into one "other" row. */
    const MAX_ROWS = 8;

    /** Slow callbacks named in the details, largest first. */
    const MAX_DETAIL_CALLBACKS = 3;

    /** Rows under this many ms fold into the "other" row. */
    const MIN_ROW_MS = 50;

    /** Row kinds drawn with the muted bar color: they are not one component. */
    const MUTED_KINDS = array('unattributed', 'others', 'main_query', 'output', 'startup', 'hook', 'phase');

    /**
     * Human label for a breakdown row or callback row.
     *
     * @param array{kind: string, key: string, label: string} $row
     * @return string
     */
    public static function rowLabel(array $row): string {
        $name = $row['label'] !== '' ? $row['label'] : $row['key'];
        $fixed = array(
            'self' => '404 Solution',
            'core' => __('WordPress core', '404-solution'),
            'startup' => __('Before 404 Solution loaded (WordPress start-up, drop-ins, must-use plugins)', '404-solution'),
            'main_query' => __('Main query (WordPress core and query filters)', '404-solution'),
            'output' => __('Page output (templates and content filters)', '404-solution'),
            'others' => __('Other components', '404-solution'),
            'unattributed' => __('Unattributed', '404-solution'),
            'plugin' => $name,
        );
        if (isset($fixed[$row['kind']])) {
            return $fixed[$row['kind']];
        }
        $formats = array(
            /* translators: %s: theme name */
            'theme' => __('Theme: %s', '404-solution'),
            /* translators: %s: must-use plugin file or folder name */
            'mu_plugin' => __('Must-use plugin: %s', '404-solution'),
            /* translators: %s: drop-in file name such as object-cache.php */
            'dropin' => __('Drop-in: %s', '404-solution'),
            /* translators: %s: WordPress action name such as init */
            'hook' => __('%s action (all callbacks, not timed one by one)', '404-solution'),
            /* translators: %s: WordPress action name such as init */
            'phase' => $name === 'plugins' ? __('Loading plugins', '404-solution') : __('From the %s action to the next', '404-solution'),
        );
        return isset($formats[$row['kind']]) ? sprintf($formats[$row['kind']], $name) : __('Other code', '404-solution');
    }

    /**
     * The rows to display: at most MAX_ROWS, small ones folded into
     * "Other components", unattributed always last.
     *
     * @param Record $record
     * @return list<Row>
     */
    public static function displayRows(array $record): array {
        $shown = array();
        $unattributed = array('kind' => 'unattributed', 'key' => '', 'label' => '', 'ms' => 0);
        $foldedMs = 0;
        foreach ($record['rows'] as $row) {
            if ($row['kind'] === 'unattributed') {
                $unattributed = $row;
            } elseif ($row['ms'] < self::MIN_ROW_MS || count($shown) >= self::MAX_ROWS) {
                $foldedMs += $row['ms'];
            } else {
                $shown[] = $row;
            }
        }
        if ($foldedMs > 0) {
            $shown[] = array('kind' => 'others', 'key' => '', 'label' => '', 'ms' => $foldedMs);
        }
        $shown[] = $unattributed;
        return $shown;
    }

    /**
     * Seconds with one decimal, e.g. "24.8 s".
     *
     * @param int|null $ms
     * @return string
     */
    public static function seconds(?int $ms): string {
        return $ms === null ? '?' : number_format($ms / 1000, 1, '.', '') . ' s';
    }

    /**
     * The summary: what stopped the request, what was proved to come before
     * 404 Solution, and how much of it was 404 Solution.
     *
     * @param Record $record
     * @return string
     */
    public static function summary(array $record): string {
        $where = self::rowLabel($record['died_in']);
        $parts = array($record['limit_s'] !== null
            /* translators: 1: time limit in seconds, 2: component name such as 404 Solution */
            ? sprintf(__('PHP stopped this request at its %1$d second time limit while it was running %2$s.', '404-solution'), $record['limit_s'], $where)
            /* translators: %s: component name */
            : sprintf(__('PHP stopped this request at its time limit while it was running %s.', '404-solution'), $where));
        if ($record['startup_ms'] !== null) {
            /* translators: %s: seconds, e.g. 27.9 s */
            $parts[] = sprintf(__('%s had already passed before 404 Solution loaded.', '404-solution'), self::seconds($record['startup_ms']));
        }
        // Only per-callback timing sees all of 404 Solution's own callbacks;
        // in every other mode some of them sit inside an action total, so the
        // figure is a measured share, and the copy says what it leaves out.
        $parts[] = $record['mode'] === ABJ_404_Solution_TimeLimitBreakdown::MODE_PER_CALLBACK
            /* translators: 1: seconds used by 404 Solution, 2: total seconds of the request */
            ? sprintf(__('404 Solution itself used %1$s of the request\'s %2$s.', '404-solution'),
                self::seconds($record['abj404_ms']), self::seconds($record['total_ms']))
            /* translators: 1: seconds measured as 404 Solution's own, 2: total seconds of the request */
            : sprintf(__('404 Solution\'s own measured time: %1$s of the request\'s %2$s (its callbacks inside the action totals are not split out).', '404-solution'),
                self::seconds($record['abj404_ms']), self::seconds($record['total_ms']));
        return implode(' ', $parts);
    }

    /**
     * Detail sentences: the slowest callbacks, slow callbacks timed on this
     * site recently, and CPU versus wall time.
     *
     * @param Record $record
     * @return list<string>
     */
    public static function details(array $record): array {
        $lines = array();
        // The top few, not just the slowest: the callback running at death is
        // usually 404 Solution's own (that is why core blamed us), and naming
        // only it would hide the component that spent the time.
        foreach (array_slice($record['slowest'], 0, self::MAX_DETAIL_CALLBACKS) as $slow) {
            $lines[] = $slow['in_flight']
                /* translators: 1: callback such as Some_Class::boot, 2: component name, 3: WordPress action name, 4: seconds */
                ? self::callbackSentence(__('Running when PHP stopped the request: %1$s (%2$s) on the %3$s action, %4$s so far.', '404-solution'), $slow)
                /* translators: 1: callback such as Some_Class::boot, 2: component name, 3: WordPress action name, 4: seconds */
                : self::callbackSentence(__('Slow callback: %1$s (%2$s) on the %3$s action, %4$s.', '404-solution'), $slow);
        }
        foreach ($record['recent_slow'] as $slow) {
            /* translators: 1: callback such as Some_Class::boot, 2: component name, 3: WordPress action name, 4: seconds */
            $lines[] = self::callbackSentence(__('Seen on this site in the last day, while 404 Solution timed callbacks: %1$s (%2$s) on the %3$s action took %4$s.', '404-solution'), $slow);
        }
        if ($record['cpu_ms'] !== null) {
            /* translators: 1: CPU seconds, 2: wall-clock seconds */
            $lines[] = sprintf(__('CPU time after 404 Solution loaded: %1$s of %2$s wall-clock time.', '404-solution'),
                self::seconds($record['cpu_ms']), self::seconds(max(0, $record['total_ms'] - ($record['startup_ms'] ?? 0))));
        }
        return $lines;
    }

    /**
     * What was measured on this request, so the reader knows how far the
     * per-component rows go.
     *
     * @param Record $record
     * @return string
     */
    public static function method(array $record): string {
        $window = sprintf(
            /* translators: 1: sample size such as 4, 2: minutes such as 30 */
            __('To name the slow callback, 404 Solution now times each callback on 1 in %1$d requests for the next %2$d minutes; what it finds appears on the 404 Solution admin screen.', '404-solution'),
            ABJ_404_Solution_TimeLimitFatalStore::ARMED_ONE_IN, intdiv(ABJ_404_Solution_TimeLimitFatalStore::ARMED_WINDOW_S, 60));
        switch ($record['mode']) {
            case ABJ_404_Solution_TimeLimitBreakdown::MODE_PER_CALLBACK:
                return __('Measured on this request: how long each plugin took to load, and each callback on the main WordPress actions (plugins_loaded, init, wp_loaded, template_redirect, admin_init and others). Time in filters and in code between those actions is shown as a window or as unattributed. Times are wall-clock seconds.', '404-solution');
            case ABJ_404_Solution_TimeLimitBreakdown::MODE_MIXED:
                return __('Measured on this request: how long each plugin took to load, the total of each main WordPress action, and each callback on the actions that ran after the request became slow. Times are wall-clock seconds.', '404-solution')
                    . ' ' . $window;
            case ABJ_404_Solution_TimeLimitBreakdown::MODE_PER_ACTION:
                return __('Measured on this request: how long each plugin took to load, and the total of each main WordPress action. Callbacks are not timed one by one on normal requests, to keep the site fast. Times are wall-clock seconds.', '404-solution')
                    . ' ' . $window;
            default:
                return __('Per-plugin timing is switched off on this site, so only WordPress phases are shown. Times are wall-clock seconds.', '404-solution');
        }
    }

    /**
     * The plain-text block for the email (and for text-only readers of the
     * HTML version): heading, aligned table, summary, details, method.
     *
     * @param Record $record
     * @return string
     */
    public static function text(array $record): string {
        $lines = array(self::heading($record), '');
        foreach (self::displayRows($record) as $row) {
            $label = self::rowLabel($row);
            $seconds = self::seconds($row['ms']);
            $lines[] = '  ' . $label . ' ' . str_repeat('.', max(3, 62 - strlen($label) - strlen($seconds))) . ' ' . $seconds;
        }
        $lines[] = '';
        $lines[] = self::summary($record);
        foreach (self::details($record) as $detail) {
            $lines[] = $detail;
        }
        $lines[] = self::method($record);
        return implode("\n", $lines);
    }

    /**
     * The HTML block for the email, from the external templates. Every
     * dynamic value is escaped here.
     *
     * @param Record $record
     * @return string
     */
    public static function html(array $record): string {
        $rows = self::displayRows($record);
        $scale = 1;
        foreach ($rows as $row) {
            $scale = max($scale, $row['ms']);
        }
        $rowTemplate = self::template('timeLimitEmailRow.html');
        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= strtr($rowTemplate, array(
                '{label}' => esc_html(self::rowLabel($row)),
                '{seconds}' => esc_html(self::seconds($row['ms'])),
                '{barColor}' => in_array($row['kind'], self::MUTED_KINDS, true) ? '#c3c4c7' : '#8c8f94', // allow-hardcoded-color: email clients ignore CSS custom properties, so inline hex is required
                '{barPercent}' => (string)(int)round(100 * $row['ms'] / $scale),
            ));
        }
        return strtr(self::template('timeLimitEmailBlock.html'), array(
            '{heading}' => esc_html(self::heading($record)),
            '{summary}' => esc_html(self::summary($record)),
            '{componentHeading}' => esc_html__('Component', '404-solution'),
            '{secondsHeading}' => esc_html__('Seconds', '404-solution'),
            '{rows}' => $rowsHtml,
            '{details}' => implode('<br>', array_map('esc_html', self::details($record))),
            '{method}' => esc_html(self::method($record)),
        ));
    }

    /**
     * The sentence added to the on-screen critical-error page for viewers
     * who already see details there (administrators, WP_DEBUG_DISPLAY).
     *
     * @param Record $record
     * @return string plain text; the caller escapes.
     */
    public static function errorPageSentence(array $record): string {
        return self::summary($record) . ' ' . __('The 404 Solution admin screen shows where the time went.', '404-solution');
    }

    /**
     * One line for the PHP error log, directly under the fatal: every row
     * with its seconds, CPU, and where the request died. Untranslated ASCII,
     * for hosts and support.
     *
     * @param Record $record
     * @return string
     */
    public static function logLine(array $record): string {
        $parts = array();
        foreach ($record['rows'] as $row) {
            $parts[] = self::componentId($row['kind'], $row['key']) . ' ' . self::secondsRaw($row['ms']);
        }
        $died = $record['died_in'];
        $line = '404 Solution: time-limit breakdown (mode ' . $record['mode']
            . ', limit ' . ($record['limit_s'] !== null ? $record['limit_s'] . 's' : '?')
            . ', total ' . self::secondsRaw($record['total_ms'])
            . ', before 404 Solution loaded ' . self::secondsRaw($record['startup_ms'])
            . ', cpu since 404 Solution loaded ' . self::secondsRaw($record['cpu_ms'])
            . '): ' . implode('; ', $parts)
            . ' | died in ' . ($died['hook'] ?? '?') . ' (' . self::componentId($died['kind'], $died['key']) . ')';
        if (isset($record['slowest'][0])) {
            $top = $record['slowest'][0];
            $line .= ' | slowest ' . $top['callback'] . ' on ' . $top['hook'] . ' ' . self::secondsRaw($top['ms']);
        }
        return str_replace(array("\r", "\n"), ' ', $line);
    }

    /**
     * One slow callback as a line: callback, owner, action and seconds.
     *
     * @param CallbackRow $row
     * @return string plain text; the caller escapes.
     */
    public static function slowCallbackLine(array $row): string {
        /* translators: 1: callback such as Some_Class::boot, 2: component name, 3: WordPress action name, 4: seconds */
        return self::callbackSentence(__('%1$s (%2$s) on the %3$s action: %4$s', '404-solution'), $row);
    }

    /**
     * @param string $format sprintf format taking callback, component, action, seconds.
     * @param CallbackRow $row
     * @return string
     */
    private static function callbackSentence(string $format, array $row): string {
        return sprintf($format, $row['callback'], self::rowLabel($row), $row['hook'], self::seconds($row['ms']));
    }

    /**
     * @param Record $record
     * @return string
     */
    private static function heading(array $record): string {
        if ($record['limit_s'] === null) {
            return __('Where this request\'s time went', '404-solution');
        }
        /* translators: %d: time limit in seconds */
        return sprintf(__('Where this request\'s time went (PHP time limit: %d s)', '404-solution'), $record['limit_s']);
    }

    /**
     * @param string $kind
     * @param string $key
     * @return string e.g. plugin:woocommerce, startup
     */
    private static function componentId(string $kind, string $key): string {
        return $kind . ($key !== '' ? ':' . $key : '');
    }

    /**
     * @param int|null $ms
     * @return string
     */
    private static function secondsRaw(?int $ms): string {
        return $ms !== null ? number_format($ms / 1000, 3, '.', '') . 's' : '?';
    }

    /**
     * An HTML template from includes/html. Shared by every time-limit
     * surface (email, error page, admin card) so they read templates one way.
     *
     * @param string $name file name under includes/html.
     * @return string the template, '' when unreadable.
     */
    public static function template(string $name): string {
        return (string)ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
    }
}
