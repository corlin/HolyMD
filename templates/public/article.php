<?php
declare(strict_types=1);
$pageTitle = $article->title . ' | ' . $siteName;
$description = $summary;
$ogType = 'article';
$ogTitle = $article->title;
$ogDescription = $summary;
$ogUrl = $url;
$canonical = $url;
$showAlternates = false;
require __DIR__ . '/_head.php';
$skipTarget = '#article-content';
$skipLabel = 'Skip to article';
$activeNav = null;
require __DIR__ . '/_header.php';
?>
  <main><article><div class="article-page shell"><header class="article-header"><nav class="breadcrumb" aria-label="Breadcrumb"><a href="/">Writing</a><span class="icon" aria-hidden="true">chevron_right</span><span aria-current="page"><?= htmlspecialchars($article->title) ?></span></nav><?php if ($topics !== []): ?><p class="eyebrow"><?php foreach ($topics as $index => $topic): ?><?php if ($index > 0): ?><span aria-hidden="true"> · </span><?php endif; ?><a href="/topics/<?= htmlspecialchars((string) ($topicSlugs[$topic] ?? ''), ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($topic) ?></a><?php endforeach; ?></p><?php endif; ?><h1><?= htmlspecialchars($article->title) ?></h1><?php if ($summary !== ''): ?><p class="deck"><?= htmlspecialchars($summary) ?></p><?php endif; ?><dl class="reading-meta"><div><dt><span class="icon" aria-hidden="true">person</span>Written by</dt><dd rel="author"><?= htmlspecialchars($authorName) ?></dd></div><div><dt><span class="icon" aria-hidden="true">calendar_today</span>Published</dt><dd><time datetime="<?= htmlspecialchars($date) ?>"><?= htmlspecialchars($date) ?></time></dd></div><?php if ($modified !== $date): ?><div><dt><span class="icon" aria-hidden="true">update</span>Updated</dt><dd><time datetime="<?= htmlspecialchars($modified) ?>"><?= htmlspecialchars($modified) ?></time></dd></div><?php endif; ?><div><dt><span class="icon" aria-hidden="true">schedule</span>Reading time</dt><dd><?= (int) ($readingMinutes ?? 1) ?> min read</dd></div></dl></header>
    <?php if (!empty($toc) && count($toc) >= 3): ?>
    <details class="toc-box">
      <summary>Table of Contents</summary>
      <ol>
        <?php foreach ($toc as $item): ?>
          <li class="toc-level-<?= (int) $item['level'] ?>"><a href="#<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars($item['title']) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>
    <div id="article-content" class="prose"><?= $contentHtml ?></div>
    <?php if ($sources !== []): ?><section aria-labelledby="sources-heading"><div class="article-section sources"><h2 id="sources-heading">Sources</h2><ol><?php foreach ($sources as $source): ?><li><span class="icon" aria-hidden="true">link</span><a href="<?= htmlspecialchars($source) ?>" rel="cite noopener noreferrer"><?= htmlspecialchars($source) ?></a></li><?php endforeach; ?></ol></div></section><?php endif; ?>
    <aside class="author-box" aria-labelledby="author-heading"><p class="eyebrow">About the author</p><h2 id="author-heading"><?= htmlspecialchars($authorName) ?></h2><p><?= htmlspecialchars($siteName) ?> is a personal publication.</p><a class="text-link" href="/about/">Read more about <?= htmlspecialchars($authorName) ?> <span class="icon" aria-hidden="true">arrow_forward</span></a></aside>
    <?php if ($related !== []): ?><section class="article-section related" aria-labelledby="related-heading"><p class="eyebrow">Continue reading</p><h2 id="related-heading">Related articles</h2><div class="article-list"><?php foreach ($related as $relatedArticle): ?><article class="article-row"><div><h3><a href="/articles/<?= htmlspecialchars($relatedArticle->slug) ?>/"><?= htmlspecialchars($relatedArticle->title) ?></a></h3><?php $relatedSummary = (string) $relatedArticle->frontMatter->get('summary', ''); if ($relatedSummary !== ''): ?><p><?= htmlspecialchars($relatedSummary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="/articles/<?= htmlspecialchars($relatedArticle->slug) ?>/" aria-label="Read <?= htmlspecialchars($relatedArticle->title) ?>"><span class="icon" aria-hidden="true">arrow_forward</span></a></article><?php endforeach; ?></div></section><?php endif; ?>
  </div></article></main>
<?php require __DIR__ . '/_footer.php'; ?>
