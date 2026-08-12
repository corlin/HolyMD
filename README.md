# HolyMD

HolyMD 是一款面向个人品牌的静态优先 Markdown 博客管理工具。作者在 PHP 后台编辑文件系统中的 Markdown，发布时生成可由 Apache 直接提供的 HTML、CSS、RSS、sitemap、`llms.txt` 与结构化数据；MySQL 只保存账号、任务、构建、审计和 GEO 建议等运行状态，不保存文章正文。

AI 的边界是 GEO 优化：它可以建议摘要、实体、元数据和结构化信息，但不能生成或改写正文。每一项建议都必须由管理员单独接受、编辑或拒绝，正文哈希在接受前后保持不变。

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
php -S 127.0.0.1:8789 -t public bin/holymd-dev-router.php
```

后台地址为 `http://127.0.0.1:8789/admin/login`。首次正式发布前执行：

```bash
php bin/holymd-prepare-release.php
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
- `content/versions/`：文章版本快照
- `content/media/`：经过图片解码验证的媒体
- `public/.holymd-current`：指向当前不可变静态版本的原子指针
- MySQL：账号、发布队列、GEO 审核、构建和审计状态

不要提交 `.env`、API 密钥、数据库口令、运行时 `content/` 或生成的 `public/site/`。
