<?php
declare(strict_types=1);
$pageTitle = $topic . ' | ' . $siteName;
$description = 'Writing about ' . $topic . ' by ' . $authorName;
$ogType = 'website';
$ogTitle = $pageTitle;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . $route;
$canonical = $ogUrl;
$jsonLd = null;
$showAlternates = false;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = 'Skip to content';
$activeNav = null;
require __DIR__ . '/_header.php';
?>
  <main id="main-content" class="shell page-intro"><nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= $basePath ?>/">Writing</a><span class="icon" aria-hidden="true">chevron_right</span><span aria-current="page"><?= htmlspecialchars($topic) ?></span></nav><p class="eyebrow">Topic</p><h1>Articles on <?= htmlspecialchars($topic) ?></h1><div class="article-list topic-articles"><?php foreach ($articles as $article): ?><article class="article-row"><div><p class="article-kicker"><time datetime="<?= htmlspecialchars((string) $article->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $article->frontMatter->get('date')) ?></time></p><h2><a href="<?= $basePath ?>/articles/<?= htmlspecialchars($article->slug) ?>/"><?= htmlspecialchars($article->title) ?></a></h2><?php $summary = (string) $article->frontMatter->get('summary', ''); if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="<?= $basePath ?>/articles/<?= htmlspecialchars($article->slug) ?>/" aria-label="Read <?= htmlspecialchars($article->title) ?>"><span class="icon" aria-hidden="true">arrow_forward</span></a></article><?php endforeach; ?></div></main>
<?php require __DIR__ . '/_footer.php'; ?>
