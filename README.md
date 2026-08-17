# HolyMD

HolyMD 是一款面向个人品牌的**静态优先** Markdown 博客管理工具。作者在 PHP 后台沉浸式编辑文件系统中的 Markdown 文章与单页，发布时原子生成 HTML、CSS、RSS、Atom、JSON Feed、Sitemap、`llms.txt`、`llms-full.txt`、OpenGraph 与结构化数据。标准共享主机部署由轻量 PHP 指针解析器读取这些预生成文件，支持直接静态映射的服务器也可绕过该解析层；MySQL 仅保存账号、任务队列、构建快照、审计日志和 GEO AI 建议等运行状态，不保存文章正文。

---

## 🌟 核心特性

### 1. 沉浸式写作工作室 (Writing Studio)
- **实时对照预览 (Live Preview)**：基于 League CommonMark 的即时渲染，安全过滤危险脚本。
- **60fps 视口比例联动滚动 (Synchronized Scrolling)**：编辑区与预览区滚动位置毫秒级比例同步，双栏独立等高视口，无回环死锁。
- **智能光标对齐 (Smart Cursor Centering)**：光标在编辑器内上下移动或输入时，自动识别最近标题或段落，将预览区对应小节平滑居中显示（内置 150ms 防抖）。
- **无感自动保存**：编辑时后台安全自动保存草稿，保留当前编辑版本校验和（Checksum），多端并发编辑冲突保护。
- **发布前检查 (Publish Preflight)**：正式发布前由服务端对精确候选内容重新执行元数据、静态构建和 GEO 检查，显示字段变化与评分变化；阻断错误必须修复，建议项需显式确认且确认值与候选内容校验和绑定。
- **全站不可变版本快照与一键回滚 (Version Control & Restore)**：文章（Articles）与自定义单页（Pages）在每次正式发布时自动生成不可变快照，支持在右侧栏随时查看历史发布列表并一键回退。
- **统一克制的危险操作区 (Danger Zone) 与级联彻底清理**：
  - 彻底移除粗暴的原生 `prompt()` 弹窗与突兀大红按钮，统一收纳为折叠式 `<details class="danger-zone">`；
  - 严格两阶段生命周期保护（已发布内容需先 Withdraw 进入 `withdrawn` 状态，之后才允许删除，防止线上误删）；
  - 确认删除时自动级联清除对应 Slug 在 `content/versions/` 下关联的所有历史快照文件与索引记录，不留孤立垃圾数据。
- **自定义单页管理 (Pages)**：支持关于页、条款页等独立单页的撰写、导航权重排序、多版本回滚及静态构建。

### 2. 前台体验与现代发现体系
- **统一刊物流首页 (Unified Editorial Stream)**：去除冗余的重复 Hero 大标题，呈现高质感的“极简简介 $\to$ 精致置顶 Featured 文章卡片 $\to$ 最新文章流 $\to$ 底部话题胶囊”。
- **暗黑模式无闪烁切换**：原生 CSS 自定义属性驱动，支持系统跟随、浅色、深色三种模式，页面加载零闪烁。
- **画廊式图片弹窗预览 (Image Viewer)**：支持键盘快捷键导航（左右切换、`+`/`-` 缩放、`0` 还原、`Esc` 关闭）。
- **全站全局毫秒级搜索**：置于全站顶部导航栏的悬浮微弹窗，支持 `⌘K` / `Ctrl+K` / `/` 快速激活与 `Esc` 关闭，毫秒级即时过滤，全站任意页面均可即时检索。
- **LLM / AI 智能体发现生态**：全站自动生成 `/llms.txt` 与包含全站公开文章正文的 `/llms-full.txt` 全文知识库。
- **页脚品牌与开源连接**：内联优雅展示 `Powered by HolyMD` 并直连 GitHub 开源仓库。

### 3. AI GEO 边界、E-E-A-T 品牌资产与全站可观测性看板 (GEO Intelligence)
- **严格安全边界**：AI 的边界严格限定为 **GEO（Generative Engine Optimization）优化**：它可针对当前文章提取摘要、命名实体、FAQ 候选、元数据、内链与图片 Alt，但**绝不自动篡改 Markdown 正文**。
- **极简 Lazy 模式（零 Token 浪费）**：草稿编辑和自动保存 0 LLM 消耗；仅在作者显式点击「一键分析」时按需调用大模型，且原生兼容 DeepSeek、Claude、Gemini 等各类 OpenAI 规范接口。
- **全站 E-E-A-T 结构化图谱与 Schema.org 严格校验**：
  - **首页 / 文章页 / 单页全覆盖**：首页自动挂载 `WebSite` 与 `Person`（含作者 URL 与 Bio）；文章页规范输出 `BlogPosting`、`author`、`publisher`（Organization）、`inLanguage` 及 `FAQPage`；自定义单页精准生成 `WebPage` / `AboutPage`；
  - **本地纯代码校验**：在保存时本地校验 `structured_data` 的 `@type` 与合法结构，杜绝错误数据发布。
- **加权百分制 GEO 评分体系 (GEO Scorecard)**：
  - **8 大多维加权评判 (0-100)**：综合评判 `summary`(20分)、`structured_data`(20分)、`faq`(15分)、`entities`(10分)、`topics`(10分)、`sources`(10分)、`internal_links`(10分) 与 `alt_text`(5分，无图自动豁免)；
  - **正文链接无感自动感知**：自动识别 Markdown 正文中自然书写的权威外链 `[xxx](https://...)` 与站内互链 `[xxx](/articles/...)`，免去在元数据表单中重复录入；
  - **多行实体智能拆分**：支持多行文本与逗号分割的多命名实体自动精确提取；
- **三处联动与时序归档**：在文章列表、写作工作室右侧栏统一「GEO 智能引擎」卡片与 GEO 看板实现三处联动感知，并在文章正式发布时自动记录不可变历史趋势快照。
- **同步/异步一致归档**：浏览器内同步发布与 Cron Worker 异步发布均在公开树成功切换后记录同一发布快照的 GEO 得分；评分记录失败会进入补偿审计，不会伪装成公开内容回滚。
- **品牌主题与知识图谱实体矩阵 (Topic & Entity Cluster)**：
  - 在 GEO 看板直观展现全站话题分类（Topics）的内容积淀度与均分，以及高频命名实体（Entities）词频词云，秒级呈现品牌在各专业领域的权威深度。
- **AI 爬虫可观测性与访问追踪 (AI Bot Observability)**：
  - 入口层轻量识别全球主流 AI 爬虫（GPTBot, PerplexityBot, ClaudeBot, Google-AI, Bytespider 等）；
  - 单向哈希匿名脱敏记录入库，在 GEO 看板紧凑呈现：近 7 天抓取总次数、爬虫阵营占比、最受 AI 关注的内容与最新抓取微流水。

---

## 🛠️ 运行要求

- **PHP**：8.4 或更高版本（PHP 8.5 兼容）；扩展：`pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `exif`, `sodium`, `openssl`, `json`, `curl`
- **MySQL**：8.0 或兼容版本（如 MariaDB 10.5+）
- **Web 服务器**：Apache 2.4（需启用 `mod_rewrite`、允许 `.htaccess`，支持符号链接）或 Nginx
- **包管理器**：Composer 2
- **文件权限**：Web 根目录指向 `public/`；PHP 用户对 `content/`、`content/media/`、`public/` 具备写权限
- **时间模型**：MySQL 运行状态统一按 UTC 保存；后台按 `HOLYMD_TIMEZONE` 展示，默认 `Asia/Singapore`

---

## 🚀 本地快速启动

```bash
# 1. 复制配置文件并修改（填入真实数据库配置与个人信息）
cp .env.example .env

# 2. 安装依赖
composer install

# 3. 执行数据库增量迁移
php bin/holymd-migrate.php

# 4. 创建初始管理员账号
HOLYMD_ADMIN_PASSWORD='a-unique-password-at-least-12-characters' \
  php bin/holymd-admin.php create --email admin@example.com --display-name 'Site administrator'

# 5. 准备静态发布指针并验证构建
php bin/holymd-prepare-release.php
php bin/holymd-build.php --dry-run

# 6. 启动本地开发服务
php -S 127.0.0.1:8789 -t public bin/holymd-dev-router.php
```

- **公开站首页**：`http://127.0.0.1:8789/`
- **管理后台**：`http://127.0.0.1:8789/admin/login`

### 异步 Worker 启动
在后台提交发布或 GEO 审核任务后，在本地终端运行 Worker 完成异步队列处理：

```bash
# 执行单次队列处理：
php bin/holymd-worker.php

# 或本地开发持续轮询：
while true; do php bin/holymd-worker.php >/dev/null 2>&1; sleep 2; done
```

若配置 GEO 审核，使用加密工具将 API Key 密文写入 `.env`（明文仅存在于当前会话环境变量）：
```bash
HOLYMD_GEO_PLAINTEXT_KEY='sk-your-provider-key' php bin/holymd-admin.php encrypt-geo-key
```

首次正式部署前运行全项检查：
```bash
php bin/holymd-check.php
```

### 发布工作流

1. 编辑器自动保存 Markdown 草稿，但不创建公开版本。
2. 点击 Publish / Update Public 后，服务端对当前候选内容执行只读预检，列出字段变化、阻断项、建议项与 GEO 分数变化。
3. 阻断项必须修复；仅有建议项时需要显式确认。确认值与候选内容 SHA-256 绑定，内容变化后旧确认自动失效。
4. 发布请求捕获不可变 `publish-inputs/` 快照；异步部署由 Worker 处理，同步部署在当前请求内处理。
5. 只有静态构建成功并完成原子指针切换后，才登记可恢复版本、推进 `published_version` 并记录 GEO 分数。
6. Withdraw 会从公开树移除内容；处于 `draft` 或 `withdrawn` 状态的文章才允许永久删除。

预检中的 GEO 建议只用于编辑决策，不保证搜索收录、排名或 AI 引用。

### 常用环境变量

| 变量 | 作用 | 默认值 |
| --- | --- | --- |
| `HOLYMD_TIMEZONE` | 后台时间显示时区；数据库和机器输出仍为 UTC | `Asia/Singapore` |
| `HOLYMD_BASE_PATH` | 子目录部署路径，例如 `/holymd` | 空 |
| `HOLYMD_SYNC_PUBLISH` | 无 Cron/进程能力的主机设为 `1`，在请求内执行发布与 GEO 审核 | `0` |
| `HOLYMD_PUBLIC_TREE` | 自定义发布指针路径；未设置时使用 `public/.holymd-current` | 项目默认路径 |
| `HOLYMD_LLMS_TXT` | 是否生成 `llms.txt` 与 `llms-full.txt` | `1` |

---

## 🧪 测试与质量验证

```bash
# 校验 composer 配置
composer validate --strict

# 运行全量单元测试与端到端测试 (284 tests, 1184 assertions)
./vendor/bin/phpunit tests

# 验证静态站点构建
php bin/holymd-build.php --dry-run

# 模拟各大主流 AI 爬虫抓取测试 (GPTBot / Perplexity / ClaudeBot / Bytespider)
curl -s -o /dev/null -H "User-Agent: Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)" http://127.0.0.1:8789/llms.txt
curl -s -o /dev/null -H "User-Agent: Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://docs.perplexity.ai/docs/perplexitybot)" http://127.0.0.1:8789/
curl -s -o /dev/null -H "User-Agent: ClaudeBot/1.0; +claudebot@anthropic.com" http://127.0.0.1:8789/llms-full.txt
curl -s -o /dev/null -H "User-Agent: Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)" http://127.0.0.1:8789/
```

---

## 📂 目录与数据结构

```text
HolyMD/
├── bin/                    # 运维与 CLI 工具集 (构建/迁移/预检/Worker)
├── content/                # 文件系统数据源
│   ├── articles/           # 文章唯一正文来源 (*.md)
│   ├── pages/              # 独立自定义单页 (*.md)
│   ├── media/              # 上传并经图片解码验证的媒体文件
│   └── versions/           # 已发布版本索引，以及隐藏的 review-inputs / publish-inputs 快照
├── database/
│   ├── schema.sql          # 基础数据库表结构
│   └── migrations/         # 增量 SQL 迁移脚本
├── public/                 # Web 根目录与静态资产
│   ├── assets/             # 后台 CSS / JS 与字体资产
│   ├── .holymd-current     # 当前不可变 release 的原子指针文件
│   ├── ..holymd-current-releases/  # 不可变 release 目录（运行时生成）
│   └── site/               # 首次迁移前保留的兼容/引导静态树
├── src/                    # 核心业务逻辑 (PSR-4 HolyMD\)
└── templates/              # 前后台 PHP 视图组件与 Partials
```

---

## 📖 相关文档
- [共享主机部署手册](docs/operations/shared-hosting.md)
- [备份与恢复手册](docs/operations/backup-and-restore.md)
- [产品设计规格（已交付）](docs/superpowers/specs/2026-08-12-holymd-design.md)
- [首版实施计划（已归档）](docs/superpowers/plans/2026-08-12-holymd-implementation.md)
- [发布预检与回归加固设计（已交付）](docs/superpowers/specs/2026-08-17-publish-preflight-regression-hardening-design.md)
- [发布预检与回归加固计划（已完成）](docs/superpowers/plans/2026-08-17-publish-preflight-regression-hardening.md)
- [公开阅读体验设计验收记录](design-qa.md)
