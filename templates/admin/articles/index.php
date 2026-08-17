<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$activeNav = 'articles';
?>
<main class="admin-shell">
<?php require __DIR__ . '/../_nav.php'; ?>
<section class="article-index"><p class="eyebrow">Writing studio</p><div class="article-index-heading"><div><h1>Articles</h1><p>Markdown remains the source of truth. Open a draft to write, preview, and publish deliberately.</p></div><a class="button-link" href="<?= $path('/admin/articles/new') ?>"><span class="icon" aria-hidden="true">add</span>New article</a></div><ul class="article-list"><?php foreach ($articles as $article): $status = (string) $article->frontMatter->get('status', 'draft'); $modified = filemtime($article->sourcePath); $gScore = $geoScores[$article->slug] ?? null; ?><li><div class="article-row-main"><a href="<?= $path('/admin/articles/' . rawurlencode($article->slug) . '/edit') ?>"><strong><?= htmlspecialchars($article->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></a><div class="article-badges"><?php if ($gScore !== null): ?><span class="geo-score-badge is-<?= $gScore->grade() ?>" title="GEO 评分: <?= $gScore->total ?>/100 (<?= $gScore->gradeLabel() ?>)"><span class="icon" aria-hidden="true">insights</span><?= $gScore->total ?>分</span><?php endif; ?><?php require __DIR__ . '/../_status_badge.php'; ?></div></div><div class="article-row-meta"><span>Modified <?= $modified === false ? 'unknown' : htmlspecialchars(date('Y-m-d H:i', $modified)) ?></span><?php if ($status === 'published'): ?><a href="<?= $path('/articles/' . rawurlencode($article->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span>View public</a><?php endif; ?></div></li><?php endforeach; ?></ul></section></main>
<?php $content = (string) ob_get_clean(); $title = 'Articles'; require dirname(__DIR__) . '/layout.php';
