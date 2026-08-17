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

  <div class="geo-grid-two-col">
    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2>品牌主题权威分布 (Topic Silos)</h2>
          <p class="muted">全站话题分类的内容积累度与该领域文章的平均 GEO 得分。</p>
        </div>
      </div>
      <?php if ($topicStats === []): ?>
        <p class="muted">全站文章暂未配置任何话题分类 (Topics)。</p>
      <?php else: ?>
        <div class="geo-topic-list">
          <?php foreach ($topicStats as $t): ?>
            <div class="geo-topic-row">
              <div class="geo-topic-info">
                <strong><?= $escape($t['name']) ?></strong>
                <span class="muted"><?= (int) $t['count'] ?> 篇文章</span>
              </div>
              <div class="geo-score-badge is-<?= $t['avgScore'] >= 80 ? 'excellent' : ($t['avgScore'] >= 50 ? 'good' : 'weak') ?>">
                均分 <strong><?= (int) $t['avgScore'] ?></strong>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="geo-section-card">
      <div class="geo-card-header">
        <div>
          <h2>核心知识图谱实体 (Entity Cluster)</h2>
          <p class="muted">全站文章提取的高频命名实体与核心概念覆盖度。</p>
        </div>
      </div>
      <?php if ($topEntities === []): ?>
        <p class="muted">暂无实体数据，可在编辑文章时补充 Entities 字段。</p>
      <?php else: ?>
        <div class="geo-entity-cloud">
          <?php foreach ($topEntities as $entityName => $count): ?>
            <span class="geo-entity-pill" title="出现在 <?= (int) $count ?> 篇文章中">
              <?= $escape($entityName) ?><small class="geo-entity-count"><?= (int) $count ?></small>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- AI 爬虫可观测性与访问追踪面板 -->
  <div class="geo-section-card">
    <div class="geo-card-header">
      <div>
        <h2>🤖 AI 爬虫可观测性 (AI Agent Observability)</h2>
        <p class="muted">实时追踪全球主流大模型与 AI 搜索引擎（GPTBot, Perplexity, ClaudeBot 等）对站点的抓取频次与内容偏好。</p>
      </div>
    </div>

    <div class="geo-ai-stat-row">
      <div class="geo-ai-stat-item">
        <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['total7d'] ?? 0) ?></span>
        <span class="geo-ai-stat-lbl">近 7 天 AI 抓取总计</span>
      </div>
      <div class="geo-ai-stat-item">
        <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['distinctBots7d'] ?? 0) ?></span>
        <span class="geo-ai-stat-lbl">活跃 AI 爬虫种类</span>
      </div>
      <div class="geo-ai-stat-item">
        <span class="geo-ai-stat-num"><?= (int) ($aiBotStats['llmsTxt7d'] ?? 0) ?></span>
        <span class="geo-ai-stat-lbl">llms.txt 知识库访问</span>
      </div>
    </div>

    <div class="geo-grid-two-col" style="margin-top: 16px; margin-bottom: 0;">
      <div>
        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">爬虫来源分布 (7 天)</h3>
        <?php if (empty($aiBotStats['botDistribution'])): ?>
          <p class="muted" style="font-size: 13px;">暂未捕获到 AI 爬虫访问记录。</p>
        <?php else: ?>
          <div class="geo-bot-dist-list">
            <?php foreach ($aiBotStats['botDistribution'] as $b): ?>
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
        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">最受 AI 关注的内容 (Top 5)</h3>
        <?php if (empty($aiBotStats['topPaths'])): ?>
          <p class="muted" style="font-size: 13px;">暂无热门被抓取内容。</p>
        <?php else: ?>
          <ul class="geo-crawled-paths-list">
            <?php foreach ($aiBotStats['topPaths'] as $tp): ?>
              <li>
                <code><?= $escape($tp['path']) ?></code>
                <span class="geo-badge-count"><?= (int) $tp['count'] ?> 次抓取</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($aiBotStats['recentVisits'])): ?>
      <div style="margin-top: 20px; border-top: 1px solid var(--line); padding-top: 16px;">
        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">最近 AI 抓取实时流水 (Recent Stream)</h3>
        <div class="geo-stream-list">
          <?php foreach ($aiBotStats['recentVisits'] as $v): ?>
            <div class="geo-stream-item">
              <span class="geo-bot-pill is-<?= strtolower(preg_replace('/[^a-z0-9]/i', '', $v['bot_name'])) ?>"><?= $escape($v['bot_name']) ?></span>
              <span class="geo-stream-path"><code><?= $escape($v['request_path']) ?></code></span>
              <span class="geo-stream-status is-status-<?= $v['http_status'] ?>"><?= (int) $v['http_status'] ?></span>
              <span class="geo-stream-time muted"><?= $escape($v['created_at']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

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
