<?php
declare(strict_types=1);
$pageTitle = $topic . ' | ' . $siteName;
$description = \HolyMD\I18n\Translator::text('Writing about {topic} by {author}', ['topic' => $topic, 'author' => $authorName]);
$ogType = 'website';
$ogTitle = $pageTitle;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . $route;
$canonical = $ogUrl;
$jsonLd = null;
$ogImage = null;
$showAlternates = false;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = \HolyMD\I18n\Translator::text('Skip to content');
$activeNav = null;
require __DIR__ . '/_header.php';
?>
  <main id="main-content" class="shell page-intro"><nav class="breadcrumb" aria-label="<?= __('Breadcrumb') ?>"><a href="<?= $basePath ?>/"><?= __('Writing') ?></a><span class="icon" aria-hidden="true">chevron_right</span><span aria-current="page"><?= htmlspecialchars($topic) ?></span></nav><p class="eyebrow"><?= __('Topic') ?></p><h1><?= __('Articles on {topic}', ['topic' => $topic]) ?></h1><div class="article-list topic-articles"><?php foreach ($articles as $article): $headingTag = 'h2'; require __DIR__ . '/_article_row.php'; endforeach; ?></div></main>
<?php require __DIR__ . '/_footer.php'; ?>
