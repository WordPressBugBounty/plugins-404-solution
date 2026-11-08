<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-key bookkeeping writes into the stored settings row
 * (`abj404_settings`), and the deferral of those writes while the site's first
 * settings snapshot is being built.
 *
 * Logging infrastructure persists its own metadata keys (debug-file key,
 * last-sent-line counter) and a few cadence timestamps through here. The write
 * is a byte-level compare-and-swap that bypasses the storage write contract,
 * native option hooks and the runtime logger, so it can never re-enter the
 * settings pipeline. It never creates an absent settings row: the full
 * initializer is the sole creator. A bookkeeping write that arrives while that
 * initializer is running is queued and written once the complete snapshot is
 * published, so it cannot be overwritten by, or overwrite, the initial row.
 *
 * Warnings go only to the inert abj404_logPhpFallback() error-log sink,
 * because the runtime logger reads settings to find its log file.
 */
final class ABJ_404_Solution_SettingsBookkeepingWriter {

    /** @var array<string, array{pending:array<string,mixed>, defer:bool}> Shared first-read state per site. */
    private static $initializationContexts = array();

    /**
     * Write one bookkeeping key, or queue it while this site's first settings
     * snapshot is being built.
     *
     * @param string $key Setting key inside abj404_settings.
     * @param mixed $value Value to store.
     * @return bool False when the key was refused; true when the write was
     *         attempted or queued, so the caller must drop its cached options.
     */
    public static function set(string $key, $value): bool {
        if ($key === 'dest404page' || $key === 'dest404_behavior') {
            abj404_logPhpFallback('service-resolution-fallback', 'Raw bookkeeping writer refused destination setting ' . $key . '.');
            return false;
        }
        if ($key === '') {
            abj404_logPhpFallback('service-resolution-fallback', 'Raw bookkeeping writer refused an empty key.');
            return false;
        }
        $contextKey = self::siteContextKey();
        if (isset(self::$initializationContexts[$contextKey]['defer'])
            && self::$initializationContexts[$contextKey]['defer'] === true) {
            self::$initializationContexts[$contextKey]['pending'][$key] = $value;
            return true;
        }
        self::write($key, $value);
        return true;
    }

    /** @return string Stable site identity shared by resolver instances in this request. */
    public static function siteContextKey(): string {
        global $wpdb;
        $blogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;
        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string)$wpdb->prefix : '';
        return $blogId . ':' . $prefix;
    }

    /** Whether this site's first settings snapshot is currently being built. */
    public static function hasInitializationContext(string $contextKey): bool {
        return isset(self::$initializationContexts[$contextKey]);
    }

    /** Start deferring bookkeeping writes for this site until the first snapshot is published. */
    public static function beginInitializationContext(string $contextKey): void {
        if (isset(self::$initializationContexts[$contextKey])) {
            return;
        }
        self::$initializationContexts[$contextKey] = array('pending' => array(), 'defer' => true);
    }

    /**
     * Overlay the queued bookkeeping values onto the options being published.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function applyPendingBookkeeping(string $contextKey, array $options): array {
        foreach (self::$initializationContexts[$contextKey]['pending'] ?? array() as $key => $value) {
            $options[$key] = $value;
        }
        return $options;
    }

    /**
     * End the deferral. When the first snapshot was published, the queued
     * writes land now, on top of it.
     *
     * @param callable(): void $invalidateCache Discard runtime snapshots after each attempted write.
     * @return void
     */
    public static function finishInitializationContext(string $contextKey, bool $published, callable $invalidateCache): void {
        if (!isset(self::$initializationContexts[$contextKey])) {
            return;
        }
        if ($published) {
            self::$initializationContexts[$contextKey]['defer'] = false;
            $pending = self::$initializationContexts[$contextKey]['pending'];
            self::$initializationContexts[$contextKey]['pending'] = array();
            foreach ($pending as $key => $value) {
                self::write($key, $value);
                // A later full getOptions() must re-read the row rather than serve
                // a snapshot taken before this raw CAS.
                $invalidateCache();
            }
        }
        unset(self::$initializationContexts[$contextKey]);
    }

    /** @param mixed $value */
    private static function write(string $key, $value): void {
        $result = ABJ_404_Solution_OptionRowCompareAndSwap::update(array(
            'optionName' => 'abj404_settings',
            'compute' => static function (?string $current) use ($key, $value): ?string {
                // A bookkeeping write must never become the creator of the
                // settings row. Only the full initializer may establish the
                // row; returning null also closes the delete-between-read-and-
                // write race because OptionRowCompareAndSwap re-evaluates this
                // closure against the latest bytes on every attempt.
                if ($current === null) {
                    return null;
                }
                $raw = @unserialize($current, array('allowed_classes' => false));
                if (!is_array($raw)) {
                    return null;
                }
                $raw[$key] = $value;
                return serialize($raw);
            },
        ));
        if ($result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_SWAPPED
            && $result !== ABJ_404_Solution_OptionRowCompareAndSwap::RESULT_UNCHANGED) {
            abj404_logPhpFallback('service-resolution-fallback', 'Raw bookkeeping write was not confirmed for key ' . $key . '.');
        }
    }
}
