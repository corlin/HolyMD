# HolyMD 共享主机部署手册

## 1. 主机与目录

选择 PHP 8.4、MySQL 8、Apache 2.4 主机。必须支持 `mod_rewrite`、`.htaccess` 和同一文件系统内的符号链接。域名的 DocumentRoot 指向项目的 `public/`，不能指向项目根目录。

推荐目录：

```text
/home/account/holymd/          项目、vendor、content、bin
/home/account/holymd/public/   唯一可公开访问的 DocumentRoot
```

上传代码后安装生产依赖：

```bash
cd /home/account/holymd
composer install --no-dev --classmap-authoritative
cp .env.example .env
chmod 600 .env
mkdir -p content/articles content/versions content/media content/audit public/site
chmod -R u+rwX,go-rwx content
chmod u+rwx public public/site
```

如果 Web 服务器与 SSH 用户不同组，使用主机面板设置等效的最小写权限；不要使用 `chmod -R 777`。

## 2. 环境配置

编辑 `.env`：

- `HOLYMD_DSN`、数据库用户和口令指向专用的 UTF-8 MySQL 数据库；该用户只需此数据库权限。
- `HOLYMD_SITE_NAME`、`HOLYMD_SITE_URL`、`HOLYMD_AUTHOR_NAME`、`HOLYMD_ABOUT` 必须是真实公开身份；占位值会阻止发布。
- `HOLYMD_SITE_LANGUAGE` 使用 BCP 47 标签，例如 `zh-CN`。
- 需要 GEO 时配置 OpenAI-compatible HTTPS endpoint、模型和加密凭据。endpoint 必须解析为公开全球单播地址，私网、回环、保留和文档地址会被拒绝。

不要把 `.env` 放进 `public/`，不要将 API key 写入文章或数据库。

## 3. 初始化与升级

每次部署新版本都执行幂等迁移：

```bash
php bin/holymd-migrate.php
```

全新数据库会载入 `database/schema.sql`；旧数据库会按实际列和索引状态补齐并记录到 `schema_migrations`。命令失败时不要继续发布。

首次创建管理员：

```bash
HOLYMD_ADMIN_PASSWORD='use-a-password-manager-value' \
php bin/holymd-admin.php create --email you@example.com --display-name 'Your name'
```

口令只通过当前进程环境传入，不写入命令脚本或 `.env`。

账号运维命令：

```bash
php bin/holymd-admin.php list
HOLYMD_ADMIN_PASSWORD='...' php bin/holymd-admin.php password-reset --email you@example.com
php bin/holymd-admin.php disable --email old@example.com
php bin/holymd-admin.php enable --email old@example.com
php bin/holymd-admin.php unlock --email you@example.com
php bin/holymd-admin.php jobs
```

`password-reset` 同时清除失败计数与锁定；`disable` 拒绝禁用最后一个活跃管理员。连续 5 次登录失败会锁定账号 15 分钟，用 `unlock` 或 `password-reset` 解除。`jobs` 输出队列汇总与最近任务；后台 `/admin/jobs` 页面提供同样信息，构建永久失败会在这里出现。

## 4. 静态发布指针

首次部署先保留 `public/site/` 作为可见旧站，再安装独立指针：

```bash
php bin/holymd-build.php --dry-run
php bin/holymd-prepare-release.php
php bin/holymd-check.php
```

`holymd-prepare-release.php` 不移动或隐藏旧站。后续发布会先生成完整不可变版本，再原子替换 `public/.holymd-current` 符号链接。若主机禁止符号链接，准备命令会失败；不要绕过检查上线，因为 HolyMD 不会用有可见空窗的双重目录重命名降级替代。

## 5. Apache 与路由验收

保留仓库的 `public/.htaccess`。它让 `/admin` 进入 PHP，而首页、文章、主题、RSS、sitemap 和静态资源直接从 `.holymd-current` 提供。

部署后检查：

```bash
curl -fsS https://your-domain.example/ >/dev/null
curl -fsS https://your-domain.example/sitemap.xml >/dev/null
curl -fsS https://your-domain.example/rss.xml >/dev/null
curl -fsS https://your-domain.example/admin/login >/dev/null
test "$(curl -sS -o /dev/null -w '%{http_code}' https://your-domain.example/not-a-real-page)" = 404
```

还应检查一篇无尾斜杠的文章 URL 返回 200，且主机访问日志显示公开静态请求未进入 `index.php`。

历史 slug（`previous_slugs`）的 301 重定向由发布时写入 release 树根的 `.htaccess` 提供，meta-refresh 页面保留为兜底。验收：

```bash
curl -sI https://your-domain.example/articles/<old-slug>/ | grep -i '^HTTP\|^location'
```

release 目录内的 `.htaccess` 依赖宿主对该路径的 `AllowOverride`（通常与 `public/.htaccess` 同开）。若宿主不允许而 301 不生效，meta-refresh 页面仍会跳转，不影响正确性。

## 6. Cron 队列

在主机面板设置每分钟一次的 Cron，使用 PHP CLI 的绝对路径：

```cron
* * * * * /usr/local/bin/php /home/account/holymd/cron/holymd.php >> /home/account/holymd/content/cron.log 2>&1
```

Cron 内置非阻塞文件锁，单次领取一个任务；GEO 暂时错误最多重试 3 次，永久的认证/配置/响应错误不会重复付费调用。首次配置后，在后台发布一篇测试草稿并确认 `jobs`、`builds` 从 queued/running 进入 succeeded。

## 7. 发布与回滚

部署代码前先备份：`php bin/holymd-backup.php`（见备份与恢复手册）。推荐顺序：维护窗口内上传新代码、`composer install`、迁移、测试 dry-run、运行 check，再恢复 Cron。代码回滚时，数据库只向前兼容；不要删除迁移列。

静态站回滚可以把 `public/.holymd-current` 原子指向 `public/..holymd-current-releases/` 中已验证的旧版本。先创建新符号链接并用 `mv` 在同一目录替换，禁止先删除当前指针。完成后重新运行 HTTP 验收。
