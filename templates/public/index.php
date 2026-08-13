<?php
declare(strict_types=1);
$pageTitle = $siteName;
$description = $about !== '' ? $about : 'Writing by ' . $authorName;
$ogType = 'website';
$ogTitle = $siteName;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . '/';
$canonical = $ogUrl;
$jsonLd = null;
$showAlternates = true;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = 'Skip to content';
$activeNav = 'writing';
require __DIR__ . '/_header.php';
?>
  <main id="main-content">
    <section class="hero shell"><p class="eyebrow">Personal notes from <?= htmlspecialchars($authorName) ?></p><h1><?= htmlspecialchars($siteName) ?></h1><?php if ($about !== ''): ?><p class="intro"><?= nl2br(htmlspecialchars($about), false) ?></p><?php endif; ?><a class="text-link" href="<?= $basePath ?>/about/">About the author <span class="icon" aria-hidden="true">arrow_forward</span></a></section>
    <section class="shell section" aria-labelledby="featured-heading"><?php if ($articles !== []): $featured = $articles[0]; ?><div class="section-heading"><p class="eyebrow">Selected</p><h2 id="featured-heading">Featured writing</h2></div><article class="feature-card"><p class="article-kicker"><time datetime="<?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?></time></p><h3><a href="<?= $basePath ?>/articles/<?= htmlspecialchars($featured->slug) ?>/"><?= htmlspecialchars($featured->title) ?></a></h3><?php $summary = (string) $featured->frontMatter->get('summary', ''); if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?><a class="text-link" href="<?= $basePath ?>/articles/<?= htmlspecialchars($featured->slug) ?>/">Read the essay <span class="icon" aria-hidden="true">arrow_forward</span></a></article><?php else: ?><div class="section-heading"><p class="eyebrow">Writing</p><h2 id="featured-heading">No published writing yet</h2></div><?php endif; ?></section>
    <section class="shell section" aria-labelledby="latest-heading"><div class="section-heading"><p class="eyebrow">Archive</p><h2 id="latest-heading">Latest writing</h2></div><div class="article-list"><?php foreach (array_slice($articles, 1) as $article): ?><article class="article-row"><div><p class="article-kicker"><time datetime="<?= htmlspecialchars((string) $article->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $article->frontMatter->get('date')) ?></time></p><h3><a href="<?= $basePath ?>/articles/<?= htmlspecialchars($article->slug) ?>/"><?= htmlspecialchars($article->title) ?></a></h3><?php $summary = (string) $article->frontMatter->get('summary', ''); if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="<?= $basePath ?>/articles/<?= htmlspecialchars($article->slug) ?>/" aria-label="Read <?= htmlspecialchars($article->title) ?>"><span class="icon" aria-hidden="true">arrow_forward</span></a></article><?php endforeach; ?></div></section>
    <?php if ($topics !== []): ?><section class="shell section topic-section" aria-labelledby="topics-heading"><div class="section-heading"><p class="eyebrow">Browse</p><h2 id="topics-heading">Topics</h2></div><ul class="topic-list"><?php foreach ($topics as $topic => $topicArticles): $slug = (string) ($topicSlugs[$topic] ?? ''); ?><li><a href="<?= $basePath ?>/topics/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars((string) $topic) ?><span><?= count($topicArticles) ?></span></a></li><?php endforeach; ?></ul></section><?php endif; ?>
    <section class="shell section search-section" aria-labelledby="search-heading"><div class="section-heading"><p class="eyebrow">Search</p><h2 id="search-heading">Find writing</h2></div><div class="search-field" hidden><span class="icon" aria-hidden="true">search</span><input type="search" id="site-search" aria-label="Search articles" hidden></div><div id="search-results" class="article-list" hidden></div></section>
  </main>
<?php require __DIR__ . '/_footer.php'; ?>
