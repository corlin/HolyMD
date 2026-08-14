# HolyMD 共享主机部署手册

## 1. 主机与目录

选择 PHP 8.4、MySQL 8、Apache 2.4 主机。必须支持 `mod_rewrite` 与 `.htaccess`；发布指针使用普通文件，不再依赖符号链接。域名的 DocumentRoot 指向项目的 `public/`，不能指向项目根目录。

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

`holymd-prepare-release.php` 不移动或隐藏旧站。它会先为尚未迁移的已发布文章建立 `published_version` 不可变快照；后续新建、自动保存、恢复和 GEO 审核都不推进内容版本。发布任务先绑定不可见的待发布输入，只有构建并切换成功后才登记新版本并移动公开版本指针。静态站生成完成后再原子替换 `public/.holymd-current` **指针文件**（内容为 release 目录相对路径，由 `public/index.php` 解析）。指针机制不依赖符号链接，`symlink()` 被禁用的共享主机同样可用。

## 5. Apache 与路由验收

保留仓库的 `public/.htaccess`。所有请求统一进入 `public/index.php`，由它解析指针文件并从 release 树提供页面与资源；`assets/admin.css|admin.js|fonts/*.woff2` 与真实文件直通 Apache，`.env` 与项目内部路径被显式拒绝。

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

静态站回滚可以把 `public/.holymd-current` 指针文件原子改写为 `public/..holymd-current-releases/` 中已验证的旧版本（写入临时文件后 `mv` 替换，禁止先删除当前指针）。完成后重新运行 HTTP 验收。

## 8. nginx 伪静态与扁平部署（固定 DocumentRoot 的虚机）

部分共享主机（如万网系）DocumentRoot 固定在 `htdocs/`、只有 nginx 且不读 `.htaccess`。部署方式：

1. 项目文件直接铺到 `htdocs/`（项目根 = docroot），把 `public/` 内容（index.php、.htaccess、assets/）也移到 `htdocs/` 根。`public/index.php` 检测到 `.env` 与自身同目录时自动按扁平布局计算项目根、指针与资源路径；标准部署（DocumentRoot 指向 `public/`）行为不变。
2. `.env` 设置 `HOLYMD_BASE_PATH`（子目录部署如 `/holymd`；根部署留空）与 `HOLYMD_SYNC_PUBLISH="1"`（无 `exec`/`proc_open` 的主机无法运行 cron worker，发布与 GEO 审查改为请求内同步执行）。
3. 在控制台"伪静态设置"写入 nginx 规则：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/(.*)$ /index.php last;
    }
}
location ~ /\.ht {
    deny all;
}
location ^~ /src/ { deny all; }
location ^~ /vendor/ { deny all; }
location ^~ /content/ { deny all; }
location ^~ /bin/ { deny all; }
location ^~ /database/ { deny all; }
location ^~ /templates/ { deny all; }
location ^~ /cron/ { deny all; }
location ^~ /docs/ { deny all; }
location ~ ^/(\.env|composer\.(json|lock)|\.holymd|README) { deny all; }
```

4. 此类主机通常禁用 `exec`/`proc_open`/`putenv`/`symlink` 且缺少 `ext-sodium`——HolyMD 已适配：环境走内存覆盖表（`src/Config/Env.php`）、凭据加密用 OpenSSL AES-256-GCM（`ext-sodium` 不再要求）、换链用指针文件、队列可选同步。
5. 无 SSH 时，数据库迁移与管理员创建用一次性 Web 脚本执行后立即删除（`Migrator` + `AccountCommands`）；初始 release 也可本地预构建后上传并改写指针。

验收同 §5，另需确认 `.env`、`/content/`、`/src/` 返回 403，登录与发布全流程可用。
