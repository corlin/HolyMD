<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_base.php';
ob_start();
$activeNav = 'pages';
?>
<main class="admin-shell">
<?php require __DIR__ . '/../_nav.php'; ?>
<section class="article-index">
  <p class="eyebrow"><?= __('Site content') ?></p>
  <div class="article-index-heading">
    <div>
      <h1><?= __('Custom pages') ?></h1>
      <p><?= __('Standalone Markdown pages for privacy policies, terms, disclosures, or special sections.') ?></p>
    </div>
    <a class="button-link" href="<?= $path('/admin/pages/new') ?>"><span class="icon" aria-hidden="true">add</span><?= __('New page') ?></a>
  </div>
  <ul class="article-list">
    <?php
    foreach ($pages as $page):
        $status = (string) $page->frontMatter->get('status', 'draft');
        $modified = filemtime($page->sourcePath);
        $navOrder = $page->frontMatter->get('nav_order');
    ?>
    <li>
      <div class="article-row-main">
        <a href="<?= $path('/admin/pages/' . rawurlencode($page->slug) . '/edit') ?>">
          <strong><?= htmlspecialchars($page->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
        </a>
        <?php require __DIR__ . '/../_status_badge.php'; ?>
      </div>
      <div class="article-row-meta">
        <span><?= __('Route: {route}', ['route' => '/' . $page->slug . '/']) ?></span>
        <?php if ($navOrder !== null): ?>
          <span>· <?= __('Nav order: {order}', ['order' => (string) $navOrder]) ?></span>
        <?php endif; ?>
        <span>· <?= __('Modified {time}', ['time' => $modified === false ? \HolyMD\I18n\Translator::text('unknown') : date('Y-m-d H:i', $modified)]) ?></span>
        <?php if ($status === 'published'): ?>
          <a href="<?= $path('/' . rawurlencode($page->slug) . '/') ?>"><span class="icon" aria-hidden="true">open_in_new</span><?= __('View public') ?></a>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
</main>
<?php
$content = (string) ob_get_clean();
$title = \HolyMD\I18n\Translator::text('Pages');
require dirname(__DIR__) . '/layout.php';
?>
