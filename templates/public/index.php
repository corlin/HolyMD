<?php
declare(strict_types=1);
$pageTitle = $siteName;
$description = $about !== '' ? $about : 'Writing by ' . $authorName;
$ogType = 'website';
$ogTitle = $siteName;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . '/';
$canonical = $ogUrl;
$jsonLd = $jsonLd ?? null;
$ogImage = null;
$showAlternates = true;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = 'Skip to content';
$activeNav = 'writing';
require __DIR__ . '/_header.php';
?>
  <main id="main-content" class="shell editorial-flow">
    <h1 class="sr-only"><?= htmlspecialchars($siteName) ?></h1>
    <?php if ($about !== ''): ?>
      <div class="editorial-bio">
        <p class="intro"><?= nl2br(htmlspecialchars($about), false) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($articles !== []): ?>
      <?php $featured = $articles[0]; ?>
      <section class="editorial-featured" aria-labelledby="featured-heading">
        <h2 id="featured-heading" class="sr-only">Featured writing</h2>
        <article class="feature-card">
          <div class="feature-card-header">
            <span class="feature-badge">Featured</span>
            <time class="article-date" datetime="<?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?></time>
          </div>
          <h3><a href="<?= $basePath ?>/articles/<?= htmlspecialchars($featured->slug) ?>/"><?= htmlspecialchars($featured->title) ?></a></h3>
          <?php $summary = (string) $featured->frontMatter->get('summary', ''); if ($summary !== ''): ?>
            <p class="feature-summary"><?= htmlspecialchars($summary) ?></p>
          <?php endif; ?>
          <a class="text-link" href="<?= $basePath ?>/articles/<?= htmlspecialchars($featured->slug) ?>/">
            Read the essay <span class="icon" aria-hidden="true">arrow_forward</span>
          </a>
        </article>
      </section>

      <?php $latestBatch = array_slice($articles, 1, 10); ?>
      <?php if ($latestBatch !== []): ?>
        <section class="editorial-latest" aria-labelledby="latest-heading">
          <div class="stream-heading">
            <h2 id="latest-heading">Latest writing</h2>
          </div>
          <div id="latest-articles" class="article-list">
            <?php foreach ($latestBatch as $article): require __DIR__ . '/_article_row.php'; endforeach; ?>
          </div>
          <?php if (count($articles) > 11): ?>
            <div class="load-more-wrap">
              <button type="button" id="load-more-button" class="button-load-more">Load more writing</button>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php else: ?>
      <section class="editorial-empty">
        <h2 id="featured-heading">No published writing yet</h2>
        <p class="muted">Check back soon for new essays and notes.</p>
      </section>
    <?php endif; ?>

    <?php if ($topics !== []): ?>
      <section class="editorial-topics" aria-labelledby="topics-heading">
        <div class="stream-heading">
          <h2 id="topics-heading">Topics</h2>
        </div>
        <ul class="topic-list">
          <?php foreach ($topics as $topic => $topicArticles): $slug = (string) ($topicSlugs[$topic] ?? ''); ?>
            <li><a href="<?= $basePath ?>/topics/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars((string) $topic) ?><span><?= count($topicArticles) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </main>
<?php require __DIR__ . '/_footer.php'; ?>
