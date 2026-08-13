<?php declare(strict_types=1); ob_start(); $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $activeNav = 'jobs'; $jobIcons = ['queued' => 'schedule', 'running' => 'sync', 'succeeded' => 'check_circle', 'failed' => 'error']; ?>
<main class="admin-shell">
<?php require __DIR__ . '/_nav.php'; ?>
<section class="article-index">
  <p class="eyebrow">Build queue</p>
  <h1>Jobs</h1>
  <p class="muted">Publications and GEO reviews are processed by the cron worker. Jobs that fail permanently appear here; check <code>last_error</code> and retry from the editor.</p>
  <?php if ($summary === []): ?>
    <p>No jobs recorded yet.</p>
  <?php else: ?>
    <div class="jobs-summary">
      <?php foreach ($summary as $row): ?>
        <span class="status status-<?= $escape($row['status']) ?>"><span class="icon" aria-hidden="true"><?= $jobIcons[$row['status']] ?? 'circle' ?></span><?= $escape($row['job_type']) ?> <?= $escape($row['status']) ?>: <?= (int) $row['count'] ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($recent !== []): ?>
    <ul class="article-list">
      <?php foreach ($recent as $job): ?>
        <li>
          <div class="article-row-main">
            <strong>#<?= (int) $job['id'] ?> <?= $escape((string) $job['job_type']) ?></strong>
            <span class="status status-<?= $escape((string) $job['status']) ?>"><span class="icon" aria-hidden="true"><?= $jobIcons[$job['status']] ?? 'circle' ?></span><?= $escape((string) $job['status']) ?></span>
          </div>
          <div class="article-row-meta">
            <span><?= $escape((string) ($job['slug'] ?? '')) ?><?= $job['action'] === null ? '' : ' · ' . $escape((string) $job['action']) ?> · <?= (int) $job['attempts'] ?> attempt(s) · <?= $escape((string) $job['created_at']) ?></span>
          </div>
          <?php if (is_string($job['last_error']) && $job['last_error'] !== ''): ?>
            <p class="job-error"><span class="icon" aria-hidden="true">error</span><?= $escape(substr($job['last_error'], 0, 300)) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
</main>
<?php $content = (string) ob_get_clean(); $title = 'Jobs'; require __DIR__ . '/layout.php'; ?>
