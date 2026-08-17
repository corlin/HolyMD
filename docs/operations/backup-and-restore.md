# HolyMD 备份与恢复手册

完整备份必须同时覆盖文件系统与 MySQL。文章 Markdown 是正文唯一来源，只备份数据库无法恢复站点。

## 备份

在没有发布任务运行时执行。先用后台 Jobs 页面或 `php bin/holymd-admin.php jobs` 确认没有 queued/running 任务，再暂停 Cron：

```bash
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=/home/account-private/holymd-backups/$stamp
umask 077
mkdir -p "$backup_root"
cd /home/account/holymd
tar -czf "$backup_root/content.tar.gz" content
cp .env "$backup_root/env.copy"
mysqldump --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 -h DB_HOST -u DB_USER -p DB_NAME \
  > "$backup_root/holymd.sql"
(cd "$backup_root" && sha256sum content.tar.gz env.copy holymd.sql > SHA256SUMS)
```

### 一键备份

仓库提供 `php bin/holymd-backup.php`，按上述流程自动完成：在项目根 `backups/<UTC 时间戳>/` 下生成 `content.tar.gz`、`holymd.sql`（数据库 dump）、`env.copy` 与 `SHA256SUMS`，目录权限 0700。脚本要求 `tar`、`mysqldump`、`sha256sum` 可用；数据库口令经 `MYSQL_PWD` 环境传入，不会出现在进程参数中。输出目录位于 `content/` 之外，不会被归档递归包含。手工命令保留为兜底方案。

将备份复制到主机之外的加密存储，设置保留周期，并定期在隔离环境试恢复。`.env` 含密钥，必须加密且限制访问。生成静态站可由 Markdown 重建，通常无需长期备份全部 release 目录。

## 恢复

1. 停止 Cron，阻止管理员发布。
2. 部署与备份兼容的 HolyMD 代码并运行 `composer install --no-dev --classmap-authoritative`。
3. 进入已复制的备份目录并校验：`cd /path/to/restored-backup && sha256sum -c SHA256SUMS`。清单使用相对文件名，只验证当前恢复副本。
4. 恢复 `content/` 与受保护的 `.env`，确认属主和最小写权限。
5. 创建空数据库并导入：`mysql -h DB_HOST -u DB_USER -p DB_NAME < holymd.sql`。
6. 运行 `php bin/holymd-migrate.php`，让恢复库升级到当前 schema。
7. 运行 `php bin/holymd-build.php --dry-run`、`php bin/holymd-prepare-release.php` 和 `php bin/holymd-check.php`。
8. 运行 `php bin/holymd-build.php --rebuild` 从已确认的 `published_version` 重建完整公开树；不要通过修改文章或伪造一次发布来完成恢复。
9. 完成首页、文章、RSS、Atom、JSON Feed、搜索索引、sitemap、`llms.txt`、404 和后台登录验收后再恢复 Cron。

## 恢复验收

- `content/articles` 数量、媒体文件数量与备份清单一致。
- 管理员能登录，版本历史可见。
- 已发布文章的 Markdown 正文哈希与备份一致。
- GEO 建议状态可见；接受元数据建议不会改变正文哈希。
- 数据库会话为 UTC，后台时间按 `HOLYMD_TIMEZONE` 显示；恢复后不手工平移显式 UTC 字段。
- 公共 HTML 的 canonical、语言、Person/Article/Breadcrumb JSON-LD、sources、RSS、sitemap 正确。
- 生成的 HTML、JSON、XML 与 `llms` 文本是有效 UTF-8，不含 Unicode 替换字符。
- 不存在公开可访问的 `.env`、数据库 dump、备份或 `content/` 路径。
