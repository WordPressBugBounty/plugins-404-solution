CREATE TABLE IF NOT EXISTS `{wp_abj404_redirect_intents}` (
    `intent_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `spec_json` LONGTEXT NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`intent_key`),
    KEY `created_intent` (`created_at`, `intent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
