<?php
declare(strict_types=1);
/** @var HolyMD\Content\ArticleDocument $article */ /** @var string $csrfToken */
$geoConfigured = (bool) (\HolyMD\Config\Env::get('HOLYMD_GEO_API_CREDENTIAL') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_KEY') && \HolyMD\Config\Env::get('HOLYMD_GEO_API_ENDPOINT') && \HolyMD\Config\Env::get('HOLYMD_GEO_MODEL'));
$geoHost = (string) (parse_url((string) \HolyMD\Config\Env::get('HOLYMD_GEO_API_ENDPOINT'), PHP_URL_HOST) ?: '');
?>
<section class="geo-panel" data-geo-panel data-article-slug="<?= htmlspecialchars($article->slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <div class="geo-panel-header">
    <div>
      <p class="eyebrow">AI Optimization</p>
      <h2>GEO Workbench</h2>
    </div>
    <span class="geo-provider-tag <?= $geoConfigured ? 'is-configured' : '' ?>">
      <span class="icon" aria-hidden="true"><?= $geoConfigured ? 'smart_toy' : 'power_off' ?></span>
      <?= $geoConfigured ? htmlspecialchars((string) \HolyMD\Config\Env::get('HOLYMD_GEO_MODEL'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Unconfigured' ?>
    </span>
  </div>
  <p class="muted">Prose analysis for metadata, entities, questions & structured data. Article prose is never changed.</p>
  <button type="button" data-geo-review <?= $geoConfigured ? '' : 'disabled' ?>>
    <span class="icon" aria-hidden="true">auto_awesome</span>Request GEO review
  </button>
  <div data-geo-review-status class="geo-review-status" aria-live="polite"></div>
  <div class="geo-summary-bar" data-geo-summary-bar hidden>
    <div class="geo-summary-info">
      <span class="geo-pending-count" data-geo-pending-count>0 proposals pending</span>
    </div>
    <div class="geo-batch-actions">
      <button type="button" class="btn-geo-accept-all" data-geo-accept-all title="Fill all matching metadata fields and save">
        <span class="icon" aria-hidden="true">done_all</span>Accept all
      </button>
      <button type="button" class="btn-geo-reject-all" data-geo-reject-all title="Reject all pending proposals">
        <span class="icon" aria-hidden="true">close</span>Reject all
      </button>
    </div>
  </div>
  <div class="geo-catchall" data-geo-catchall hidden>
    <h3 class="geo-catchall-title">Reference suggestions</h3>
    <p class="muted">Proposals that do not map to a single editable field appear here.</p>
    <ol data-geo-catchall-list aria-label="Reference suggestions"></ol>
  </div>
  <input type="hidden" data-geo-csrf value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</section>
