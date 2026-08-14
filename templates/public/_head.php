<?php
declare(strict_types=1);
/**
 * Shared public <head>.
 *
 * @var string $pageTitle
 * @var string $description
 * @var string $ogType
 * @var string $ogTitle
 * @var string $ogDescription
 * @var string $ogUrl
 * @var string $canonical
 * @var ?string $jsonLd
 * @var bool $showAlternates
 * @var string $assetCss
 * @var string $assetSearch
 */
?>
<!doctype html>
<html lang="<?= htmlspecialchars($siteLanguage, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:type" content="<?= htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') ?>"><meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>"><meta property="og:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>"><meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>"><meta property="og:site_name" content="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"><?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
  <meta name="twitter:card" content="<?= !empty($ogImage) ? 'summary_large_image' : 'summary' ?>"><meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>"><meta name="twitter:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>"><?php if (!empty($ogImage)): ?><meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<?php if ($showAlternates): ?>
  <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> RSS" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/rss.xml">
  <link rel="alternate" type="application/atom+xml" title="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> Atom Feed" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/atom.xml">
  <link rel="alternate" type="application/feed+json" title="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> JSON Feed" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/feed.json">
<?php endif; ?>
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetCss, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($jsonLd !== null): ?>
  <script type="application/ld+json"><?= $jsonLd ?></script>
<?php endif; ?>
  <script>!function(){try{var t=localStorage.getItem("holymd_theme");t?document.documentElement.setAttribute("data-theme",t):"dark"===matchMedia("(prefers-color-scheme: dark)").matches&&document.documentElement.setAttribute("data-theme","dark")}catch(e){}}();</script>
</head>
