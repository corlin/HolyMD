<?php
declare(strict_types=1);
$pageTitle = \HolyMD\I18n\Translator::text('Page not found') . ' | ' . $siteName;
$description = \HolyMD\I18n\Translator::text('The requested page could not be found.');
$ogType = 'website';
$ogTitle = \HolyMD\I18n\Translator::text('Page not found');
$ogDescription = $description;
$ogUrl = rtrim($siteUrl, '/') . '/404.html';
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
  <main id="main-content" class="shell page-intro"><p class="eyebrow">404</p><h1><?= __('Page not found') ?></h1><p class="deck"><?= __("The page you were looking for doesn't exist or has been moved.") ?></p><p><a class="text-link" href="<?= $basePath ?>/"><?= __('Return to writing') ?> <span class="icon" aria-hidden="true">arrow_forward</span></a></p></main>
<?php require __DIR__ . '/_footer.php'; ?>
