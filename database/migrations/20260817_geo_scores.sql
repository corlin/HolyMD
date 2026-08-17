CREATE TABLE IF NOT EXISTS `geo_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(255) NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL,
  `breakdown` JSON NOT NULL,
  `snapshot_trigger` VARCHAR(20) NOT NULL DEFAULT 'publish',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_slug_created` (`slug`, `created_at`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
