<?php
declare(strict_types=1);

/**
 * @var \HolyMD\Content\ArticleDocument $page
 * @var string $contentHtml
 * @var string $siteName
 * @var string $siteUrl
 * @var string $authorName
 */

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$renderHighlights = static function (string $text) use ($escape): string {
    $out = $escape($text);
    $out = (string) preg_replace('/\[\[(.+?)\]\]/u', '<span class="hl">$1</span>', $out);
    $out = (string) preg_replace('/\(\((.+?)\)\)/u', '<span class="hl-lilac">$1</span>', $out);
    $out = (string) preg_replace('/\{\{(.+?)\}\}/u', '<span class="pill-hl">$1</span>', $out);
    return nl2br($out, false);
};

$fm = $page->frontMatter;
$tagline = (string) ($fm->get('tagline') ?? $page->title);
$heroEyebrow = (string) ($fm->get('hero_eyebrow') ?? ($authorName . ' · ' . date('Y')));
$heroSub = (string) ($fm->get('hero_sub') ?? '');
$heroStance = (array) ($fm->get('hero_stance') ?? []);
$heroCtas = (array) ($fm->get('hero_ctas') ?? [
    ['label' => '了解我能提供什么', 'url' => '#offer', 'style' => 'lemon'],
    ['label' => '联系我', 'url' => '#contact', 'style' => 'outline'],
]);
$photo = (array) ($fm->get('photo') ?? []);

$stats = (array) ($fm->get('stats') ?? []);
$offers = (array) ($fm->get('offers') ?? []);
$offerTitle = (string) ($fm->get('offer_title') ?? '我能提供什么');
$offerLede = (string) ($fm->get('offer_lede') ?? '');

$tracks = (array) ($fm->get('tracks') ?? []);
$trackTitle = (string) ($fm->get('track_title') ?? '这些不是履历，是我判断的来源');
$trackLede = (string) ($fm->get('track_lede') ?? '');
$trackPhoto = (array) ($fm->get('track_photo') ?? []);

$identities = (array) ($fm->get('identities') ?? []);
$identityTitle = (string) ($fm->get('identity_title') ?? '一个完整的我');
$identityLede = (string) ($fm->get('identity_lede') ?? '');
$identityClose = (string) ($fm->get('identity_close') ?? '');

$contact = (array) ($fm->get('contact') ?? []);
?>
<main id="main-content" class="brand-page">

  <!-- ================= 1. Hero 视觉首屏 ================= -->
  <header id="top" class="brand-hero">
    <div class="brand-container">
      <div class="brand-hero-grid">
        <div class="brand-hero-text">
          <div class="brand-eyebrow"><?= $escape($heroEyebrow) ?></div>

          <h1 class="brand-hero-title">
            <?= $renderHighlights($tagline) ?>
            <svg class="brand-arrow-doodle" width="56" height="26" viewBox="0 0 60 28" fill="none" aria-hidden="true">
              <path d="M2 18 Q 14 4, 28 12 T 56 6" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round"/>
              <path d="M48 4 L56 6 L52 14" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </h1>

          <?php if ($heroSub !== ''): ?>
            <p class="brand-hero-sub"><?= $renderHighlights($heroSub) ?></p>
          <?php endif; ?>

          <?php if ($heroStance !== []): ?>
            <div class="brand-hero-stance">
              <?php foreach ($heroStance as $item): ?>
                <p><?= $renderHighlights((string) $item) ?></p>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($heroCtas !== []): ?>
            <div class="brand-hero-ctas">
              <?php foreach ($heroCtas as $cta): ?>
                <?php
                $ctaStyle = (string) ($cta['style'] ?? 'lemon');
                $ctaClass = match ($ctaStyle) {
                    'lemon' => 'btn-brand-lemon',
                    'lilac' => 'btn-brand-lilac',
                    'dark' => 'btn-brand-dark',
                    default => 'btn-brand-outline',
                };
                ?>
                <a href="<?= $escape((string) ($cta['url'] ?? '#contact')) ?>" class="btn-brand <?= $ctaClass ?>">
                  <?= $escape((string) ($cta['label'] ?? '了解更多')) ?>
                  <span class="arrow-r" aria-hidden="true">→</span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="brand-hero-photo-wrap">
          <div class="brand-hero-card">
            <?php if (!empty($photo['src'])): ?>
              <img src="<?= $escape((string) $photo['src']) ?>" alt="<?= $escape((string) ($photo['alt'] ?? $authorName)) ?>" class="brand-hero-img">
            <?php else: ?>
              <div class="brand-hero-avatar-placeholder">
                <span class="brand-avatar-initial"><?= $escape(mb_substr($authorName, 0, 1)) ?></span>
              </div>
            <?php endif; ?>
            <?php if (!empty($photo['tag'])): ?>
              <span class="brand-photo-tag"><?= $escape((string) $photo['tag']) ?></span>
            <?php endif; ?>
          </div>
          <!-- 闪烁星芒点缀 -->
          <span class="brand-sparkle sp-1" aria-hidden="true">✦</span>
          <span class="brand-sparkle sp-2" aria-hidden="true">♥</span>
          <span class="brand-sparkle sp-3" aria-hidden="true">✦</span>
        </div>
      </div>
    </div>
  </header>

  <!-- ================= 2. Stats Strip 量化信任条 ================= -->
  <?php if ($stats !== []): ?>
    <section class="brand-stats-strip">
      <div class="brand-container">
        <div class="brand-stats-inner">
          <span class="brand-stats-label">走过 / 做过</span>
          <div class="brand-stats-list">
            <?php foreach ($stats as $idx => $st): ?>
              <?php if ($idx > 0): ?><span class="brand-stats-sep" aria-hidden="true">·</span><?php endif; ?>
              <div class="brand-stats-item">
                <span class="num <?= !empty($st['highlight']) ? 'is-highlight' : '' ?>"><?= $escape((string) ($st['num'] ?? '')) ?></span>
                <span class="lbl"><?= $escape((string) ($st['label'] ?? '')) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= 3. 我能提供什么 (Offer 矩阵) ================= -->
  <?php if ($offers !== []): ?>
    <section id="offer" class="brand-section brand-offer-section">
      <div class="brand-container">
        <div class="brand-sec-head">
          <div class="brand-eyebrow">我能提供什么</div>
          <h2 class="brand-sec-title"><?= $renderHighlights($offerTitle) ?></h2>
          <?php if ($offerLede !== ''): ?>
            <div class="brand-sec-lede"><p><?= $renderHighlights($offerLede) ?></p></div>
          <?php endif; ?>
        </div>

        <div class="brand-offer-grid">
          <?php foreach ($offers as $idx => $offer): ?>
            <?php
            $numTag = sprintf('%02d / OFFER', $idx + 1);
            $icon = (string) ($offer['icon'] ?? '✦');
            $iconBg = match ($idx % 3) {
                0 => 'bg-lemon',
                1 => 'bg-lilac',
                default => 'bg-dark',
            };
            ?>
            <div class="brand-offer-card">
              <span class="brand-card-num-tag"><?= $numTag ?></span>
              <div class="brand-card-icon <?= $iconBg ?>"><?= $escape($icon) ?></div>
              <h3><?= $escape((string) ($offer['title'] ?? '')) ?></h3>
              <?php if (!empty($offer['desc'])): ?>
                <div class="brand-card-desc"><?= $renderHighlights((string) $offer['desc']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= 4. 我做过什么 (Track 经历与深度洞察) ================= -->
  <?php if ($tracks !== []): ?>
    <section id="track" class="brand-section brand-track-section">
      <div class="brand-container">
        <div class="brand-sec-head">
          <div class="brand-eyebrow">我做过什么</div>
          <h2 class="brand-sec-title"><?= $renderHighlights($trackTitle) ?></h2>
          <?php if ($trackLede !== ''): ?>
            <div class="brand-sec-lede"><p><?= $renderHighlights($trackLede) ?></p></div>
          <?php endif; ?>
        </div>

        <div class="brand-track-list">
          <?php foreach ($tracks as $tr): ?>
            <div class="brand-track-item">
              <div class="brand-track-headline">
                <span class="brand-track-num"><?= $renderHighlights((string) ($tr['title'] ?? '')) ?></span>
              </div>
              <div class="brand-track-insight">
                <?= $renderHighlights((string) ($tr['insight'] ?? '')) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= 5. 一个完整的我 (Identity 多维人设横滑卡片墙) ================= -->
  <?php if ($identities !== []): ?>
    <section id="me" class="brand-section brand-identity-section">
      <div class="brand-container">
        <div class="brand-sec-head">
          <div class="brand-eyebrow">完整的我</div>
          <h2 class="brand-sec-title"><?= $renderHighlights($identityTitle) ?></h2>
          <?php if ($identityLede !== ''): ?>
            <div class="brand-sec-lede"><p><?= $renderHighlights($identityLede) ?></p></div>
          <?php endif; ?>
        </div>

        <div class="brand-identity-track-wrap">
          <div class="brand-identity-line" aria-hidden="true"></div>
          <div class="brand-identity-list" tabindex="0" role="region" aria-label="多元身份卡片横滑列表">
            <?php foreach ($identities as $idx => $idItem): ?>
              <?php
              $tag = (string) ($idItem['tag'] ?? sprintf('%02d IDENTITY', $idx + 1));
              $clipColor = match ($idx % 4) {
                  0 => 'clip-lemon',
                  1 => 'clip-lilac',
                  2 => 'clip-mint',
                  default => 'clip-pink',
              };
              ?>
              <div class="brand-identity-card <?= $clipColor ?>">
                <div class="brand-id-tag"><?= $escape($tag) ?></div>
                <h3 class="brand-id-title"><?= $escape((string) ($idItem['title'] ?? '')) ?></h3>
                <div class="brand-id-body">
                  <?= $renderHighlights((string) ($idItem['desc'] ?? '')) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($identityClose !== ''): ?>
          <p class="brand-identity-close"><?= $renderHighlights($identityClose) ?></p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= 6. 博主深度自述 (Markdown 正文) ================= -->
  <?php if (trim(strip_tags($contentHtml)) !== ''): ?>
    <section class="brand-section brand-prose-section">
      <div class="brand-container brand-container-prose">
        <div class="prose brand-editorial-prose">
          <?= $contentHtml ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================= 7. 靠近我 (Contact 合作与行动号召) ================= -->
  <?php if ($contact !== []): ?>
    <section id="contact" class="brand-section brand-contact-section">
      <div class="brand-container brand-container-contact">
        <div class="brand-sec-head" style="text-align: center; margin-inline: auto;">
          <div class="brand-eyebrow" style="justify-content: center;"><?= $escape((string) ($contact['eyebrow'] ?? '靠近我')) ?></div>
          <h2 class="brand-sec-title"><?= $renderHighlights((string) ($contact['title'] ?? '如果你也想把自己重新放大，我们可以聊聊。')) ?></h2>
          <?php if (!empty($contact['desc'])): ?>
            <div class="brand-sec-lede" style="margin-inline: auto;"><p><?= $renderHighlights((string) $contact['desc']) ?></p></div>
          <?php endif; ?>
        </div>

        <div class="brand-contact-actions">
          <?php if (!empty($contact['email'])): ?>
            <a href="mailto:<?= $escape((string) $contact['email']) ?>" class="btn-brand btn-brand-lemon btn-brand-lg">
              <?= $escape((string) ($contact['cta'] ?? '聊聊合作')) ?>
              <span class="arrow-r" aria-hidden="true">→</span>
            </a>
          <?php endif; ?>
        </div>

        <?php if (!empty($contact['links']) && is_array($contact['links'])): ?>
          <div class="brand-social-links">
            <?php foreach ($contact['links'] as $link): ?>
              <a href="<?= $escape((string) ($link['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer" class="brand-social-item">
                <?= $escape((string) ($link['label'] ?? '')) ?> ↗
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

</main>
