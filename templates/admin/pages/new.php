<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'pages';
?>
<main class="admin-shell">
<?php require __DIR__ . '/../_nav.php'; ?>
<section class="article-index">
  <p class="eyebrow"><?= __('Site content') ?></p>
  <h1><?= __('New page') ?></h1>
  <p><?= __('Create a standalone custom Markdown page.') ?></p>
  <form class="new-article-form" method="post" action="<?= $path('/admin/pages/new') ?>">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <label><?= __('Title') ?>
      <input name="title" required>
    </label>
    <label><?= __('Slug') ?> <span class="muted"><?= __('(optional)') ?></span>
      <input name="slug" pattern="[A-Za-z0-9 _\-]+" aria-describedby="slug-help">
    </label>
    <p id="slug-help" class="muted"><?= __('A lowercase, URL-safe slug is generated from this value or the title. The page is served at /<slug>/.') ?></p>
    <label><?= __('Date') ?>
      <input name="date" type="date" value="<?= $escape($today) ?>" required>
    </label>
    <label><?= __('Navigation order') ?> <span class="muted"><?= __('(optional integer, e.g. 1, 2 — leaves out of header/footer if blank)') ?></span>
      <input name="nav_order" type="number" step="1">
    </label>
    <label><?= __('Description') ?> <span class="muted"><?= __('(optional summary for meta description)') ?></span>
      <input name="description">
    </label>
    <label class="markdown-label" for="markdown-body">Markdown</label>
    <textarea id="markdown-body" name="body" spellcheck="true"></textarea>
    <button type="submit"><span class="icon" aria-hidden="true">add</span><?= __('Create draft page') ?></button>
  </form>
</section>
</main>
<?php
$content = (string) ob_get_clean();
$title = \HolyMD\I18n\Translator::text('New page');
require dirname(__DIR__) . '/layout.php';
?>
