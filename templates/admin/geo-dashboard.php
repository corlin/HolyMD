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
  <p class="eyebrow">Brand & Search Intelligence</p>
  <h1>GEO 健康度看板</h1>
  <p class="muted">全站生成式引擎优化（GEO）健康评分体系。实时监控全站结构化数据完整度、AI 摘要完备性与可信度信号。</p>

  <div class="geo-overview-grid">
    <div class="geo-stat-card">
      <span class="geo-stat-label">全站平均 GEO 得分</span>
      <div class="geo-stat-value <?= $averageScore >= 80 ? 'is-excellent' : ($averageScore >= 50 ? 'is-good' : 'is-weak') ?>">
        <?= (int) $averageScore ?><span class="geo-stat-unit">/ 100</span>
      </div>
      <span class="geo-stat-hint"><?= $averageScore >= 80 ? '✨ 结构化信号优良' : ($averageScore >= 50 ? '⚡ 部分文章需补强' : '⚠️ 存在较多空缺字段') ?></span>
    </div>

    <div class="geo-stat-card">
      <span class="geo-stat-label">已发布文章统计</span>
      <div class="geo-stat-value"><?= (int) $publishedCount ?><span class="geo-stat-unit">篇</span></div>
      <span class="geo-stat-hint">其中 <?= (int) $excellentCount ?> 篇达到 80 分以上 (<?= (int) $excellentPercentage ?>%)</span>
    </div>

    <div class="geo-stat-card">
      <span class="geo-stat-label">评分分布格局</span>
      <div class="geo-distribution-bar">
        <?php if ($publishedCount > 0): ?>
          <div class="geo-dist-segment is-excellent" style="width: <?= round(($excellentCount / $publishedCount) * 100) ?>%;" title="优秀: <?= $excellentCount ?>篇"></div>
          <div class="geo-dist-segment is-good" style="width: <?= round(($goodCount / $publishedCount) * 100) ?>%;" title="良好: <?= $goodCount ?>篇"></div>
          <div class="geo-dist-segment is-weak" style="width: <?= round(($weakCount / $publishedCount) * 100) ?>%;" title="待优化: <?= $weakCount ?>篇"></div>
        <?php else: ?>
          <div class="geo-dist-segment" style="width: 100%; background: var(--line);"></div>
        <?php endif; ?>
      </div>
      <div class="geo-distribution-legend">
        <span><span class="dot is-excellent"></span>优秀 <?= (int) $excellentCount ?></span>
        <span><span class="dot is-good"></span>良好 <?= (int) $goodCount ?></span>
        <span><span class="dot is-weak"></span>待优化 <?= (int) $weakCount ?></span>
      </div>
    </div>
  </div>

  <?php if ($trends !== []): ?>
    <div class="geo-section-card">
      <h2>历史发布健康度趋势 (近 <?= count($trends) ?> 次快照)</h2>
      <p class="muted">每次正式发布文章时，系统自动对被发布文章及全站平均分打上不可变评分快照。</p>
      <div class="geo-trend-chart-wrap">
        <div class="geo-trend-chart">
          <?php foreach ($trends as $point): ?>
            <div class="geo-chart-col" title="<?= $escape($point['date']) ?>: 平均 <?= (int) $point['score'] ?> 分">
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

  <div class="geo-section-card">
    <div class="geo-card-header">
      <div>
        <h2>待优化文章排行榜</h2>
        <p class="muted">优先优化以下文章，可快速拉升独立站品牌的整体 AI 可信度与搜索展现。</p>
      </div>
    </div>

    <?php if ($topWeakest === []): ?>
      <p class="muted">暂无已发布的文章数据。</p>
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
                <strong><?= $sc->total ?></strong>分 · <?= $sc->gradeLabel() ?>
              </div>
            </div>
            <div class="article-row-meta">
              <span><?= $escape((string) $art->frontMatter->get('date')) ?> · <code>/articles/<?= $escape($art->slug) ?>/</code></span>
              <a class="button-link-secondary" href="<?= $path('/admin/articles/' . rawurlencode($art->slug) . '/edit') ?>">
                <span class="icon" aria-hidden="true">edit</span>进入微调优化
              </a>
            </div>
            <div class="geo-missing-tags">
              <?php foreach ($sc->breakdown as $field): ?>
                <?php if ($field['earned'] < $field['weight']): ?>
                  <span class="geo-missing-tag" title="<?= $escape($field['reason']) ?>">
                    缺失: <?= $escape($field['label']) ?> (-<?= $field['weight'] - $field['earned'] ?>分)
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
$title = 'GEO 仪表盘';
require __DIR__ . '/layout.php';
?>
