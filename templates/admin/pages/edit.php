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
      <a href="<?= $path('/admin/pages') ?>"><span class="icon" aria-hidden="true">arrow_back</span>All pages</a>
      <output id="save-state" aria-live="polite" data-state="saved"><span class="icon" aria-hidden="true" data-save-icon>check_circle</span><span data-save-label>Source saved</span></output>
    </div>
    <label>
      Title
      <input id="article-title" name="title" form="<?= $publicationFormId ?>" value="<?= $escape($page->title) ?>">
    </label>
    <label>
      Date
      <input id="article-date" name="date" form="<?= $publicationFormId ?>" type="date" value="<?= $escape((string) $page->frontMatter->get('date')) ?>">
    </label>
    <label>
      Navigation order <span class="muted">(integer, e.g. 1, 2 — leaves out of header/footer if blank)</span>
      <input name="nav_order" form="<?= $publicationFormId ?>" type="number" step="1" value="<?= $escape((string) ($page->frontMatter->get('nav_order') ?? '')) ?>">
    </label>
    <label>
      Description <span class="muted">(optional summary)</span>
      <input name="description" form="<?= $publicationFormId ?>" value="<?= $escape((string) ($page->frontMatter->get('description') ?? '')) ?>">
    </label>
    <label class="markdown-label" for="markdown-body">Markdown</label>
    <textarea id="markdown-body" name="body" form="<?= $publicationFormId ?>" spellcheck="true"><?= $escape($page->bodyMarkdown) ?></textarea>
    <input id="csrf-token" type="hidden" value="<?= $escape($csrfToken) ?>">
  </section>

  <section class="preview-panel">
    <div class="preview-heading">
      <p class="eyebrow">Live preview</p>
      <div class="publication-actions">
        <?php if ($status === 'published'): ?>
          <a href="<?= $path('/' . rawurlencode($page->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span>View public</a>
        <?php endif; ?>
        <form id="<?= $publicationFormId ?>" data-publication-form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/publish') ?>">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input data-publication-checksum type="hidden" name="expected_checksum" value="<?= $escape($pageChecksum) ?>">
          <button id="publish-button" type="submit"><span class="icon" aria-hidden="true">publish</span><?= $status === 'published' ? 'Update public' : 'Publish' ?></button>
        </form>
        <?php if ($status === 'published'): ?>
          <form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/withdraw') ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <button type="submit" class="secondary"><span class="icon" aria-hidden="true">unpublished</span>Withdraw</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <article id="markdown-preview" class="prose"></article>
  </section>

  <aside class="right-rail">
    <h2>Page details</h2>
    <p class="muted">Public route: <code>/<?= $escape($page->slug) ?>/</code></p>
    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--line);">
      <h2>Delete page</h2>
      <form method="post" action="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/delete') ?>" onsubmit="return prompt('Type <?= $escape($page->slug) ?> to confirm deletion:') === '<?= $escape($page->slug) ?>';">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="confirm_slug" value="<?= $escape($page->slug) ?>">
        <button type="submit" class="danger" style="width:100%;"><span class="icon" aria-hidden="true">delete</span>Delete page</button>
      </form>
    </div>
  </aside>
</main>
<?php
$content = (string) ob_get_clean();
$title = 'Edit ' . $page->title;
require dirname(__DIR__) . '/layout.php';
?>
