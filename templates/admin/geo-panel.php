<?php
declare(strict_types=1);
/** @var HolyMD\Content\ArticleDocument $article */
/** @var string $csrfToken */
/** @var HolyMD\Geo\GeoScore|null $geoScore */
$geoConfigured = (bool) (\HolyMD\Config\Env::get('HOLYMD_GEO_API_CREDENTIAL') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_KEY') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_ENDPOINT') && \HolyMD\Config\Env::get('HOLYMD_GEO_MODEL'));
$geoModel = (string) (\HolyMD\Config\Env::get('HOLYMD_GEO_MODEL') ?: 'AI');
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="geo-compact-panel geo-unified-card" data-geo-panel data-article-slug="<?= $escape($article->slug) ?>">
  <div class="geo-compact-header">
    <div class="geo-compact-title">
      <span class="icon" aria-hidden="true"><?= $geoConfigured ? 'auto_awesome' : 'power_off' ?></span>
      <span class="geo-compact-heading"><?= __('GEO engine') ?></span>
      <span class="geo-provider-tag <?= $geoConfigured ? 'is-configured' : '' ?>">
        <?= $geoConfigured ? $escape($geoModel) : __('Not configured') ?>
      </span>
    </div>
    <?php if ($geoConfigured): ?>
      <button type="button" class="btn-geo-trigger" data-geo-review title="<?= __('Analyze now and fill in missing metadata') ?>">
        <span class="icon" aria-hidden="true">refresh</span><?= __('Suggest metadata') ?>
      </button>
    <?php endif; ?>
  </div>

  <?php if (isset($geoScore)): ?>
    <details class="geo-score-inline-details" <?= $geoScore->total < 80 ? 'open' : '' ?>>
      <summary class="geo-score-summary-bar">
        <div class="geo-score-summary-left">
          <span class="icon" aria-hidden="true">insights</span>
          <span><?= __('Health score') ?></span>
        </div>
        <span class="geo-score-pill is-<?= $geoScore->grade() ?>"><?= $geoScore->total ?> · <?= $escape($geoScore->gradeLabel()) ?></span>
      </summary>
      <div class="geo-score-breakdown">
        <?php foreach ($geoScore->breakdown as $item): ?>
          <div class="geo-score-row <?= $item['earned'] === $item['weight'] ? 'is-full' : ($item['earned'] > 0 ? 'is-half' : 'is-zero') ?>">
            <div class="geo-score-row-info">
              <span class="geo-score-row-label"><?= $escape($item['label']) ?></span>
              <span class="geo-score-row-reason"><?= $escape($item['reason']) ?></span>
            </div>
            <span class="geo-score-row-points"><?= $item['earned'] ?> / <?= $item['weight'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <div data-geo-review-status class="geo-review-status" aria-live="polite"></div>
  <div class="geo-catchall" data-geo-catchall hidden><ol data-geo-catchall-list aria-label="<?= __('Reference suggestions') ?>"></ol></div>
  <input type="hidden" data-geo-csrf value="<?= $escape($csrfToken) ?>">
</section>

