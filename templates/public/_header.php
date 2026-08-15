<?php
declare(strict_types=1);
/**
 * Shared skip link and site header.
 *
 * @var string $skipTarget
 * @var string $skipLabel
 * @var ?string $activeNav 'writing' | 'about' | null
 * @var bool|null $showReadingProgress
 */
$showReadingProgress = $showReadingProgress ?? false;
?>
<body>
  <a class="skip-link" href="<?= htmlspecialchars($skipTarget, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($skipLabel, ENT_QUOTES, 'UTF-8') ?></a>
  <header class="site-header"><div class="shell nav-row"><div class="brand-nav"><a class="wordmark" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/" aria-label="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> home"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></a><nav aria-label="Primary"><a<?= $activeNav === 'writing' ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/">Writing</a><?php foreach ($navPages ?? [] as $navPage): ?><a<?= $activeNav === $navPage['slug'] ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($navPage['slug'], ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($navPage['title'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?><a class="rss-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/rss.xml">RSS</a></nav></div><div class="header-tools"><?php if ($showReadingProgress): ?><div class="header-reading-progress" role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="progress-track" aria-hidden="true"><span class="progress-fill"></span></span><span class="progress-value">0%</span></div><?php endif; ?><div class="theme-switcher" role="group" aria-label="Theme switcher"><button type="button" data-theme-set="auto" title="System" aria-pressed="true"><span class="icon" aria-hidden="true">brightness_auto</span><span class="sr-only">Auto</span></button><button type="button" data-theme-set="light" title="Light" aria-pressed="false"><span class="icon" aria-hidden="true">light_mode</span><span class="sr-only">Light</span></button><button type="button" data-theme-set="dark" title="Dark" aria-pressed="false"><span class="icon" aria-hidden="true">dark_mode</span><span class="sr-only">Dark</span></button></div></div></div></header>
