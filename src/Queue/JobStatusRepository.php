<?php

declare(strict_types=1);

namespace HolyMD\Queue;

use PDO;

final readonly class JobStatusRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{job_type: string, status: string, count: int}> */
    public function summary(): array
    {
        $statement = $this->pdo->query('SELECT job_type, status, COUNT(*) AS count FROM jobs GROUP BY job_type, status ORDER BY job_type, status');
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['job_type' => (string) $row['job_type'], 'status' => (string) $row['status'], 'count' => (int) $row['count']];
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 25): array
    {
        // Clamped and interpolated: LIMIT ? behaves inconsistently across PDO drivers.
        $limit = max(1, min(250, $limit));
        $statement = $this->pdo->query('SELECT j.id, j.job_type, j.status, j.action, j.attempts, j.last_error, j.created_at, j.updated_at, a.slug FROM jobs j LEFT JOIN articles a ON a.id = j.article_id ORDER BY j.id DESC LIMIT ' . $limit);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
