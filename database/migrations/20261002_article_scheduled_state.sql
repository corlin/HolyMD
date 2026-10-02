-- Add scheduled state to articles table
ALTER TABLE `articles` MODIFY COLUMN `state` ENUM('draft', 'scheduled', 'published', 'withdrawn') NOT NULL DEFAULT 'draft';
