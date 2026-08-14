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
    <details class="version-history-block">
      <summary class="eyebrow-summary">Version history (<?= count($versions) ?>)</summary>
      <h2>Published versions</h2>
      <p class="muted">A restorable Markdown version is created only after a successful publish.</p>
      <ul class="versions">
        <?php foreach ($versions as $version): ?>
          <li><form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/restore/' . $version->value) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><button type="submit"><span class="icon" aria-hidden="true">history</span>Restore <?= $escape(substr($version->value, 0, 8)) ?></button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php if ($status === 'draft'): ?>
      <details class="danger-zone">
        <summary>Delete draft</summary>
        <p>This permanently removes the Markdown file. Type <strong><?= $escape($article->slug) ?></strong> to confirm.</p>
        <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/delete') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input name="confirm_slug" required autocomplete="off">
          <button class="danger" type="submit"><span class="icon" aria-hidden="true">delete</span>Delete draft</button>
        </form>
      </details>
    <?php endif; ?>
    <?php
    $metadataValue = static function (string $key) use ($article): string {
        $value = $article->frontMatter->get($key);
        if ($value === null) return '';
        if (!is_array($value)) return (string) $value;
        $isStringList = array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && is_string($item), true);
        if ($isStringList) return implode("\n", $value);
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    };
    $geoField = static function (string $key, string $label, string $hint) use ($article, $escape, $publicationFormId, $metadataValue): string {
        $html = '<div class="geo-field" data-geo-field="' . $key . '"><label>' . $escape($label) . '<textarea name="' . $key . '" data-metadata-input form="' . $publicationFormId . '">' . $escape($metadataValue($key)) . '</textarea></label>';
        $html .= '<p class="muted">' . $escape($hint) . '</p><ol class="geo-field-suggestions" data-geo-suggestions aria-label="Suggested ' . $escape($label) . ' values"></ol></div>';
        return $html;
    };
    ?>
    <details class="metadata-block">
      <summary class="eyebrow-summary">Metadata</summary>
      <h2>Front matter</h2>
      <p class="muted">Summary, topics and citations feed llms.txt, JSON-LD and search. List fields take one item per line; structured values use JSON.</p>
      <?= $geoField('summary', 'Summary', 'One or two sentences describing the article.') ?>
      <label>Topics (one per line)<textarea name="topics" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('topics')) ?></textarea></label>
      <?= $geoField('entities', 'Entities', 'One entity per line.') ?>
      <?= $geoField('faq', 'FAQ', 'Question/answer pairs as JSON once the field is structured.') ?>
      <?= $geoField('sources', 'Sources', 'One URL per line.') ?>
      <?= $geoField('alt_text', 'Alt text', 'One description per line, matching image order.') ?>
      <?= $geoField('hierarchy', 'Hierarchy', 'Outline text, or JSON once the field is structured.') ?>
      <?= $geoField('internal_links', 'Internal links', 'One link per line.') ?>
      <label>Previous slugs (one per line)<textarea name="previous_slugs" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('previous_slugs')) ?></textarea></label>
      <?= $geoField('structured_data', 'Structured data', 'JSON object.') ?>
    </details>
    <?php require dirname(__DIR__) . '/geo-panel.php'; ?>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Edit ' . $article->title;
require dirname(__DIR__) . '/layout.php';
