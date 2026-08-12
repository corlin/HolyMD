<?php
declare(strict_types=1);
ob_start();
?>
<main class="admin-shell"><nav class="left-rail" aria-label="Administration"><a class="brand" href="/admin/articles">HolyMD</a><a class="active" href="/admin/articles">Articles</a><span>Library</span><span>Settings</span><footer>Administrator</footer></nav><section class="article-index"><p class="eyebrow">Writing studio</p><h1>Articles</h1><p>Markdown remains the source of truth. Open a draft to write, preview, and publish deliberately.</p><ul class="article-list"><?php foreach ($articles as $article): ?><li><a href="/admin/articles/<?= rawurlencode($article->slug) ?>/edit"><strong><?= htmlspecialchars($article->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) $article->frontMatter->get('date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></a></li><?php endforeach; ?></ul></section></main>
<?php $content = (string) ob_get_clean(); $title = 'Articles'; require dirname(__DIR__) . '/layout.php';
