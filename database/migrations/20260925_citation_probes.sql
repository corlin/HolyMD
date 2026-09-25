-- Migration: results of asking AI search models the FAQ questions of published articles
CREATE TABLE IF NOT EXISTS `citation_probes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(255) NOT NULL,
    `question_hash` CHAR(64) NOT NULL,
    `question` VARCHAR(500) NOT NULL,
    `model` VARCHAR(100) NOT NULL,
    `cited_site` TINYINT(1) NOT NULL DEFAULT 0,
    `cited_article` TINYINT(1) NOT NULL DEFAULT 0,
    `mentioned` TINYINT(1) NOT NULL DEFAULT 0,
    `cited_url` VARCHAR(768) NULL,
    `citations` TEXT NOT NULL,
    `error` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_question_created` (`question_hash`, `created_at`),
    INDEX `idx_slug_created` (`slug`, `created_at`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
