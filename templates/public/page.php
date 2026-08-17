<?php
declare(strict_types=1);
$pageTitle = $page->title . ' | ' . $siteName;
$description = (string) $page->frontMatter->get('summary', (string) $page->frontMatter->get('description', $page->title));
$ogType = 'website';
$ogTitle = $page->title;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . '/' . $page->slug . '/';
$canonical = $ogUrl;
$jsonLd = $jsonLd ?? null;
$ogImage = null;
$showAlternates = false;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = 'Skip to content';
$activeNav = $page->slug;
require __DIR__ . '/_header.php';
?>
  <main id="main-content" class="shell page-intro"><p class="eyebrow"><?= htmlspecialchars($page->title) ?></p><h1><?= htmlspecialchars($page->title) ?></h1><div class="prose page-copy"><?= $contentHtml ?></div></main>
<?php require __DIR__ . '/_footer.php'; ?>
