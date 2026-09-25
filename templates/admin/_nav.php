<?php
declare(strict_types=1);
/** @var string $activeNav */
$active = static fn (string $name): string => $activeNav === $name ? ' class="active"' : '';
$attr = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentLocale = \HolyMD\I18n\Translator::locale();
?>
<nav class="left-rail" aria-label="<?= __('Administration') ?>">
  <a class="brand" href="<?= $attr($basePath . '/admin/articles') ?>">HolyMD</a>
  <a<?= $active('articles') ?> href="<?= $attr($basePath . '/admin/articles') ?>"><span class="icon" aria-hidden="true">article</span><?= __('Articles') ?></a>
  <a<?= $active('geo') ?> href="<?= $attr($basePath . '/admin/geo') ?>"><span class="icon" aria-hidden="true">insights</span>GEO</a>
  <a<?= $active('pages') ?> href="<?= $attr($basePath . '/admin/pages') ?>"><span class="icon" aria-hidden="true">description</span><?= __('Pages') ?></a>
  <a<?= $active('jobs') ?> href="<?= $attr($basePath . '/admin/jobs') ?>"><span class="icon" aria-hidden="true">checklist</span><?= __('Jobs') ?></a>
  <a<?= $active('media') ?> href="<?= $attr($basePath . '/admin/media') ?>"><span class="icon" aria-hidden="true">image</span><?= __('Library') ?></a>
  <a<?= $active('profile') ?> href="<?= $attr($basePath . '/admin/profile') ?>"><span class="icon" aria-hidden="true">person</span><?= __('Profile') ?></a>
  <a<?= $active('settings') ?> href="<?= $attr($basePath . '/admin/settings') ?>"><span class="icon" aria-hidden="true">settings</span><?= __('Settings') ?></a>
  <footer>
    <p class="locale-switch" aria-label="<?= __('Language') ?>"><span class="icon" aria-hidden="true">translate</span><?php if ($currentLocale === 'en'): ?><strong lang="en">English</strong> · <a href="?lang=zh-CN" lang="zh-CN" hreflang="zh-CN">中文</a><?php else: ?><a href="?lang=en" lang="en" hreflang="en">English</a> · <strong lang="zh-CN">中文</strong><?php endif; ?></p>
    <form method="post" action="<?= $attr($basePath . '/admin/logout') ?>">
      <input type="hidden" name="csrf_token" value="<?= $attr((string) ($csrfToken ?? '')) ?>">
      <button type="submit"><span class="icon" aria-hidden="true">logout</span><?= __('Sign out') ?></button>
    </form>
  </footer>
</nav>
