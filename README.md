# HolyMD

HolyMD 是一款面向个人品牌的**静态优先** Markdown 博客管理工具。作者在 PHP 后台沉浸式编辑文件系统中的 Markdown 文章与单页，发布时原子生成可直接由 Apache/Nginx 提供服务的极速静态 HTML、CSS、RSS、Atom、JSON Feed、Sitemap、`llms.txt`、`llms-full.txt`、OpenGraph 与结构化数据；MySQL 仅保存账号、任务队列、构建快照、审计日志和 GEO AI 建议等运行状态，不保存文章正文。

---

## 🌟 核心特性

### 1. 沉浸式写作工作室 (Writing Studio)
- **实时对照预览 (Live Preview)**：基于 League CommonMark 的即时渲染，安全过滤危险脚本。
- **60fps 视口比例联动滚动 (Synchronized Scrolling)**：编辑区与预览区滚动位置毫秒级比例同步，双栏独立等高视口，无回环死锁。
- **智能光标对齐 (Smart Cursor Centering)**：光标在编辑器内上下移动或输入时，自动识别最近标题或段落，将预览区对应小节平滑居中显示（内置 150ms 防抖）。
- **无感自动保存**：编辑时后台安全自动保存草稿，保留当前编辑版本校验和（Checksum），多端并发编辑冲突保护。
- **全站不可变版本快照与一键回滚 (Version Control & Restore)**：文章（Articles）与自定义单页（Pages）在每次正式发布时自动生成不可变快照，支持在右侧栏随时查看历史发布列表并一键回退。
- **统一克制的危险操作区 (Danger Zone) 与级联彻底清理**：
  - 彻底移除粗暴的原生 `prompt()` 弹窗与突兀大红按钮，统一收纳为折叠式 `<details class="danger-zone">`；
  - 严格两阶段生命周期保护（已发布内容需先 Withdraw 撤回为草稿方可删除，防止线上误删）；
  - 确认删除时自动级联清除对应 Slug 在 `content/versions/` 下关联的所有历史快照文件与索引记录，不留孤立垃圾数据。
- **自定义单页管理 (Pages)**：支持关于页、条款页等独立单页的撰写、导航权重排序、多版本回滚及静态构建。

### 2. 前台体验与现代发现体系
- **统一刊物流首页 (Unified Editorial Stream)**：去除冗余的重复 Hero 大标题，呈现高质感的“极简简介 $\to$ 精致置顶 Featured 文章卡片 $\to$ 最新文章流 $\to$ 底部话题胶囊”。
- **暗黑模式无闪烁切换**：原生 CSS 自定义属性驱动，支持系统跟随、浅色、深色三种模式，页面加载零闪烁。
- **画廊式图片弹窗预览 (Image Viewer)**：支持键盘快捷键导航（左右切换、`+`/`-` 缩放、`0` 还原、`Esc` 关闭）。
- **全站全局毫秒级搜索**：置于全站顶部导航栏的悬浮微弹窗，支持 `⌘K` / `Ctrl+K` / `/` 快速激活与 `Esc` 关闭，毫秒级即时过滤，全站任意页面均可即时检索。
- **LLM / AI 智能体发现生态**：全站自动生成 `/llms.txt` 与包含全站公开文章正文的 `/llms-full.txt` 全文知识库。
- **页脚品牌与开源连接**：内联优雅展示 `Powered by HolyMD` 并直连 GitHub 开源仓库。

### 3. AI GEO 边界与智能静默自动补全
- AI 的边界严格限定为 **GEO（Generative Engine Optimization）优化**：它可针对当前文章提取摘要、命名实体、FAQ 候选、元数据、内链与图片 Alt，但**绝不自动篡改 Markdown 正文**。
- **极简无感自动化**：保存或发布文章时，系统在后台异步调用 GEO 模型，对未填写的空元数据字段进行自动补齐，已手动填写的内容则绝对保留不被覆盖。
- 界面彻底移除繁重的审核卡片与 Diff 比较框，收纳为右侧折叠抽屉，使写作者始终沉浸于创作。

---

## 🛠️ 运行要求

- **PHP**：8.4 或更高版本（PHP 8.5 兼容）；扩展：`pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `exif`, `sodium`, `openssl`, `json`, `curl`
- **MySQL**：8.0 或兼容版本（如 MariaDB 10.5+）
- **Web 服务器**：Apache 2.4（需启用 `mod_rewrite`、允许 `.htaccess`，支持符号链接）或 Nginx
- **包管理器**：Composer 2
- **文件权限**：Web 根目录指向 `public/`；PHP 用户对 `content/`、`content/media/`、`public/` 具备写权限

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

---

## 🧪 测试与质量验证

```bash
# 校验 composer 配置
composer validate --strict

# 运行全量单元测试与端到端测试 (253 tests, 1008 assertions)
./vendor/bin/phpunit tests

# 验证静态站点构建
php bin/holymd-build.php --dry-run
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
│   └── versions/           # 发布后的不可变快照及审核暂存
├── database/
│   ├── schema.sql          # 基础数据库表结构
│   └── migrations/         # 增量 SQL 迁移脚本
├── public/                 # Web 根目录与静态资产
│   ├── assets/             # 后台 CSS / JS 与字体资产
│   ├── .holymd-current     # 指向当前不可变静态版本的原子符号链接/指针
│   └── site/               # 静态构建产物 (可直接分发至 CDN/对象存储)
├── src/                    # 核心业务逻辑 (PSR-4 HolyMD\)
└── templates/              # 前后台 PHP 视图组件与 Partials
```

---

## 📖 相关文档
- [共享主机部署手册](docs/operations/shared-hosting.md)
- [备份与恢复手册](docs/operations/backup-and-restore.md)
