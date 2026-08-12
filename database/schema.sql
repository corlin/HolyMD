-- Operational state only. Markdown article content remains in content/articles/.
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(320) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(255) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `articles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_path` VARCHAR(768) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `state` ENUM('draft', 'published', 'withdrawn') NOT NULL DEFAULT 'draft',
    `metadata_checksum` CHAR(64) NOT NULL,
    `published_at` DATETIME(6) NULL,
    `withdrawn_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `articles_source_path_unique` (`source_path`),
    UNIQUE KEY `articles_slug_unique` (`slug`),
    KEY `articles_state_index` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `article_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `article_id` BIGINT UNSIGNED NOT NULL,
    `snapshot_path` VARCHAR(768) NOT NULL,
    `content_checksum` CHAR(64) NOT NULL,
    `created_by_admin_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `article_versions_snapshot_path_unique` (`snapshot_path`),
    KEY `article_versions_article_index` (`article_id`),
    CONSTRAINT `article_versions_article_fk` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `article_versions_admin_user_fk` FOREIGN KEY (`created_by_admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_reviews` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `article_id` BIGINT UNSIGNED NOT NULL,
    `article_version_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    `provider` VARCHAR(100) NOT NULL,
    `model` VARCHAR(255) NOT NULL,
    `input_checksum` CHAR(64) NOT NULL,
    `request_key` CHAR(64) NOT NULL,
    `failure_message` TEXT NULL,
    `completed_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `geo_reviews_article_index` (`article_id`),
    KEY `geo_reviews_version_index` (`article_version_id`),
    KEY `geo_reviews_status_index` (`status`),
    UNIQUE KEY `geo_reviews_request_unique` (`request_key`),
    CONSTRAINT `geo_reviews_article_fk` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `geo_reviews_version_fk` FOREIGN KEY (`article_version_id`) REFERENCES `article_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geo_proposals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `geo_review_id` BIGINT UNSIGNED NOT NULL,
    `proposal_type` VARCHAR(100) NOT NULL,
    `proposed_metadata` JSON NOT NULL,
    `proposal_key` CHAR(64) NOT NULL,
    `status` ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    `decision_by_admin_user_id` BIGINT UNSIGNED NULL,
    `decided_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `geo_proposals_review_index` (`geo_review_id`),
    KEY `geo_proposals_status_index` (`status`),
    UNIQUE KEY `geo_proposals_key_unique` (`proposal_key`),
    CONSTRAINT `geo_proposals_review_fk` FOREIGN KEY (`geo_review_id`) REFERENCES `geo_reviews` (`id`) ON DELETE CASCADE,
    CONSTRAINT `geo_proposals_admin_user_fk` FOREIGN KEY (`decision_by_admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `builds` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `status` ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'queued',
    `triggered_by_admin_user_id` BIGINT UNSIGNED NULL,
    `manifest_path` VARCHAR(768) NULL,
    `output_checksum` CHAR(64) NULL,
    `failure_message` TEXT NULL,
    `started_at` DATETIME(6) NULL,
    `completed_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `builds_status_index` (`status`),
    CONSTRAINT `builds_admin_user_fk` FOREIGN KEY (`triggered_by_admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type` ENUM('geo_review', 'build') NOT NULL,
    `status` ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'queued',
    `article_id` BIGINT UNSIGNED NULL,
    `geo_review_id` BIGINT UNSIGNED NULL,
    `build_id` BIGINT UNSIGNED NULL,
    `action` ENUM('publish', 'withdraw') NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `locked_at` DATETIME(6) NULL,
    `lock_token` CHAR(36) NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `jobs_claim_index` (`status`, `available_at`),
    KEY `jobs_article_index` (`article_id`),
    CONSTRAINT `jobs_article_fk` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL,
    CONSTRAINT `jobs_geo_review_fk` FOREIGN KEY (`geo_review_id`) REFERENCES `geo_reviews` (`id`) ON DELETE SET NULL,
    CONSTRAINT `jobs_build_fk` FOREIGN KEY (`build_id`) REFERENCES `builds` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_user_id` BIGINT UNSIGNED NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `subject_type` VARCHAR(100) NOT NULL,
    `subject_id` BIGINT UNSIGNED NULL,
    `event_data` JSON NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `audit_events_subject_index` (`subject_type`, `subject_id`),
    KEY `audit_events_created_at_index` (`created_at`),
    CONSTRAINT `audit_events_admin_user_fk` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
