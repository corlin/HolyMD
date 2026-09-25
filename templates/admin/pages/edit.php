<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = (string) $page->frontMatter->get('status', 'draft');
$publicationFormId = 'publication-form';
$activeNav = 'pages';
?>
<main
  class="studio"
  data-base-path="<?= $escape($basePath) ?>"
  data-autosave-url="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/draft') ?>"
  data-article-checksum="<?= $escape($pageChecksum) ?>"
>
<?php require dirname(__DIR__) . '/_nav.php'; ?>

  <section class="editor-panel">
    <div class="editor-topline">
      <a href="<?= $path('/admin/pages') ?>"><span class="icon" aria-hidden="true">arrow_back</span><?= __('All pages') ?></a>
      <output id="save-state" aria-live="polite" data-state="saved"><span class="icon" aria-hidden="true" data-save-icon>check_circle</span><span data-save-label><?= __('Source saved') ?></span></output>
    </div>
    <label>
      <?= __('Title') ?>
      <input id="article-title" name="title" form="<?= $publicationFormId ?>" value="<?= $escape($page->title) ?>">
    </label>
    <label>
      <?= __('Date') ?>
      <input id="article-date" name="date" form="<?= $publicationFormId ?>" type="date" value="<?= $escape((string) $page->frontMatter->get('date')) ?>">
    </label>
    <label>
      <?= __('Navigation order') ?> <span class="muted"><?= __('(integer, e.g. 1, 2 — leaves out of header/footer if blank)') ?></span>
      <input name="nav_order" form="<?= $publicationFormId ?>" type="number" step="1" value="<?= $escape((string) ($page->frontMatter->get('nav_order') ?? '')) ?>">
    </label>
    <label>
      <?= __('Description') ?> <span class="muted"><?= __('(optional summary)') ?></span>
      <input name="description" form="<?= $publicationFormId ?>" value="<?= $escape((string) ($page->frontMatter->get('description') ?? '')) ?>">
    </label>
    <label class="markdown-label" for="markdown-body">Markdown</label>
    <textarea id="markdown-body" name="body" form="<?= $publicationFormId ?>" spellcheck="true"><?= $escape($page->bodyMarkdown) ?></textarea>
    <input id="csrf-token" type="hidden" value="<?= $escape($csrfToken) ?>">
  </section>

  <section class="preview-panel">
    <div class="preview-heading">
      <p class="eyebrow"><?= __('Live preview') ?></p>
      <div class="publication-actions">
        <?php if ($status === 'published'): ?>
          <a href="<?= $path('/' . rawurlencode($page->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span><?= __('View public') ?></a>
        <?php endif; ?>
        <form id="<?= $publicationFormId ?>" data-publication-form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/publish') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input data-publication-checksum type="hidden" name="expected_checksum" value="<?= $escape($pageChecksum) ?>">
          <button id="publish-button" type="submit"><span class="icon" aria-hidden="true">publish</span><?= $status === 'published' ? __('Update public') : __('Publish') ?></button>
        </form>
        <?php if ($status === 'published'): ?>
          <form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/withdraw') ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <button type="submit" class="secondary"><span class="icon" aria-hidden="true">unpublished</span><?= __('Withdraw') ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <article id="markdown-preview" class="prose"></article>
  </section>

  <aside class="right-rail">
    <h2><?= __('Page details') ?></h2>
    <p class="muted"><?= __('Public route:') ?> <code>/<?= $escape($page->slug) ?>/</code></p>

    <details class="version-history-block">
      <summary class="eyebrow-summary"><?= __('Version history ({count})', ['count' => count($versions)]) ?></summary>
      <h2><?= __('Published versions') ?></h2>
      <p class="muted"><?= __('A restorable Markdown version is created only after a successful publish.') ?></p>
      <ul class="versions">
        <?php foreach ($versions as $version): ?>
          <li><form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/restore/' . $version) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><button type="submit"><span class="icon" aria-hidden="true">history</span><?= __('Restore {version}', ['version' => substr($version, 0, 8)]) ?></button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>

    <?php if ($status === 'draft'): ?>
      <details class="danger-zone">
        <summary><?= __('Delete draft') ?></summary>
        <p><?= str_replace('%%SLUG%%', '<strong>' . $escape($page->slug) . '</strong>', __('This permanently removes the Markdown page and all its published snapshots. Type {slug} to confirm.', ['slug' => '%%SLUG%%'])) ?></p>
        <form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/delete') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input name="confirm_slug" required autocomplete="off" placeholder="<?= $escape($page->slug) ?>">
          <button class="danger" type="submit"><span class="icon" aria-hidden="true">delete</span><?= __('Delete draft') ?></button>
        </form>
      </details>
    <?php endif; ?>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = \HolyMD\I18n\Translator::text('Edit {title}', ['title' => $page->title]);
require dirname(__DIR__) . '/layout.php';
?>
