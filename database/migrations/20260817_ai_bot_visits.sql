-- Migration: Create ai_bot_visits table for GEO AI crawler observability
CREATE TABLE IF NOT EXISTS `ai_bot_visits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bot_name` VARCHAR(64) NOT NULL,
    `request_path` VARCHAR(768) NOT NULL,
    `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `ip_hash` CHAR(16) NOT NULL,
    `user_agent` VARCHAR(512) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_bot_created` (`bot_name`, `created_at`),
    INDEX `idx_path_created` (`request_path`(255), `created_at`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
