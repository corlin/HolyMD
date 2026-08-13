ALTER TABLE `admin_users` ADD COLUMN `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `display_name`;
ALTER TABLE `admin_users` ADD COLUMN `locked_until` DATETIME(6) NULL AFTER `failed_attempts`;
ALTER TABLE `admin_users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `locked_until`;
