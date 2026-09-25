<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'articles';
?>
<main class="admin-shell">
<?php require __DIR__ . '/../_nav.php'; ?>
<section class="article-index"><p class="eyebrow"><?= __('Writing studio') ?></p><h1><?= __('New article') ?></h1><p><?= __('Start with a Markdown draft. A restorable content version is created only after a successful publish.') ?></p><form class="new-article-form" method="post" action="<?= $path('/admin/articles/new') ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><label><?= __('Title') ?><input name="title" required></label><label><?= __('Slug') ?> <span class="muted"><?= __('(optional)') ?></span><input name="slug" pattern="[A-Za-z0-9 _\-]+" aria-describedby="slug-help"></label><p id="slug-help" class="muted"><?= __('A lowercase, URL-safe slug is generated from this value or the title.') ?></p><label><?= __('Date') ?><input name="date" type="date" value="<?= $escape($today) ?>" required></label><label class="markdown-label" for="markdown-body">Markdown</label><textarea id="markdown-body" name="body" spellcheck="true"></textarea><button type="submit"><span class="icon" aria-hidden="true">add</span><?= __('Create draft') ?></button></form></section></main>
<?php $content = (string) ob_get_clean(); $title = \HolyMD\I18n\Translator::text('New article'); require dirname(__DIR__) . '/layout.php';
