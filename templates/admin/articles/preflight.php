<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'articles';
?>
<main class="admin-shell">
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<section class="article-index preflight-page">
  <p class="eyebrow">Publication safety</p>
  <h1>Publish preflight</h1>
  <p>Review the exact candidate before it enters the immutable publication queue.</p>

  <div class="preflight-score-grid">
    <div><span class="muted">Current published GEO score</span><strong><?= $preflight->currentScore === null ? 'Not published' : (int) $preflight->currentScore ?></strong></div>
    <div><span class="muted">Candidate GEO score</span><strong><?= (int) $preflight->candidateScore ?></strong></div>
  </div>

  <section aria-labelledby="preflight-changes"><h2 id="preflight-changes">Changes</h2><ul><?php foreach ($preflight->changes as $change): ?><li><?= $escape(ucwords(str_replace('_', ' ', $change))) ?></li><?php endforeach; ?></ul></section>
  <?php if ($preflight->blockers !== []): ?><section class="preflight-blockers" aria-labelledby="preflight-blockers"><h2 id="preflight-blockers">Publication blockers</h2><ul><?php foreach ($preflight->blockers as $blocker): ?><li><?= $escape($blocker) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
  <?php if ($preflight->warnings !== []): ?><section class="preflight-warnings" aria-labelledby="preflight-warnings"><h2 id="preflight-warnings">Recommendations to acknowledge</h2><ul><?php foreach ($preflight->warnings as $warning): ?><li><?= $escape($warning) ?></li><?php endforeach; ?></ul><p class="muted">These checks are editorial guidance, not a guarantee of indexing, ranking, or AI citation.</p></section><?php endif; ?>

  <div class="preflight-actions">
    <a href="<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/edit') ?>">Return to editor</a>
    <?php if ($preflight->canPublish()): ?>
      <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/publish') ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="expected_checksum" value="<?= $escape($expectedChecksum) ?>">
        <input type="hidden" name="preflight_acknowledgement" value="<?= $escape($preflight->checksum) ?>">
        <?php foreach ($fields as $name => $value): ?><textarea hidden name="<?= $escape($name) ?>"><?= $escape($value) ?></textarea><?php endforeach; ?>
        <button type="submit"><span class="icon" aria-hidden="true">publish</span>Confirm publication</button>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
<?php $content = (string) ob_get_clean(); $title = 'Publish preflight'; require dirname(__DIR__) . '/layout.php'; ?>
