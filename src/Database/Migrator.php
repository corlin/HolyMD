<?php

declare(strict_types=1);

namespace HolyMD\Database;

use PDO;
use RuntimeException;

final readonly class Migrator
{
    private const HARDENING = '20260812_queue_release_hardening';
    private const ACCOUNT_LOCKOUT = '20260813_admin_account_lockout';

    public function __construct(private PDO $pdo, private string $projectRoot)
    {
    }

    public function migrate(): MigrationResult
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('HolyMD migrations require MySQL.');
        }

        $installed = !$this->tableExists('admin_users');
        $schema = file_get_contents($this->projectRoot . '/database/schema.sql');
        if (!is_string($schema) || trim($schema) === '') {
            throw new RuntimeException('Unable to read database/schema.sql.');
        }
        // CREATE TABLE IF NOT EXISTS makes an interrupted first install recoverable.
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `schema_migrations` (`version` VARCHAR(191) NOT NULL, `applied_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (`version`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $applied = 0;
        foreach ($this->migrations() as [$version, $method]) {
            if ($this->applied($version)) {
                continue;
            }
            $this->{$method}();
            $this->record($version);
            $applied++;
        }
        return new MigrationResult($installed, $applied);
    }

    /** @return list<array{string, string}> */
    private function migrations(): array
    {
        return [
            [self::HARDENING, 'hardenLegacySchema'],
            [self::ACCOUNT_LOCKOUT, 'addAccountLockoutColumns'],
        ];
    }

    private function hardenLegacySchema(): void
    {
        if (!$this->columnExists('jobs', 'action')) {
            $this->pdo->exec("ALTER TABLE `jobs` ADD COLUMN `action` ENUM('publish', 'withdraw') NULL AFTER `build_id`");
        }
        if (!$this->columnExists('article_versions', 'body_checksum')) {
            $this->pdo->exec('ALTER TABLE `article_versions` ADD COLUMN `body_checksum` CHAR(64) NULL AFTER `content_checksum`');
        }
        $this->pdo->exec('UPDATE `article_versions` SET `body_checksum` = `content_checksum` WHERE `body_checksum` IS NULL');
        $this->pdo->exec('ALTER TABLE `article_versions` MODIFY `body_checksum` CHAR(64) NOT NULL');

        if (!$this->columnExists('geo_reviews', 'request_key')) {
            $this->pdo->exec('ALTER TABLE `geo_reviews` ADD COLUMN `request_key` CHAR(64) NULL AFTER `input_checksum`');
        }
        $this->pdo->exec("UPDATE `geo_reviews` SET `request_key` = SHA2(CONCAT(`article_id`, ':', `article_version_id`, ':', `input_checksum`, ':', `id`), 256) WHERE `request_key` IS NULL");
        $this->pdo->exec('ALTER TABLE `geo_reviews` MODIFY `request_key` CHAR(64) NOT NULL');
        if (!$this->indexExists('geo_reviews', 'geo_reviews_request_unique')) {
            $this->pdo->exec('ALTER TABLE `geo_reviews` ADD UNIQUE KEY `geo_reviews_request_unique` (`request_key`)');
        }

        if (!$this->columnExists('geo_proposals', 'proposal_key')) {
            $this->pdo->exec('ALTER TABLE `geo_proposals` ADD COLUMN `proposal_key` CHAR(64) NULL AFTER `proposed_metadata`');
        }
        $this->pdo->exec("UPDATE `geo_proposals` SET `proposal_key` = SHA2(CONCAT(`geo_review_id`, ':', `proposal_type`, ':', CAST(`proposed_metadata` AS CHAR), ':', `id`), 256) WHERE `proposal_key` IS NULL");
        $this->pdo->exec('ALTER TABLE `geo_proposals` MODIFY `proposal_key` CHAR(64) NOT NULL');
        if (!$this->indexExists('geo_proposals', 'geo_proposals_key_unique')) {
            $this->pdo->exec('ALTER TABLE `geo_proposals` ADD UNIQUE KEY `geo_proposals_key_unique` (`proposal_key`)');
        }
    }

    private function addAccountLockoutColumns(): void
    {
        if (!$this->columnExists('admin_users', 'failed_attempts')) {
            $this->pdo->exec('ALTER TABLE `admin_users` ADD COLUMN `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `display_name`');
        }
        if (!$this->columnExists('admin_users', 'locked_until')) {
            $this->pdo->exec('ALTER TABLE `admin_users` ADD COLUMN `locked_until` DATETIME(6) NULL AFTER `failed_attempts`');
        }
        if (!$this->columnExists('admin_users', 'is_active')) {
            $this->pdo->exec('ALTER TABLE `admin_users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `locked_until`');
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $statement->execute([$table, $column]);
        return $statement->fetchColumn() !== false;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $statement->execute([$table, $index]);
        return $statement->fetchColumn() !== false;
    }

    private function applied(string $version): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
        $statement->execute([$version]);
        return $statement->fetchColumn() !== false;
    }

    private function record(string $version): void
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
        $statement->execute([$version]);
    }
}
