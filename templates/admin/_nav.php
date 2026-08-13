<?php
declare(strict_types=1);
/** @var string $activeNav */
$active = static fn (string $name): string => $activeNav === $name ? ' class="active"' : '';
$attr = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="left-rail" aria-label="Administration">
  <a class="brand" href="/admin/articles">HolyMD</a>
  <a<?= $active('articles') ?> href="/admin/articles"><span class="icon" aria-hidden="true">article</span>Articles</a>
  <a<?= $active('jobs') ?> href="/admin/jobs"><span class="icon" aria-hidden="true">checklist</span>Jobs</a>
  <a<?= $active('media') ?> href="/admin/media"><span class="icon" aria-hidden="true">image</span>Library</a>
  <a<?= $active('settings') ?> href="/admin/settings"><span class="icon" aria-hidden="true">settings</span>Settings</a>
  <footer>
    <form method="post" action="/admin/logout">
      <input type="hidden" name="csrf_token" value="<?= $attr((string) ($csrfToken ?? '')) ?>">
      <button type="submit"><span class="icon" aria-hidden="true">logout</span>Sign out</button>
    </form>
  </footer>
</nav>
