CREATE TABLE IF NOT EXISTS {wp_abj404_stats_buckets} (
    `generation` char(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `window_start` bigint NOT NULL,
    `window_end` bigint NOT NULL,
    `timestamp` bigint NOT NULL,
    `hit_404` bigint unsigned NOT NULL DEFAULT 0,
    `hit_redirect` bigint unsigned NOT NULL DEFAULT 0,
    `source_count` bigint unsigned NOT NULL DEFAULT 0,
    `created_at` bigint NOT NULL,
    PRIMARY KEY (`generation`, `window_start`, `timestamp`),
    KEY `timestamp` (`generation`, `timestamp`, `window_start`)
) ENGINE=InnoDB
