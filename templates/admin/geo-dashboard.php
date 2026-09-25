<?php
declare(strict_types=1);
require __DIR__ . '/_base.php';
ob_start();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeNav = 'geo';
?>
<main class="admin-shell">
<?php require __DIR__ . '/_nav.php'; ?>
<section class="article-index geo-dashboard">
  <p class="eyebrow"><?= __('Search & AI observability') ?></p>
  <h1><?= __('GEO health dashboard') ?></h1>
  <p class="muted"><?= __('Site-wide Generative Engine Optimization (GEO) health scores and AI crawler observability.') ?></p>

  <div class="geo-section-card geo-hero-card">
    <div class="geo-hero-grid">
      <div class="geo-stat-card">
        <span class="geo-stat-label"><?= __('Average GEO score') ?></span>
        <div class="geo-stat-value <?= $averageScore >= 80 ? 'is-excellent' : ($averageScore >= 50 ? 'is-good' : 'is-weak') ?>">
          <?= (int) $averageScore ?><span class="geo-stat-unit">/ 100</span>
        </div>
        <span class="geo-stat-hint"><?= $averageScore >= 80 ? __('Strong structured signals') : ($averageScore >= 50 ? __('Some articles need work') : __('Many fields are missing')) ?></span>
      </div>

      <div class="geo-stat-card">
        <span class="geo-stat-label"><?= __('Articles scoring 80+') ?></span>
        <div class="geo-stat-value"><?= (int) $excellentPercentage ?><span class="geo-stat-unit">%</span></div>
        <span class="geo-stat-hint"><?= __('{excellent} of {total} published articles rated excellent', ['excellent' => (int) $excellentCount, 'total' => (int) $publishedCount]) ?></span>
      </div>

      <div class="geo-stat-card">
        <span class="geo-stat-label"><?= __('Score distribution') ?></span>
        <div class="geo-distribution-bar">
          <?php if ($publishedCount > 0): ?>
            <div class="geo-dist-segment is-excellent" style="width: <?= round(($excellentCount / $publishedCount) * 100) ?>%;" title="<?= __('Excellent: {count}', ['count' => $excellentCount]) ?>"></div>
            <div class="geo-dist-segment is-good" style="width: <?= round(($goodCount / $publishedCount) * 100) ?>%;" title="<?= __('Good: {count}', ['count' => $goodCount]) ?>"></div>
            <div class="geo-dist-segment is-weak" style="width: <?= round(($weakCount / $publishedCount) * 100) ?>%;" title="<?= __('Needs work: {count}', ['count' => $weakCount]) ?>"></div>
          <?php else: ?>
            <div class="geo-dist-segment" style="width: 100%; background: var(--line);"></div>
          <?php endif; ?>
        </div>
        <div class="geo-distribution-legend">
          <span><span class="dot is-excellent"></span><?= __('Excellent') ?> <?= (int) $excellentCount ?></span>
          <span><span class="dot is-good"></span><?= __('Good') ?> <?= (int) $goodCount ?></span>
          <span><span class="dot is-weak"></span><?= __('Needs work') ?> <?= (int) $weakCount ?></span>
        </div>
      </div>
    </div>

    <?php if ($trends !== []): ?>
      <div class="geo-hero-trend">
        <div class="geo-card-header">
          <span class="geo-stat-label"><?= __('Score history at publish (last {count})', ['count' => count($trends)]) ?></span>
        </div>
        <div class="geo-trend-chart-wrap">
          <div class="geo-trend-chart">
            <?php foreach ($trends as $point): ?>
              <div class="geo-chart-col" title="<?= __('{date}: average {score}', ['date' => $point['date'], 'score' => (int) $point['score']]) ?>">
                <div class="geo-chart-bar-wrap">
                  <div class="geo-chart-bar <?= $point['score'] >= 80 ? 'is-excellent' : ($point['score'] >= 50 ? 'is-good' : 'is-weak') ?>" style="height: <?= max(6, (int) $point['score']) ?>%;">
                    <span class="geo-chart-val"><?= (int) $point['score'] ?></span>
                  </div>
                </div>
                <span class="geo-chart-label"><?= $escape(substr($point['date'], 5)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="geo-grid-two-col">
    <!-- Topics and entities -->
    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2><?= __('Topics and entities') ?></h2>
          <p class="muted"><?= __('How much you have published per topic, and which concepts you cover most.') ?></p>
        </div>
      </div>
      <?php if ($topicStats === [] && $topEntities === []): ?>
        <p class="muted"><?= __('No topics or entities configured yet.') ?></p>
      <?php else: ?>
        <?php if ($topicStats !== []): ?>
          <div class="geo-topic-list">
            <?php foreach (array_slice($topicStats, 0, 4) as $t): ?>
              <div class="geo-topic-row">
                <div class="geo-topic-info">
                  <strong><?= $escape($t['name']) ?></strong>
                  <span class="muted"><?= __('{count} article(s)', ['count' => (int) $t['count']]) ?></span>
                </div>
                <div class="geo-score-badge is-<?= $t['avgScore'] >= 80 ? 'excellent' : ($t['avgScore'] >= 50 ? 'good' : 'weak') ?>">
                  <?= __('Avg') ?> <strong><?= (int) $t['avgScore'] ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($topEntities !== []): ?>
          <div class="geo-entity-cloud" style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line);">
            <?php foreach (array_slice($topEntities, 0, 16, true) as $entityName => $count): ?>
              <span class="geo-entity-pill" title="<?= __('Appears in {count} article(s)', ['count' => (int) $count]) ?>">
                <?= $escape($entityName) ?><small class="geo-entity-count"><?= (int) $count ?></small>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- AI crawler observability -->
    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2><?= __('AI crawler observability') ?></h2>
          <p class="muted"><?= __('Which AI crawlers visit your site, how often, and what they read.') ?></p>
        </div>
      </div>

      <div class="geo-ai-stat-row">
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['total7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl"><?= __('Crawls, last 7 days') ?></span>
        </div>
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['distinctBots7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl"><?= __('Active crawlers') ?></span>
        </div>
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['llmsTxt7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl"><?= __('llms.txt requests') ?></span>
        </div>
      </div>

      <div class="geo-bot-compact-grid">
        <div>
          <h3 class="geo-sub-title"><?= __('Crawlers') ?></h3>
          <?php if (empty($aiBotStats['botDistribution'])): ?>
            <p class="muted" style="font-size: 12px;"><?= __('No crawler visits recorded yet') ?></p>
          <?php else: ?>
            <div class="geo-bot-dist-list">
              <?php foreach (array_slice($aiBotStats['botDistribution'], 0, 4) as $b): ?>
                <div class="geo-bot-dist-row">
                  <div class="geo-bot-name-col">
                    <strong><?= $escape($b['bot_name']) ?></strong>
                    <span class="muted"><?= __('{count} visit(s)', ['count' => (int) $b['count']]) ?> (<?= (int) $b['percentage'] ?>%)</span>
                  </div>
                  <div class="geo-bot-progress-track">
                    <div class="geo-bot-progress-bar" style="width: <?= (int) $b['percentage'] ?>%;"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div>
          <h3 class="geo-sub-title"><?= __('Most crawled') ?></h3>
          <?php if (empty($aiBotStats['topPaths'])): ?>
            <p class="muted" style="font-size: 12px;"><?= __('No crawled paths yet') ?></p>
          <?php else: ?>
            <ul class="geo-crawled-paths-list">
              <?php foreach (array_slice($aiBotStats['topPaths'], 0, 3) as $tp): ?>
                <li>
                  <code><?= $escape($tp['path']) ?></code>
                  <span class="geo-badge-count"><?= (int) $tp['count'] ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($aiBotStats['recentVisits'])): ?>
        <div class="geo-stream-wrap">
          <h3 class="geo-sub-title"><?= __('Recent visits') ?></h3>
          <div class="geo-stream-list">
            <?php foreach ($aiBotStats['recentVisits'] as $v): ?>
              <div class="geo-stream-item">
                <span class="geo-bot-pill is-<?= strtolower(preg_replace('/[^a-z0-9]/i', '', $v['bot_name'])) ?>"><?= $escape($v['bot_name']) ?></span>
                <span class="geo-stream-path"><code><?= $escape($v['request_path']) ?></code></span>
                <span class="geo-stream-status is-status-<?= $v['http_status'] ?>"><?= (int) $v['http_status'] ?></span>
                <span class="geo-stream-time muted"><?= $escape($v['created_at_display']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="geo-section-card">
    <div class="geo-card-header">
      <div>
        <h2><?= __('Articles to improve first') ?></h2>
        <p class="muted"><?= __('Filling in the missing fields on these articles raises your site-wide GEO health fastest.') ?></p>
      </div>
    </div>

    <?php if ($topWeakest === []): ?>
      <p class="muted"><?= __('No published articles yet.') ?></p>
    <?php else: ?>
      <ul class="article-list geo-weak-list">
        <?php foreach ($topWeakest as $item): 
          /** @var HolyMD\Content\ArticleDocument $art */
          $art = $item['article'];
          /** @var HolyMD\Geo\GeoScore $sc */
          $sc = $item['score'];
        ?>
          <li>
            <div class="article-row-main">
              <a href="<?= $path('/admin/articles/' . rawurlencode($art->slug) . '/edit') ?>">
                <strong><?= $escape($art->title) ?></strong>
              </a>
              <div class="geo-score-badge is-<?= $sc->grade() ?>">
                <span class="icon" aria-hidden="true">insights</span>
                <strong><?= $sc->total ?></strong> · <?= $escape($sc->gradeLabel()) ?>
              </div>
            </div>
            <div class="article-row-meta">
              <span><?= $escape((string) $art->frontMatter->get('date')) ?> · <code>/articles/<?= $escape($art->slug) ?>/</code></span>
              <a class="button-link-secondary" href="<?= $path('/admin/articles/' . rawurlencode($art->slug) . '/edit') ?>">
                <span class="icon" aria-hidden="true">edit</span><?= __('Improve') ?>
              </a>
            </div>
            <div class="geo-missing-tags">
              <?php foreach ($sc->breakdown as $field): ?>
                <?php if ($field['earned'] < $field['weight']): ?>
                  <span class="geo-missing-tag" title="<?= $escape($field['reason']) ?>">
                    <?= __('Missing: {field} (−{points})', ['field' => $field['label'], 'points' => $field['weight'] - $field['earned']]) ?>
                  </span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
</main>
<?php
$content = (string) ob_get_clean();
$title = \HolyMD\I18n\Translator::text('GEO dashboard');
require __DIR__ . '/layout.php';
?>
