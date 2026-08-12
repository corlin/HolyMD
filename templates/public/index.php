<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= htmlspecialchars($about !== '' ? $about : 'Writing by ' . $authorName, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($siteName) ?></title>
  <link rel="canonical" href="<?= htmlspecialchars(rtrim($siteUrl, '/') . '/') ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($siteName) ?> RSS" href="/rss.xml">
  <link rel="alternate" type="application/feed+json" title="<?= htmlspecialchars($siteName) ?> JSON Feed" href="/feed.json">
  <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
  <a class="skip-link" href="#main-content">Skip to content</a>
  <header class="site-header"><div class="shell nav-row"><a class="wordmark" href="/" aria-label="<?= htmlspecialchars($siteName) ?> home"><?= htmlspecialchars($siteName) ?></a><nav aria-label="Primary"><a aria-current="page" href="/">Writing</a><a href="/about/">About</a><a href="/rss.xml">RSS</a></nav></div></header>
  <main id="main-content">
    <section class="hero shell"><p class="eyebrow">Personal notes from <?= htmlspecialchars($authorName) ?></p><h1><?= htmlspecialchars($siteName) ?></h1><?php if ($about !== ''): ?><p class="intro"><?= nl2br(htmlspecialchars($about), false) ?></p><?php endif; ?><a class="text-link" href="/about/">About the author <span aria-hidden="true">→</span></a></section>
    <section class="shell section" aria-labelledby="featured-heading"><?php if ($articles !== []): $featured = $articles[0]; ?><div class="section-heading"><p class="eyebrow">Selected</p><h2 id="featured-heading">Featured writing</h2></div><article class="feature-card"><p class="article-kicker"><time datetime="<?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $featured->frontMatter->get('date')) ?></time></p><h3><a href="/articles/<?= htmlspecialchars($featured->slug) ?>/"><?= htmlspecialchars($featured->title) ?></a></h3><?php $summary = (string) $featured->frontMatter->get('summary', ''); if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?><a class="text-link" href="/articles/<?= htmlspecialchars($featured->slug) ?>/">Read the essay <span aria-hidden="true">→</span></a></article><?php else: ?><div class="section-heading"><p class="eyebrow">Writing</p><h2 id="featured-heading">No published writing yet</h2></div><?php endif; ?></section>
    <section class="shell section" aria-labelledby="latest-heading"><div class="section-heading"><p class="eyebrow">Archive</p><h2 id="latest-heading">Latest writing</h2></div><div class="article-list"><?php foreach (array_slice($articles, 1) as $article): ?><article class="article-row"><div><p class="article-kicker"><time datetime="<?= htmlspecialchars((string) $article->frontMatter->get('date')) ?>"><?= htmlspecialchars((string) $article->frontMatter->get('date')) ?></time></p><h3><a href="/articles/<?= htmlspecialchars($article->slug) ?>/"><?= htmlspecialchars($article->title) ?></a></h3><?php $summary = (string) $article->frontMatter->get('summary', ''); if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="/articles/<?= htmlspecialchars($article->slug) ?>/" aria-label="Read <?= htmlspecialchars($article->title) ?>">→</a></article><?php endforeach; ?></div></section>
    <?php if ($topics !== []): ?><section class="shell section topic-section" aria-labelledby="topics-heading"><div class="section-heading"><p class="eyebrow">Browse</p><h2 id="topics-heading">Topics</h2></div><ul class="topic-list"><?php foreach ($topics as $topic => $topicArticles): $slug = trim((string) preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($topic)) ?? ''), '-'); ?><li><a href="/topics/<?= htmlspecialchars($slug) ?>/"><?= htmlspecialchars($topic) ?><span><?= count($topicArticles) ?></span></a></li><?php endforeach; ?></ul></section><?php endif; ?>
  </main>
  <footer class="site-footer"><div class="shell"><p><?= htmlspecialchars($siteName) ?> — writing by <?= htmlspecialchars($authorName) ?>.</p><nav aria-label="Footer"><a href="/about/">About</a><a href="/rss.xml">RSS</a><a href="/feed.json">JSON Feed</a></nav></div></footer>
</body>
</html>
