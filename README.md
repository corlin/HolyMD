# HolyMD

HolyMD 是一款面向个人品牌的静态优先 Markdown 博客管理工具。作者在 PHP 后台编辑文件系统中的 Markdown，发布时生成可由 Apache 直接提供的 HTML、CSS、RSS、sitemap、`llms.txt`、`llms-full.txt`、OpenGraph 与结构化数据；MySQL 只保存账号、任务、构建、审计和 GEO 建议等运行状态，不保存文章正文。

全站前台原生支持暗黑模式无闪烁切换（系统跟随/浅色/深色）、智能 TOC 文章目录提取与阅读时间预估，并内置向 LLM / AI 智能体开放的整站知识库全文导出文件 `/llms-full.txt`。

AI 的边界是 GEO 优化：它可以建议摘要、实体、元数据和结构化信息，但不能生成或改写正文。每一项建议都必须由管理员单独接受、编辑或拒绝；如果正文在审核后发生变化，旧建议会被拒绝并要求重新审核。

已接受的摘要、来源和结构化数据进入公开页面及发现文件；实体进入 Article JSON-LD，结构化 FAQ 同时生成可见问答和 FAQPage JSON-LD，alt text 按图片顺序应用，内部链接显示为文章关联链接。层级建议只作为参考，不会自动改写正文标题。旧版 `*_suggestion` 字段不会作为已确认事实发布。

## 运行要求

- PHP 8.4 或更高版本；扩展：PDO MySQL、mbstring、fileinfo、GD、Exif、Sodium、OpenSSL、JSON
- MySQL 8.0 或兼容版本
- Apache 2.4，启用 `mod_rewrite`、允许 `.htaccess`，并支持符号链接
- Composer 2
- Web 根目录必须指向 `public/`
- PHP 用户对 `content/`、`content/media/`、`public/` 具有写权限

## 本地启动

```bash
cp .env.example .env
# 编辑 .env：数据库、真实站点地址和个人品牌信息均不可保留占位值
composer install
php bin/holymd-migrate.php
HOLYMD_ADMIN_PASSWORD='a-unique-password-at-least-12-characters' \
  php bin/holymd-admin.php create --email admin@example.com --display-name 'Site administrator'
php bin/holymd-build.php --dry-run
php bin/holymd-prepare-release.php
php -S 127.0.0.1:8789 -t public bin/holymd-dev-router.php
```

后台地址为 `http://127.0.0.1:8789/admin/login`。在后台提交发布或 GEO 审核任务后，在本地终端运行 Worker 即可完成异步处理：

如需 GEO 审核，先生成 `.env` 所需的两项加密值；明文只存在于这个命令的进程环境中：

```bash
HOLYMD_GEO_PLAINTEXT_KEY='sk-your-provider-key' php bin/holymd-admin.php encrypt-geo-key
```

把输出的 `HOLYMD_GEO_API_CREDENTIAL` 与 `HOLYMD_GEO_API_KEY` 放入 `.env`，不要提交该文件。

```bash
php bin/holymd-worker.php
# 或在本地开发时启动轮询 Worker：
while true; do php bin/holymd-worker.php >/dev/null 2>&1; sleep 2; done
```

首次正式发布前执行部署预检：

```bash
php bin/holymd-check.php
```

## 验证

```bash
composer validate --strict
composer test
php bin/holymd-build.php --dry-run
```

生产部署、Cron、权限和回滚见 [共享主机部署手册](docs/operations/shared-hosting.md)，备份恢复见 [备份与恢复手册](docs/operations/backup-and-restore.md)。

## 内容与运行数据

- `content/articles/*.md`：文章唯一正文来源
- `content/versions/`：成功发布后登记的文章版本；`publish-inputs/` 与 `review-inputs/` 分别保存不可见的待发布输入和 GEO 审核输入
- 文章 front matter 的 `published_version`：当前公开内容所绑定的不可变版本；新建、自动保存、恢复和 GEO 审核均不会创建或推进内容版本，只有成功发布才会推进该指针并登记版本历史
- `content/media/`：经过图片解码验证的媒体
- `public/.holymd-current`：指向当前不可变静态版本的原子指针
- MySQL：账号、发布队列、GEO 审核、构建和审计状态

发布队列会把每个 publish job 绑定到点击发布时的不可变 publication input。Worker 使用该输入生成公开页面，同时保留点击后产生的更新草稿；只有 Worker 发布成功后，该输入才成为 `published_version` 和可恢复内容版本。withdraw job 不需要内容版本。

不要提交 `.env`、API 密钥、数据库口令、运行时 `content/` 或生成的 `public/site/`。
