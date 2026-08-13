<?php
declare(strict_types=1);
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = (string) $article->frontMatter->get('status', 'draft');
$publicationFormId = 'publication-form';
$activeNav = 'articles';
?>
<main
  class="studio"
  data-autosave-url="/admin/articles/<?= rawurlencode($article->slug) ?>/draft"
  data-article-checksum="<?= $escape($articleChecksum) ?>"
>
<?php require dirname(__DIR__) . '/_nav.php'; ?>

  <section class="editor-panel">
    <div class="editor-topline">
      <a href="/admin/articles"><span class="icon" aria-hidden="true">arrow_back</span>All articles</a>
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
          <a href="/articles/<?= rawurlencode($article->slug) ?>/"><span class="icon" aria-hidden="true">open_in_new</span>View public</a>
        <?php endif; ?>
        <form id="<?= $publicationFormId ?>" data-publication-form method="post" action="/admin/articles/<?= rawurlencode($article->slug) ?>/publish">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input data-publication-checksum type="hidden" name="expected_checksum" value="<?= $escape($articleChecksum) ?>">
          <button id="publish-button" type="submit"><span class="icon" aria-hidden="true">publish</span><?= $status === 'published' ? 'Update public' : 'Publish' ?></button>
        </form>
        <?php if ($status === 'published'): ?>
          <form method="post" action="/admin/articles/<?= rawurlencode($article->slug) ?>/withdraw">
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
      <h2>Restorable drafts</h2>
      <p class="muted">Each save stores a Markdown snapshot.</p>
      <ul class="versions">
        <?php foreach ($versions as $version): ?>
          <li><form method="post" action="/admin/articles/<?= rawurlencode($article->slug) ?>/restore/<?= $escape($version->value) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><button type="submit"><span class="icon" aria-hidden="true">history</span>Restore <?= $escape(substr($version->value, 0, 8)) ?></button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php if ($status === 'draft'): ?>
      <details class="danger-zone">
        <summary>Delete draft</summary>
        <p>This permanently removes the Markdown file. Type <strong><?= $escape($article->slug) ?></strong> to confirm.</p>
        <form method="post" action="/admin/articles/<?= rawurlencode($article->slug) ?>/delete">
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
        if (is_array($value)) return implode("\n", array_map('strval', $value));
        return (string) $value;
    };
    $structured = $article->frontMatter->get('structured_data');
    $structuredText = is_array($structured)
        ? json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        : (is_string($structured) ? $structured : '');
    ?>
    <details class="metadata-block">
      <summary class="eyebrow-summary">Metadata</summary>
      <h2>Front matter</h2>
      <p class="muted">Summary, topics and citations feed llms.txt, JSON-LD and search. List fields take one item per line.</p>
      <label>Summary<textarea name="summary" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('summary')) ?></textarea></label>
      <label>Topics (one per line)<textarea name="topics" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('topics')) ?></textarea></label>
      <label>Entities<textarea name="entities" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('entities')) ?></textarea></label>
      <label>FAQ<textarea name="faq" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('faq')) ?></textarea></label>
      <label>Sources (one URL per line)<textarea name="sources" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('sources')) ?></textarea></label>
      <label>Previous slugs (one per line)<textarea name="previous_slugs" data-metadata-input form="<?= $publicationFormId ?>"><?= $escape($metadataValue('previous_slugs')) ?></textarea></label>
      <label>Structured data (JSON object)<textarea name="structured_data" data-metadata-input form="<?= $publicationFormId ?>" spellcheck="false"><?= $escape($structuredText) ?></textarea></label>
    </details>
    <?php require dirname(__DIR__) . '/geo-panel.php'; ?>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Edit ' . $article->title;
require dirname(__DIR__) . '/layout.php';
