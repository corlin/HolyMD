<?php
declare(strict_types=1);
/** @var HolyMD\Content\ArticleDocument $article */ /** @var string $csrfToken */
$geoConfigured = (bool) (\HolyMD\Config\Env::get('HOLYMD_GEO_API_CREDENTIAL') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_KEY') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_ENDPOINT') && \HolyMD\Config\Env::get('HOLYMD_GEO_MODEL'));
$geoModel = (string) (\HolyMD\Config\Env::get('HOLYMD_GEO_MODEL') ?: 'AI');
?>
<section class="geo-compact-panel" data-geo-panel data-article-slug="<?= htmlspecialchars($article->slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <div class="geo-compact-header">
    <div class="geo-compact-title">
      <span class="icon" aria-hidden="true"><?= $geoConfigured ? 'auto_awesome' : 'power_off' ?></span>
      <span class="geo-compact-heading">GEO 自动优化</span>
      <span class="geo-provider-tag <?= $geoConfigured ? 'is-configured' : '' ?>">
        <?= $geoConfigured ? htmlspecialchars($geoModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '未配置' ?>
      </span>
    </div>
    <?php if ($geoConfigured): ?>
      <button type="button" class="btn-geo-trigger" data-geo-review title="后台已启用自动补全，也可点击立即手动分析">
        <span class="icon" aria-hidden="true">refresh</span>智能补全
      </button>
    <?php endif; ?>
  </div>
  <div data-geo-review-status class="geo-review-status" aria-live="polite"></div>
  <div class="geo-catchall" data-geo-catchall hidden><ol data-geo-catchall-list aria-label="Reference suggestions"></ol></div>
  <input type="hidden" data-geo-csrf value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</section>
