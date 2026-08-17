<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = (string) $article->frontMatter->get('status', 'draft');
$publicationFormId = 'publication-form';
$activeNav = 'articles';
?>
<main
  class="studio"
  data-base-path="<?= $escape($basePath) ?>"
  data-autosave-url="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/draft') ?>"
  data-article-checksum="<?= $escape($articleChecksum) ?>"
>
<?php require dirname(__DIR__) . '/_nav.php'; ?>

  <section class="editor-panel">
    <div class="editor-topline">
      <a href="<?= $path('/admin/articles') ?>"><span class="icon" aria-hidden="true">arrow_back</span>All articles</a>
      <output id="save-state" aria-live="polite" data-state="saved"><span class="icon" aria-hidden="true" data-save-icon>check_circle</span><span data-save-label>Source saved</span></output>
    </div>
    <label>
      Title
      <input id="article-title" name="title" form="<?= $publicationFormId ?>" value="<?= $escape($article->title) ?>">
    </label>
    <label>
      Date
      <input id="article-date" name="date" form="<?= $publicationFormId ?>" type="date" value="<?= $escape((string) $article->frontMatter->get('date')) ?>">
    </label>
    <label class="markdown-label" for="markdown-body">Markdown</label>
    <textarea id="markdown-body" name="body" form="<?= $publicationFormId ?>" spellcheck="true"><?= $escape($article->bodyMarkdown) ?></textarea>
    <input id="csrf-token" type="hidden" value="<?= $escape($csrfToken) ?>">
  </section>

  <section class="preview-panel">
    <div class="preview-heading">
      <p class="eyebrow">Live preview</p>
      <div class="publication-actions">
        <?php if ($status === 'published'): ?>
          <a href="<?= $path('/articles/' . rawurlencode($article->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span>View public</a>
        <?php endif; ?>
        <form id="<?= $publicationFormId ?>" data-publication-form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/publish') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input data-publication-checksum type="hidden" name="expected_checksum" value="<?= $escape($articleChecksum) ?>">
          <button id="publish-button" type="submit"><span class="icon" aria-hidden="true">publish</span><?= $status === 'published' ? 'Update public' : 'Publish' ?></button>
        </form>
        <?php if ($status === 'published'): ?>
          <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/withdraw') ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <button type="submit" class="secondary"><span class="icon" aria-hidden="true">unpublished</span>Withdraw</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <article id="markdown-preview" class="prose"></article>
  </section>

  <aside class="right-rail">
    <?php
    $metadataValue = static function (string $key) use ($article): string {
        $value = $article->frontMatter->get($key);
        if ($value === null) return '';
        if (!is_array($value)) return (string) $value;
        $isStringList = array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && is_string($item), true);
        if ($isStringList) return implode("\n", $value);
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    };
    $metaField = static function (string $key, string $label, string $hint) use ($article, $escape, $publicationFormId, $metadataValue): string {
        return '<div class="meta-field geo-field" data-geo-field="' . $key . '" data-meta-field="' . $key . '"><label>' . $escape($label) . '<textarea name="' . $key . '" data-metadata-input form="' . $publicationFormId . '">' . $escape($metadataValue($key)) . '</textarea></label><p class="muted">' . $escape($hint) . '</p></div>';
    };
    ?>
    <?php require dirname(__DIR__) . '/geo-panel.php'; ?>

    <?php if (isset($geoScore)): ?>
      <details class="geo-scorecard-block" <?= $geoScore->total < 80 ? 'open' : '' ?>>
        <summary class="eyebrow-summary">
          <span>🎯 GEO 评分：<strong><?= $geoScore->total ?></strong>/100 (<?= $geoScore->gradeLabel() ?>)</span>
          <span class="geo-score-pill is-<?= $geoScore->grade() ?>"><?= $geoScore->total ?>分</span>
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

    <div class="core-metadata-block">
      <div class="meta-field geo-field" data-geo-field="summary" data-meta-field="summary">
        <label>Summary <span class="muted">(摘要，用于 RSS、llms.txt 及分享描述)</span>
          <textarea name="summary" data-metadata-input form="<?= $publicationFormId ?>" placeholder="文章精简摘要..."><?= $escape($metadataValue('summary')) ?></textarea>
        </label>
      </div>
      <div class="meta-field geo-field" data-geo-field="topics" data-meta-field="topics">
        <label>Topics <span class="muted">(话题/分类，每行一个)</span>
          <textarea name="topics" data-metadata-input form="<?= $publicationFormId ?>" placeholder="例如：Architecture&#10;PHP"><?= $escape($metadataValue('topics')) ?></textarea>
        </label>
      </div>
    </div>

    <details class="advanced-geo-block" data-advanced-geo-block>
      <summary class="eyebrow-summary">
        <span>⚙️ 高级 / GEO 结构化数据</span>
        <span class="advanced-geo-badge" data-advanced-geo-badge hidden>0 项已配置</span>
      </summary>
      <p class="muted">保存或发布时，缺失的字段将由 GEO 模型自动静默填充。你也可以在此直接手工微调。</p>
      <?= $metaField('entities', 'Entities（命名实体/关键词）', '每行一个关键词，用于搜索引擎结构化理解。') ?>
      <?= $metaField('faq', 'FAQ（常见问答候选）', 'JSON 格式问答对。') ?>
      <?= $metaField('sources', 'Sources（引用来源）', '每行一条 URL。') ?>
      <?= $metaField('alt_text', 'Alt text（图片描述）', '每行一条图片描述。') ?>
      <?= $metaField('hierarchy', 'Hierarchy（大纲结构）', '大纲结构或 JSON。') ?>
      <?= $metaField('internal_links', 'Internal links（内链推荐）', '每行一条站点内链。') ?>
      <?= $metaField('previous_slugs', 'Previous slugs（历史别名跳转）', '每行一个历史别名。') ?>
      <?= $metaField('structured_data', 'Structured data（JSON-LD）', '标准结构化数据对象。') ?>
    </details>

    <details class="version-history-block">
      <summary class="eyebrow-summary">Version history (<?= count($versions) ?>)</summary>
      <h2>Published versions</h2>
      <p class="muted">A restorable Markdown version is created only after a successful publish.</p>
      <ul class="versions">
        <?php foreach ($versions as $version): ?>
          <li><form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/restore/' . $version) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><button type="submit"><span class="icon" aria-hidden="true">history</span>Restore <?= $escape(substr($version, 0, 8)) ?></button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php if ($status === 'draft'): ?>
      <details class="danger-zone">
        <summary>Delete draft</summary>
        <p>This permanently removes the Markdown file and all its published snapshots. Type <strong><?= $escape($article->slug) ?></strong> to confirm.</p>
        <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/delete') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input name="confirm_slug" required autocomplete="off" placeholder="<?= $escape($article->slug) ?>">
          <button class="danger" type="submit"><span class="icon" aria-hidden="true">delete</span>Delete draft</button>
        </form>
      </details>
    <?php endif; ?>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Edit ' . $article->title;
require dirname(__DIR__) . '/layout.php';
