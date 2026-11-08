<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The version stamp on the stored `abj404_settings` row: `DB_VERSION` (the
 * plugin release that last upgraded the row) and `DB_SCHEMA_REVISION` (the
 * schema revision it was upgraded to).
 *
 * One definition serves both sides of the stamp so they cannot drift:
 *
 *  - isStale() is the cheap, pure question the boot-time settings read asks to
 *    decide whether to resolve the upgrade service at all. The read path
 *    (PluginLogicOptionsResolver) must not load the upgrade service graph just
 *    to learn that nothing needs upgrading, which is why this lives apart from
 *    PluginLogicVersionUpgrader (BootSettingsReadServiceGraphTest pins that).
 *  - stamp() is what the upgrade tail writes once the upgrade has run.
 *
 * Not to be confused with SchemaHighWaterMark, a separate option that only ever
 * rises and exists to protect a newer build's schema from an older build.
 */
final class ABJ_404_Solution_SettingsVersionStamp {

    /**
     * Whether the stored stamp lags the running code and the upgrade must run.
     *
     * A missing or unreadable stamp lags (it reads as never stamped); a schema
     * revision AHEAD of the code (a downgrade) does not, so an older build does
     * not re-run an upgrade for a schema a newer build shaped.
     *
     * @param array<string, mixed> $options The stored settings row.
     * @return bool True when the upgrade must run.
     */
    public static function isStale(array $options): bool {
        return !array_key_exists('DB_VERSION', $options)
            || $options['DB_VERSION'] != ABJ404_VERSION
            || ABJ_404_Solution_ExactInteger::readOr($options['DB_SCHEMA_REVISION'] ?? null, 0, 0)
                < ABJ_404_Solution_DatabaseUpgradesEtc::SCHEMA_REVISION;
    }

    /**
     * The settings row carrying the running code's stamp. Pure: returns a copy
     * and persists nothing; every other key is preserved untouched.
     *
     * @param array<string, mixed> $options The settings row to stamp.
     * @return array<string, mixed> The same row with the current stamp keys.
     */
    public static function stamp(array $options): array {
        $options['DB_VERSION'] = ABJ404_VERSION;
        $options['DB_SCHEMA_REVISION'] = ABJ_404_Solution_DatabaseUpgradesEtc::SCHEMA_REVISION;
        return $options;
    }
}
