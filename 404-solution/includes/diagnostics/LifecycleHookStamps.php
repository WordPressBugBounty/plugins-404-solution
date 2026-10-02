<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feeds WordPress lifecycle hooks into the always-on per-request phase
 * timeline (ABJ_404_Solution_RequestPhaseTimeline), so a request that dies
 * says which window of the WordPress lifecycle spent its time.
 *
 * The timeline itself only knows the plugin's own moments (boot, the census
 * join, AJAX stages). A front-end request that dies of max_execution_time
 * spends most of its time OUTSIDE those moments: in WordPress core, the other
 * active plugins, the theme and the main query. plmcb.fr reports 505/506 (and
 * 483-492 before them) are why this exists: each request died 1-2 s after the
 * plugin's 404 work began, or before it began at all, and nothing on record
 * could say where the first 28 s went. Stamping each lifecycle hook on entry
 * turns that single number into per-window gaps.
 *
 * This class owns only "which WordPress hooks feed the timeline, and under
 * what stamp names". Storage, the stamp cap and the encoding stay with the
 * timeline. In-memory only; no I/O; never throws.
 */
final class ABJ_404_Solution_LifecycleHookStamps {

    /**
     * Hooks a non-AJAX request stamps on entry, in the order WordPress fires
     * them. Stamp name is "wp:<hook>".
     */
    const HOOKS = array('plugins_loaded', 'init', 'wp_loaded', 'wp', 'template_redirect');

    /**
     * Register a PHP_INT_MIN callback on every hook in HOOKS, so each stamp
     * marks the moment the hook STARTED, before any other callback on it ran.
     * The gap between two consecutive stamps is the time spent in everything
     * that ran in that window.
     *
     * Not registered for AJAX: the census-instrumented requests are all
     * admin-ajax, and their timelines already spend the stamp cap on their
     * own stages. Call once, at plugin boot, after the timeline's
     * markPluginBoot().
     *
     * @return void
     */
    public static function register(): void {
        if (!function_exists('add_action')) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        // Non-AJAX requests carry no request id, so the empty id makes this
        // bracket persist nothing; it exists because the diagnostics-wide guard
        // (DecisiveRecordManifestTest) requires every hook mutation here to
        // run inside traceBoundary().
        (new ABJ_404_Solution_HookInstrumentationLifecycleTracer('', 'lifecycle_hook_stamps'))->traceBoundary(
            ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION, 'plugins_loaded', static function (): void {
                foreach (self::HOOKS as $hook) {
                    add_action($hook, array(self::class, 'stampCurrentHook'), PHP_INT_MIN);
                }
            });
    }

    /**
     * Stamp the WordPress hook currently running, as "wp:<hook>". Reads
     * $GLOBALS['wp_current_filter'] directly, the array core's own
     * current_filter() reads. No-op when no hook is running.
     *
     * @return void
     */
    public static function stampCurrentHook(): void {
        $stack = $GLOBALS['wp_current_filter'] ?? null;
        if (!is_array($stack) || $stack === array()) {
            return;
        }
        $hook = end($stack);
        if (is_string($hook)) {
            ABJ_404_Solution_RequestPhaseTimeline::stamp('wp:' . $hook);
        }
    }
}
