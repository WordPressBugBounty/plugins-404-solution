CREATE TABLE IF NOT EXISTS {wp_abj404_stats_dimensions} (
    `generation` char(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `dimension_id` tinyint unsigned NOT NULL,
    `value_key` binary(32) NOT NULL,
    `latest_404` bigint DEFAULT NULL,
    `latest_redirect` bigint DEFAULT NULL,
    `created_at` bigint NOT NULL,
    PRIMARY KEY (`generation`, `dimension_id`, `value_key`),
    KEY `latest_404` (`generation`, `dimension_id`, `latest_404`),
    KEY `latest_redirect` (`generation`, `dimension_id`, `latest_redirect`)
) ENGINE=InnoDB
