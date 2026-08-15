<?php

declare(strict_types=1);

namespace HolyMD\Database;

use PDO;
use RuntimeException;

final readonly class Migrator
{
    public function __construct(private PDO $pdo, private string $projectRoot)
    {
    }

    public function migrate(): MigrationResult
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('HolyMD migrations require MySQL.');
        }

        $installed = !$this->tableExists('admin_users');
        $schemaPath = $this->projectRoot . '/database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new RuntimeException('Unable to read database/schema.sql.');
        }

        $this->executeSql($schema);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `schema_migrations` (`version` VARCHAR(191) NOT NULL, `applied_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (`version`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $applied = 0;
        $migrations = glob($this->projectRoot . '/database/migrations/*.sql') ?: [];
        sort($migrations);

        foreach ($migrations as $migrationPath) {
            $version = basename($migrationPath, '.sql');
            if ($this->applied($version)) {
                continue;
            }

            $sql = file_get_contents($migrationPath);
            if (is_string($sql) && trim($sql) !== '') {
                $this->executeSql($sql);
            }

            $this->record($version);
            $applied++;
        }

        return new MigrationResult($installed, $applied);
    }

    private function executeSql(string $sql): void
    {
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
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
