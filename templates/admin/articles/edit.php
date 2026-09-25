<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn (string $source): string => \HolyMD\I18n\Translator::text($source);
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
    <h1 class="sr-only"><?= __('Edit {title}', ['title' => $article->title]) ?></h1>
    <div class="editor-topline">
      <a href="<?= $path('/admin/articles') ?>"><span class="icon" aria-hidden="true">arrow_back</span><?= __('All articles') ?></a>
      <output id="save-state" aria-live="polite" data-state="saved"><span class="icon" aria-hidden="true" data-save-icon>check_circle</span><span data-save-label><?= __('Source saved') ?></span></output>
    </div>
    <label>
      <?= __('Title') ?>
      <input id="article-title" name="title" form="<?= $publicationFormId ?>" value="<?= $escape($article->title) ?>">
    </label>
    <label>
      <?= __('Date') ?>
      <input id="article-date" name="date" form="<?= $publicationFormId ?>" type="date" value="<?= $escape((string) $article->frontMatter->get('date')) ?>">
    </label>
    <label class="markdown-label" for="markdown-body">Markdown</label>
    <textarea id="markdown-body" name="body" form="<?= $publicationFormId ?>" spellcheck="true"><?= $escape($article->bodyMarkdown) ?></textarea>
    <input id="csrf-token" type="hidden" value="<?= $escape($csrfToken) ?>">
  </section>

  <section class="preview-panel">
    <div class="preview-heading">
      <p class="eyebrow"><?= __('Live preview') ?></p>
      <div class="publication-actions">
        <?php if ($status === 'published'): ?>
          <a href="<?= $path('/articles/' . rawurlencode($article->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span><?= __('View public') ?></a>
        <?php endif; ?>
        <form id="<?= $publicationFormId ?>" data-publication-form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/preflight') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input data-publication-checksum type="hidden" name="expected_checksum" value="<?= $escape($articleChecksum) ?>">
          <button id="publish-button" type="submit"><span class="icon" aria-hidden="true">publish</span><?= $status === 'published' ? __('Update public') : __('Publish') ?></button>
        </form>
        <?php if ($status === 'published'): ?>
          <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/withdraw') ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <button type="submit" class="secondary"><span class="icon" aria-hidden="true">unpublished</span><?= __('Withdraw') ?></button>
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

    <div class="core-metadata-block">
      <div class="meta-field geo-field" data-geo-field="summary" data-meta-field="summary">
        <label><?= __('Summary') ?> <span class="muted"><?= __('(used for RSS, llms.txt, and share descriptions)') ?></span>
          <textarea name="summary" data-metadata-input form="<?= $publicationFormId ?>" placeholder="<?= __('A concise summary of the article…') ?>"><?= $escape($metadataValue('summary')) ?></textarea>
        </label>
      </div>
      <div class="meta-field geo-field" data-geo-field="topics" data-meta-field="topics">
        <label><?= __('Topics') ?> <span class="muted"><?= __('(one per line)') ?></span>
          <textarea name="topics" data-metadata-input form="<?= $publicationFormId ?>" placeholder="<?= __('e.g. Architecture') ?>&#10;PHP"><?= $escape($metadataValue('topics')) ?></textarea>
        </label>
      </div>
    </div>

    <details class="advanced-geo-block" data-advanced-geo-block>
      <summary class="eyebrow-summary">
        <span><?= __('Advanced GEO metadata') ?></span>
        <span class="advanced-geo-badge" data-advanced-geo-badge hidden><?= __('{count} configured', ['count' => 0]) ?></span>
      </summary>
      <p class="muted"><?= __('External and internal links in the body are detected automatically. Use these fields to fine-tune or add FAQ and Schema data.') ?></p>
      <?= $metaField('entities', $t('Entities'), $t('One entity or keyword per line.')) ?>
      <?= $metaField('faq', $t('FAQ'), $t('Question and answer pairs as JSON.')) ?>
      <?= $metaField('sources', $t('Sources'), $t('One URL per line.')) ?>
      <?= $metaField('alt_text', $t('Alt text'), $t('One image description per line.')) ?>
      <?= $metaField('hierarchy', $t('Hierarchy'), $t('Outline text or JSON.')) ?>
      <?= $metaField('internal_links', $t('Internal links'), $t('One site link per line.')) ?>
      <?= $metaField('previous_slugs', $t('Previous slugs'), $t('One former slug per line; each redirects here.')) ?>
      <?= $metaField('structured_data', $t('Structured data (JSON-LD)'), $t('A Schema.org JSON object.')) ?>
    </details>

    <details class="version-history-block">
      <summary class="eyebrow-summary"><?= __('Version history ({count})', ['count' => count($versions)]) ?></summary>
      <h2><?= __('Published versions') ?></h2>
      <p class="muted"><?= __('A restorable Markdown version is created only after a successful publish.') ?></p>
      <ul class="versions">
        <?php foreach ($versions as $version): ?>
          <li><form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/restore/' . $version) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><button type="submit"><span class="icon" aria-hidden="true">history</span><?= __('Restore {version}', ['version' => substr($version, 0, 8)]) ?></button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php if (in_array($status, ['draft', 'withdrawn'], true)): ?>
      <details class="danger-zone">
        <summary><?= __('Delete draft') ?></summary>
        <p><?= str_replace('%%SLUG%%', '<strong>' . $escape($article->slug) . '</strong>', __('This permanently removes the Markdown file and all its published snapshots. Type {slug} to confirm.', ['slug' => '%%SLUG%%'])) ?></p>
        <form method="post" action="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/delete') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input name="confirm_slug" required autocomplete="off" placeholder="<?= $escape($article->slug) ?>">
          <button class="danger" type="submit"><span class="icon" aria-hidden="true">delete</span><?= __('Delete draft') ?></button>
        </form>
      </details>
    <?php endif; ?>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = \HolyMD\I18n\Translator::text('Edit {title}', ['title' => $article->title]);
require dirname(__DIR__) . '/layout.php';
