<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'articles';
?>
<main class="admin-shell">
<?php require __DIR__ . '/../_nav.php'; ?>
<section class="article-index"><p class="eyebrow">Writing studio</p><h1>New article</h1><p>Start with a Markdown draft. Saving this form creates the article and its first restorable version.</p><form class="new-article-form" method="post" action="<?= $path('/admin/articles/new') ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><label>Title<input name="title" required></label><label>Slug <span class="muted">(optional)</span><input name="slug" pattern="[A-Za-z0-9 _-]+" aria-describedby="slug-help"></label><p id="slug-help" class="muted">A lowercase, URL-safe slug is generated from this value or the title.</p><label>Date<input name="date" type="date" value="<?= $escape($today) ?>" required></label><label class="markdown-label" for="markdown-body">Markdown</label><textarea id="markdown-body" name="body" spellcheck="true"></textarea><button type="submit"><span class="icon" aria-hidden="true">add</span>Create draft</button></form></section></main>
<?php $content = (string) ob_get_clean(); $title = 'New article'; require dirname(__DIR__) . '/layout.php';
