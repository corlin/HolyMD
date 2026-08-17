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
  <p class="eyebrow">Search & AI Observability</p>
  <h1>GEO 健康度看板</h1>
  <p class="muted">全站生成式引擎优化（GEO）健康评分与 AI 爬虫可观测性监控。</p>

  <div class="geo-section-card geo-hero-card">
    <div class="geo-hero-grid">
      <div class="geo-stat-card">
        <span class="geo-stat-label">全站平均 GEO 得分</span>
        <div class="geo-stat-value <?= $averageScore >= 80 ? 'is-excellent' : ($averageScore >= 50 ? 'is-good' : 'is-weak') ?>">
          <?= (int) $averageScore ?><span class="geo-stat-unit">/ 100</span>
        </div>
        <span class="geo-stat-hint"><?= $averageScore >= 80 ? '✨ 结构化信号优良' : ($averageScore >= 50 ? '⚡ 部分文章需补强' : '⚠️ 存在较多空缺字段') ?></span>
      </div>

      <div class="geo-stat-card">
        <span class="geo-stat-label">已发布文章达标率</span>
        <div class="geo-stat-value"><?= (int) $excellentPercentage ?><span class="geo-stat-unit">%</span></div>
        <span class="geo-stat-hint">共 <?= (int) $publishedCount ?> 篇文章，<?= (int) $excellentCount ?> 篇达到 80+ 优秀</span>
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
      <div class="geo-hero-trend">
        <div class="geo-card-header">
          <span class="geo-stat-label">发布健康度历史快照 (近 <?= count($trends) ?> 次)</span>
        </div>
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
  </div>

  <div class="geo-grid-two-col">
    <!-- 品牌主题与实体矩阵 -->
    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2>品牌主题与实体矩阵</h2>
          <p class="muted">话题分类的内容积累度与核心概念覆盖。</p>
        </div>
      </div>
      <?php if ($topicStats === [] && $topEntities === []): ?>
        <p class="muted">全站文章暂未配置话题或实体数据。</p>
      <?php else: ?>
        <?php if ($topicStats !== []): ?>
          <div class="geo-topic-list">
            <?php foreach (array_slice($topicStats, 0, 4) as $t): ?>
              <div class="geo-topic-row">
                <div class="geo-topic-info">
                  <strong><?= $escape($t['name']) ?></strong>
                  <span class="muted"><?= (int) $t['count'] ?> 篇</span>
                </div>
                <div class="geo-score-badge is-<?= $t['avgScore'] >= 80 ? 'excellent' : ($t['avgScore'] >= 50 ? 'good' : 'weak') ?>">
                  均分 <strong><?= (int) $t['avgScore'] ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($topEntities !== []): ?>
          <div class="geo-entity-cloud" style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line);">
            <?php foreach (array_slice($topEntities, 0, 16, true) as $entityName => $count): ?>
              <span class="geo-entity-pill" title="出现在 <?= (int) $count ?> 篇文章中">
                <?= $escape($entityName) ?><small class="geo-entity-count"><?= (int) $count ?></small>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- AI 爬虫可观测性 -->
    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2>AI 爬虫可观测性 (AI Bots)</h2>
          <p class="muted">实时追踪全球主流大模型与 AI 搜索的抓取频次与偏好。</p>
        </div>
      </div>

      <div class="geo-ai-stat-row">
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['total7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl">近 7 天抓取</span>
        </div>
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['distinctBots7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl">活跃爬虫种类</span>
        </div>
        <div class="geo-ai-stat-item">
          <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['llmsTxt7d'] ?? 0) ?></span>
          <span class="geo-ai-stat-lbl">llms.txt 访问</span>
        </div>
      </div>

      <div class="geo-bot-compact-grid">
        <div>
          <h3 class="geo-sub-title">来源分布</h3>
          <?php if (empty($aiBotStats['botDistribution'])): ?>
            <p class="muted" style="font-size: 12px;">暂无捕获记录</p>
          <?php else: ?>
            <div class="geo-bot-dist-list">
              <?php foreach (array_slice($aiBotStats['botDistribution'], 0, 4) as $b): ?>
                <div class="geo-bot-dist-row">
                  <div class="geo-bot-name-col">
                    <strong><?= $escape($b['bot_name']) ?></strong>
                    <span class="muted"><?= (int) $b['count'] ?> 次 (<?= (int) $b['percentage'] ?>%)</span>
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
          <h3 class="geo-sub-title">Top 热门内容</h3>
          <?php if (empty($aiBotStats['topPaths'])): ?>
            <p class="muted" style="font-size: 12px;">暂无热门路径</p>
          <?php else: ?>
            <ul class="geo-crawled-paths-list">
              <?php foreach (array_slice($aiBotStats['topPaths'], 0, 3) as $tp): ?>
                <li>
                  <code><?= $escape($tp['path']) ?></code>
                  <span class="geo-badge-count"><?= (int) $tp['count'] ?>次</span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($aiBotStats['recentVisits'])): ?>
        <div class="geo-stream-wrap">
          <h3 class="geo-sub-title">最近流水</h3>
          <div class="geo-stream-list">
            <?php foreach ($aiBotStats['recentVisits'] as $v): ?>
              <div class="geo-stream-item">
                <span class="geo-bot-pill is-<?= strtolower(preg_replace('/[^a-z0-9]/i', '', $v['bot_name'])) ?>"><?= $escape($v['bot_name']) ?></span>
                <span class="geo-stream-path"><code><?= $escape($v['request_path']) ?></code></span>
                <span class="geo-stream-status is-status-<?= $v['http_status'] ?>"><?= (int) $v['http_status'] ?></span>
                <span class="geo-stream-time muted"><?= $escape(substr($v['created_at'], 5, 11)) ?></span>
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
        <h2>待优化文章排行榜</h2>
        <p class="muted">优先补强以下文章的缺失字段，可快速拉升独立站整体 GEO 健康度与搜索展现。</p>
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

