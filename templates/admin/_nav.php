<?php
declare(strict_types=1);
/** @var string $activeNav */
$active = static fn (string $name): string => $activeNav === $name ? ' class="active"' : '';
$attr = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="left-rail" aria-label="Administration">
  <a class="brand" href="<?= $attr($basePath . '/admin/articles') ?>">HolyMD</a>
  <a<?= $active('articles') ?> href="<?= $attr($basePath . '/admin/articles') ?>"><span class="icon" aria-hidden="true">article</span>Articles</a>
  <a<?= $active('geo') ?> href="<?= $attr($basePath . '/admin/geo') ?>"><span class="icon" aria-hidden="true">insights</span>GEO</a>
  <a<?= $active('pages') ?> href="<?= $attr($basePath . '/admin/pages') ?>"><span class="icon" aria-hidden="true">description</span>Pages</a>
  <a<?= $active('jobs') ?> href="<?= $attr($basePath . '/admin/jobs') ?>"><span class="icon" aria-hidden="true">checklist</span>Jobs</a>
  <a<?= $active('media') ?> href="<?= $attr($basePath . '/admin/media') ?>"><span class="icon" aria-hidden="true">image</span>Library</a>
  <a<?= $active('profile') ?> href="<?= $attr($basePath . '/admin/profile') ?>"><span class="icon" aria-hidden="true">person</span>Profile</a>
  <a<?= $active('settings') ?> href="<?= $attr($basePath . '/admin/settings') ?>"><span class="icon" aria-hidden="true">settings</span>Settings</a>
  <footer>
    <form method="post" action="<?= $attr($basePath . '/admin/logout') ?>">
      <input type="hidden" name="csrf_token" value="<?= $attr((string) ($csrfToken ?? '')) ?>">
      <button type="submit"><span class="icon" aria-hidden="true">logout</span>Sign out</button>
    </form>
  </footer>
</nav>
