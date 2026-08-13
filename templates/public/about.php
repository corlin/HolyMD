<?php
declare(strict_types=1);
$pageTitle = 'About | ' . $siteName;
$description = 'About ' . $authorName;
$ogType = 'profile';
$ogTitle = 'About ' . $authorName;
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . '/about/';
$canonical = $ogUrl;
$jsonLd = null;
$showAlternates = false;
require __DIR__ . '/_head.php';
$skipTarget = '#main-content';
$skipLabel = 'Skip to content';
$activeNav = 'about';
require __DIR__ . '/_header.php';
?>
  <main id="main-content" class="shell page-intro"><p class="eyebrow">About</p><h1><?= htmlspecialchars($authorName) ?></h1><?php if ($about !== ''): ?><div class="prose about-copy"><p><?= nl2br(htmlspecialchars($about), false) ?></p></div><?php else: ?><p class="deck">This personal publication is written by <?= htmlspecialchars($authorName) ?>.</p><?php endif; ?><p><a class="text-link" href="<?= $basePath ?>/">Browse the writing <span class="icon" aria-hidden="true">arrow_forward</span></a></p></main>
<?php require __DIR__ . '/_footer.php'; ?>
