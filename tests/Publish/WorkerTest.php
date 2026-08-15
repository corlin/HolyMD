<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Queue\Worker;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function test_worker_module_exists(): void
    {
        self::assertTrue(class_exists(Worker::class));
    }

    public function test_reports_when_no_job_is_available_after_recovering_leases(): void
    {
        $pdo = new ScriptedWorkerPdo(null);

        $result = (new Worker($pdo, dirname(__DIR__, 2), static fn (string $command): array => ['exitCode' => 0, 'output' => []]))->runOne();

        self::assertSame(0, $result->exitCode);
        self::assertSame("No queued jobs.\n", $result->stdout);
        self::assertSame('', $result->stderr);
        self::assertTrue($pdo->committed);
        self::assertGreaterThanOrEqual(9, count($pdo->executedSql), 'Lease recovery must update exhausted and retryable linked state.');
    }

    public function test_runs_a_version_bound_publish_and_completes_the_claimed_job(): void
    {
        $pdo = new ScriptedWorkerPdo([
            'id' => 17,
            'job_type' => 'build',
            'article_id' => 2,
            'article_version_id' => 8,
            'geo_review_id' => null,
            'build_id' => 5,
            'action' => 'publish',
            'attempts' => 0,
            'slug' => 'first-note',
            'snapshot_path' => 'publish-inputs/0123456789abcdef0123456789abcdef.md',
        ]);
        $commands = [];
        $executor = static function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['exitCode' => 0, 'output' => ['published']];
        };

        $result = (new Worker($pdo, dirname(__DIR__, 2), $executor))->runOne();

        self::assertSame(0, $result->exitCode);
        self::assertSame("Completed job 17.\n", $result->stdout);
        self::assertStringContainsString('holymd-build.php', $commands[0]);
        self::assertStringContainsString("--article 'first-note'", $commands[0]);
        self::assertStringContainsString("--version '0123456789abcdef0123456789abcdef'", $commands[0]);
        self::assertTrue($pdo->executedStatementContaining("status = 'succeeded'"));
    }

    public function test_retryable_child_failure_requeues_with_a_numeric_retry_flag(): void
    {
        $pdo = new ScriptedWorkerPdo([
            'id' => 19,
            'job_type' => 'geo_review',
            'article_id' => 2,
            'article_version_id' => 8,
            'geo_review_id' => 6,
            'build_id' => null,
            'action' => null,
            'attempts' => 1,
            'slug' => 'first-note',
            'snapshot_path' => 'review-inputs/0123456789abcdef0123456789abcdef.md',
        ]);
        $executor = static fn (string $command): array => ['exitCode' => 75, 'output' => ['provider unavailable']];

        $result = (new Worker($pdo, dirname(__DIR__, 2), $executor))->runOne();

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('RETRYABLE: provider unavailable', $result->stderr);
        self::assertTrue($pdo->executedWithFirstParameter(1), 'MySQL IF must receive an integer retry flag.');
        self::assertTrue($pdo->executedStatementContaining("status = IF(? AND attempts < 3, 'queued', 'failed')"));
    }
}

final class ScriptedWorkerPdo extends PDO
{
    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<array{sql:string,params:?array}> */
    public array $statements = [];

    public bool $committed = false;
    private bool $transaction = false;

    /** @param array<string,mixed>|null $job */
    public function __construct(public ?array $job) {}

    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; $this->committed = true; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'" . str_replace("'", "''", $string) . "'"; }
    public function exec(string $statement): int|false { $this->executedSql[] = $statement; return 1; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $this->executedSql[] = $query; return new ScriptedWorkerStatement($this, $query, $this->job); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ScriptedWorkerStatement($this, $query); }

    public function executedStatementContaining(string $fragment): bool
    {
        foreach ($this->statements as $statement) if (str_contains($statement['sql'], $fragment)) return true;
        return false;
    }

    public function executedWithFirstParameter(int $value): bool
    {
        foreach ($this->statements as $statement) if (($statement['params'][0] ?? null) === $value) return true;
        return false;
    }
}

final class ScriptedWorkerStatement extends PDOStatement
{
    /** @param array<string,mixed>|null $fetchValue */
    public function __construct(private ScriptedWorkerPdo $pdo, private string $sql, private ?array $fetchValue = null) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->statements[] = ['sql' => $this->sql, 'params' => $params];
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->fetchValue ?? false;
    }

    public function rowCount(): int { return 1; }
}
