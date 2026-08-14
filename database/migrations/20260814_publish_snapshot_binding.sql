ALTER TABLE `jobs` ADD COLUMN `article_version_id` BIGINT UNSIGNED NULL AFTER `article_id`;
ALTER TABLE `jobs` ADD KEY `jobs_article_version_index` (`article_version_id`);
ALTER TABLE `jobs` ADD CONSTRAINT `jobs_article_version_fk` FOREIGN KEY (`article_version_id`) REFERENCES `article_versions` (`id`) ON DELETE SET NULL;
