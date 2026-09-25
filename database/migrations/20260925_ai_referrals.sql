-- Migration: human visits referred by AI assistants and AI search engines (no IP or user agent stored)
CREATE TABLE IF NOT EXISTS `ai_referrals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(64) NOT NULL,
    `landing_path` VARCHAR(768) NOT NULL,
    `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `referrer_host` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_source_created` (`source`, `created_at`),
    INDEX `idx_path_created` (`landing_path`(255), `created_at`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
