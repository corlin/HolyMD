<?php
declare(strict_types=1);
/** @var HolyMD\Content\ArticleDocument $article */ /** @var string $csrfToken */
?>
<section class="geo-panel" data-geo-panel data-article-slug="<?= htmlspecialchars($article->slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<p class="eyebrow">GEO review</p><h2>Metadata proposals</h2><p class="muted">Analysis-only suggestions for metadata, entities, questions, links, hierarchy, sources, alt text, and structured data. Article prose is never generated or changed.</p><button type="button" data-geo-review>Request GEO review</button><div data-geo-review-status aria-live="polite"></div><ol data-geo-proposals aria-label="GEO proposals"></ol><template data-geo-proposal-template><li><output data-geo-diff></output><button type="button" data-geo-accept>Accept metadata</button><button type="button" data-geo-reject>Reject</button><button type="button" data-geo-edit>Edit proposal</button></li></template><input type="hidden" data-geo-csrf value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</section>
