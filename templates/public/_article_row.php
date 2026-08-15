<?php
declare(strict_types=1);
/**
 * @var HolyMD\Content\ArticleDocument $article
 * @var string $basePath
 * @var string $headingTag ('h2' | 'h3')
 * @var bool $showDate
 */
$headingTag ??= 'h3';
$showDate ??= true;
$slug = htmlspecialchars($article->slug);
$title = htmlspecialchars($article->title);
$date = htmlspecialchars((string) $article->frontMatter->get('date'));
$summary = (string) $article->frontMatter->get('summary', '');
?>
<article class="article-row"><div><?php if ($showDate && $date !== ''): ?><p class="article-kicker"><time datetime="<?= $date ?>"><?= $date ?></time></p><?php endif; ?><<?= $headingTag ?>><a href="<?= $basePath ?>/articles/<?= $slug ?>/"><?= $title ?></a></<?= $headingTag ?>><?php if ($summary !== ''): ?><p><?= htmlspecialchars($summary) ?></p><?php endif; ?></div><a class="quiet-arrow" href="<?= $basePath ?>/articles/<?= $slug ?>/" aria-label="Read <?= $title ?>"><span class="icon" aria-hidden="true">arrow_forward</span></a></article>
