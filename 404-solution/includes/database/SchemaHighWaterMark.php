<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The newest plugin version that has shaped this database's schema.
 *
 * DB_VERSION follows whichever build ran last, so a downgrade lowers it. This
 * separate record only ever rises (the version_compare maximum of the stored
 * value and every version raised to it), which lets an older build recognise a
 * schema a newer build shaped and refrain from dropping or rewriting its
 * columns (see DatabaseUpgradeSchemaDiff::updateATableBasedOnDifferences()).
 *
 * It is its own WordPress option, outside the main settings array, so a
 * settings reset cannot erase it, and it is not autoloaded because only the
 * upgrade path reads it. PluginLogicVersionUpgrader::stampDbVersion() raises it.
 *
 * A stored value that is not a version string is unreadable. The guard then
 * fails closed (isNewerThan() is true, since a newer build may have written
 * it), and the next raiseTo() replaces it and logs the repair at warn level.
 */
class ABJ_404_Solution_SchemaHighWaterMark {

    /** Name of the WordPress option that stores the high-water version. */
    const OPTION = 'abj404_schema_high_water';

    /** A plugin version: digits and dots first, anything (a beta suffix) after. */
    private const VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+)*/';

    /**
     * The newest plugin version recorded as having shaped this database's schema.
     *
     * @return string|null Empty when nothing has been recorded, null when the
     *                     stored value is not a version string.
     */
    public static function read(): ?string {
        $stored = get_option(self::OPTION, '');
        if ($stored === '') {
            return '';
        }
        return is_string($stored) && preg_match(self::VERSION_PATTERN, $stored) === 1 ? $stored : null;
    }

    /**
     * Record a version as having shaped the schema, unless a newer one is already
     * recorded. The record never falls, and the option is written only when its
     * value changes. An unreadable record is replaced, and the repair is logged.
     *
     * @param string $version The plugin version to record.
     * @return void
     */
    public static function raiseTo(string $version): void {
        $stored = self::read();
        if ($stored === null) {
            $raw = get_option(self::OPTION, '');
            abj_service('logging')->warn("The schema high-water record (option " . self::OPTION .
                ") held an unreadable value (" . (is_string($raw) ? '"' . $raw . '"' : gettype($raw)) .
                "); replaced it with " . $version . ".");
            update_option(self::OPTION, $version, false);
            return;
        }
        if ($stored === '' || version_compare($stored, $version, '<')) {
            update_option(self::OPTION, $version, false);
        }
    }

    /**
     * Whether a newer version than the given one has already shaped the schema.
     * An empty or missing record never blocks: a site that never ran a build
     * writing the record has no evidence of a newer schema. An unreadable record
     * always blocks, because a newer build may have written it.
     *
     * @param string $version The running plugin version.
     * @return bool True when the record is unreadable, or exists and is newer than $version.
     */
    public static function isNewerThan(string $version): bool {
        $recorded = self::read();
        if ($recorded === null) {
            return true;
        }
        return $recorded !== '' && version_compare($version, $recorded, '<');
    }
}
