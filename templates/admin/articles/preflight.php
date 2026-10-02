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
  <p class="eyebrow"><?= __('Publication safety') ?></p>
  <h1><?= __('Publish preflight') ?></h1>
  <p><?= __('Review the exact candidate before it enters the immutable publication queue.') ?></p>

  <div class="preflight-score-grid">
    <div><span class="muted"><?= __('Current published GEO score') ?></span><strong><?= $preflight->currentScore === null ? __('Not published') : (int) $preflight->currentScore ?></strong></div>
    <div><span class="muted"><?= __('Candidate GEO score') ?></span><strong><?= (int) $preflight->candidateScore ?></strong></div>
  </div>

  <section aria-labelledby="preflight-changes"><h2 id="preflight-changes"><?= __('Changes') ?></h2><ul><?php foreach ($preflight->changes as $change): ?><li><?= __(ucwords(str_replace('_', ' ', $change))) ?></li><?php endforeach; ?></ul></section>
  <?php if (isset($bodyDiffHtml)): ?>
    <section class="preflight-diff-section" aria-labelledby="preflight-diff-heading">
      <details open>
        <summary id="preflight-diff-heading">
          <span class="icon" aria-hidden="true">difference</span>
          <span><?= __('Content differences') ?> (<?= ($isFirstPublication ?? false) ? __('First publication') : __('Compared with published version') ?>)</span>
        </summary>
        <?= $bodyDiffHtml ?>
      </details>
    </section>
  <?php endif; ?>
  <?php if ($preflight->blockers !== []): ?><section class="preflight-blockers" aria-labelledby="preflight-blockers"><h2 id="preflight-blockers"><?= __('Publication blockers') ?></h2><ul><?php foreach ($preflight->blockers as $blocker): ?><li><?= $escape($blocker) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
  <?php if ($preflight->warnings !== []): ?><section class="preflight-warnings" aria-labelledby="preflight-warnings"><h2 id="preflight-warnings"><?= __('Recommendations to acknowledge') ?></h2><ul><?php foreach ($preflight->warnings as $warning): ?><li><?= $escape($warning) ?></li><?php endforeach; ?></ul><p class="muted"><?= __('These checks are editorial guidance, not a guarantee of indexing, ranking, or AI citation.') ?></p></section><?php endif; ?>

  <div class="preflight-actions">
    <a href="<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/edit') ?>"><?= __('Return to editor') ?></a>
    <?php if ($preflight->canPublish()): ?>
      <div style="flex: 1; max-width: 480px;">
        <div class="publish-mode-selector" style="margin-bottom: 12px; display: flex; gap: 16px; font-size: 0.95rem;">
          <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
            <input type="radio" name="publish_mode" value="now" checked onchange="document.getElementById('schedule-options').hidden = true; document.getElementById('preflight-form').action = '<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/publish') ?>'; document.getElementById('btn-confirm-text').textContent = '<?= $escape(__('Confirm publication')) ?>';">
            <?= __('Publish immediately') ?>
          </label>
          <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
            <input type="radio" name="publish_mode" value="schedule" onchange="document.getElementById('schedule-options').hidden = false; document.getElementById('preflight-form').action = '<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/schedule') ?>'; document.getElementById('btn-confirm-text').textContent = '<?= $escape(__('Schedule publication')) ?>';">
            <?= __('Schedule publication') ?>
          </label>
        </div>

        <form id="preflight-form" method="post" action="<?= $path('/admin/articles/' . rawurlencode($candidate->slug) . '/publish') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input type="hidden" name="expected_checksum" value="<?= $escape($expectedChecksum) ?>">
          <input type="hidden" name="preflight_acknowledgement" value="<?= $escape($preflight->checksum) ?>">
          <?php foreach ($fields as $name => $value): ?><textarea hidden name="<?= $escape($name) ?>"><?= $escape($value) ?></textarea><?php endforeach; ?>

          <div id="schedule-options" hidden style="margin-bottom: 16px; padding: 12px; background: var(--surface-canvas); border-radius: 6px; border: 1px solid var(--border-subtle);">
            <label style="display: block; font-weight: 500; font-size: 0.85rem; margin-bottom: 6px;">
              <?= __('Publication date and time') ?>
              <span class="muted" style="font-weight: normal;">(<?= __('Site timezone: {tz}', ['tz' => $siteTimezone ?? 'UTC']) ?>)</span>
            </label>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
              <input type="datetime-local" name="scheduled_at" id="scheduled-at-input" style="padding: 6px 10px; font-size: 0.9rem;">
              <button type="button" class="secondary" style="font-size: 0.85rem; padding: 4px 8px;" onclick="
                const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0);
                const pad = n => String(n).padStart(2, '0');
                document.getElementById('scheduled-at-input').value = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
              "><?= __('Tomorrow at 09:00') ?></button>
            </div>
          </div>

          <button type="submit" id="btn-confirm-action"><span class="icon" aria-hidden="true">publish</span><span id="btn-confirm-text"><?= __('Confirm publication') ?></span></button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>
</main>
<?php $content = (string) ob_get_clean(); $title = \HolyMD\I18n\Translator::text('Publish preflight'); require dirname(__DIR__) . '/layout.php'; ?>
