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
$showReadingProgress = true;
require __DIR__ . '/_header.php';
?>
  <main class="reading-page"><article><div class="reading-layout shell">
    <?php if (!empty($toc) && count($toc) >= 3): ?>
    <aside class="toc-rail" aria-labelledby="desktop-toc-heading">
      <div class="toc-rail-inner">
        <p class="eyebrow">In this essay</p>
        <h2 id="desktop-toc-heading">Contents</h2>
        <ol>
          <?php foreach ($toc as $item): ?>
            <li class="toc-level-<?= (int) $item['level'] ?>"><a href="#<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars($item['title']) ?></a></li>
          <?php endforeach; ?>
        </ol>
      </div>
    </aside>
    <?php endif; ?>
    <div class="article-page">
      <header class="article-header">
        <?php if ($topics !== []): ?><p class="eyebrow article-topics"><?php foreach ($topics as $index => $topic): ?><?php if ($index > 0): ?><span aria-hidden="true"> · </span><?php endif; ?><a href="<?= $basePath ?>/topics/<?= htmlspecialchars((string) ($topicSlugs[$topic] ?? ''), ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($topic) ?></a><?php endforeach; ?></p><?php endif; ?>
        <h1><?= htmlspecialchars($article->title) ?></h1>
        <?php if ($summary !== ''): ?><p class="deck"><?= htmlspecialchars($summary) ?></p><?php endif; ?>
        <dl class="reading-meta"><div><dt><span class="icon" aria-hidden="true">person</span>Written by</dt><dd rel="author"><?= htmlspecialchars($authorName) ?></dd></div><div><dt><span class="icon" aria-hidden="true">calendar_today</span>Published</dt><dd><time datetime="<?= htmlspecialchars($date) ?>"><?= htmlspecialchars($date) ?></time></dd></div><?php if ($modified !== $date): ?><div><dt><span class="icon" aria-hidden="true">update</span>Updated</dt><dd><time datetime="<?= htmlspecialchars($modified) ?>"><?= htmlspecialchars($modified) ?></time></dd></div><?php endif; ?><div><dt><span class="icon" aria-hidden="true">schedule</span>Reading time</dt><dd><?= (int) ($readingMinutes ?? 1) ?> min read</dd></div></dl>
      </header>
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
    <?php if ($internalLinks !== []): ?><section aria-labelledby="internal-links-heading"><div class="article-section related"><h2 id="internal-links-heading">Related links</h2><ul><?php foreach ($internalLinks as $internalLink): ?><li><a href="<?= htmlspecialchars($internalLink, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($internalLink) ?></a></li><?php endforeach; ?></ul></div></section><?php endif; ?>
    <?php if ($faq !== []): ?><section aria-labelledby="faq-heading"><div class="article-section"><h2 id="faq-heading">Frequently asked questions</h2><?php foreach ($faq as $entry): ?><details><summary><?= htmlspecialchars($entry['question']) ?></summary><p><?= htmlspecialchars($entry['answer']) ?></p></details><?php endforeach; ?></div></section><?php endif; ?>
    <aside class="author-box" aria-labelledby="author-heading"><p class="eyebrow">About the author</p><h2 id="author-heading"><?= htmlspecialchars($authorName) ?></h2><p><?= htmlspecialchars($siteName) ?> is a personal publication.</p><a class="text-link" href="<?= $basePath ?>/about/">Read more about <?= htmlspecialchars($authorName) ?> <span class="icon" aria-hidden="true">arrow_forward</span></a></aside>
    <?php if ($related !== []): ?><section class="article-section related" aria-labelledby="related-heading"><p class="eyebrow">Continue reading</p><h2 id="related-heading">Related articles</h2><div class="article-list"><?php foreach ($related as $relatedArticle): ?><article class="article-row"><div><h3><a href="<?= $basePath ?>/articles/<?= htmlspecialchars($relatedArticle->slug) ?>/"><?= htmlspecialchars($relatedArticle->title) ?></a></h3><?php $relatedSummary = (string) $relatedArticle->frontMatter->get('summary', ''); if ($relatedSummary !== ''): ?><p><?= htmlspecialchars($relatedSummary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="<?= $basePath ?>/articles/<?= htmlspecialchars($relatedArticle->slug) ?>/" aria-label="Read <?= htmlspecialchars($relatedArticle->title) ?>"><span class="icon" aria-hidden="true">arrow_forward</span></a></article><?php endforeach; ?></div></section><?php endif; ?>
    </div>
  </div></article></main>
  <dialog class="image-viewer" aria-labelledby="image-viewer-title">
    <div class="image-viewer-shell">
      <header class="image-viewer-toolbar">
        <div class="image-viewer-heading"><span class="icon" aria-hidden="true">image</span><div><strong id="image-viewer-title">Image preview</strong><span class="image-viewer-count" aria-live="polite"></span></div></div>
        <div class="image-viewer-actions" role="group" aria-label="Image controls">
          <button type="button" data-image-zoom="out" aria-label="Zoom out" title="Zoom out"><span class="icon" aria-hidden="true">zoom_out</span></button>
          <button type="button" class="image-viewer-scale" data-image-zoom="reset" aria-label="Reset zoom" title="Reset zoom">100%</button>
          <button type="button" data-image-zoom="in" aria-label="Zoom in" title="Zoom in"><span class="icon" aria-hidden="true">zoom_in</span></button>
          <a class="image-viewer-download" href="" download aria-label="Download original image" title="Download original"><span class="icon" aria-hidden="true">download</span></a>
          <button type="button" data-image-close aria-label="Close image preview" title="Close"><span class="icon" aria-hidden="true">close</span></button>
        </div>
      </header>
      <div class="image-viewer-stage">
        <button type="button" class="image-viewer-nav image-viewer-prev" data-image-nav="prev" aria-label="Previous image"><span class="icon" aria-hidden="true">chevron_left</span></button>
        <div class="image-viewer-canvas"><img src="" alt=""></div>
        <button type="button" class="image-viewer-nav image-viewer-next" data-image-nav="next" aria-label="Next image"><span class="icon" aria-hidden="true">chevron_right</span></button>
      </div>
      <p class="image-viewer-caption"></p>
    </div>
  </dialog>
<?php require __DIR__ . '/_footer.php'; ?>
