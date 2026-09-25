# HolyMD Shared Hosting Deployment Guide

English | [简体中文](shared-hosting.zh-CN.md)

## 1. Host and directories

Choose a host with PHP 8.4+, MySQL 8, and Apache 2.4. It must support `mod_rewrite` and `.htaccess`. The deployment preflight requires `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `exif`, `sodium`, `openssl`, and `json`; the GEO client also needs `curl`. The release pointer is a plain file, so symbolic links are not required. Point the domain's DocumentRoot at the project's `public/` directory, never at the project root.

Recommended layout:

```text
/home/account/holymd/          project, vendor, content, bin
/home/account/holymd/public/   the only publicly reachable DocumentRoot
```

After uploading the code, install production dependencies:

```bash
cd /home/account/holymd
composer install --no-dev --classmap-authoritative
cp .env.example .env
chmod 600 .env
mkdir -p content/articles content/pages content/versions content/media content/audit public/site
chmod -R u+rwX,go-rwx content
chmod u+rwx public public/site
```

If the web server and the SSH user are in different groups, use the hosting panel to grant the equivalent minimum write permissions. Never use `chmod -R 777`.

## 2. Environment configuration

Edit `.env`:

- `HOLYMD_DSN`, the database user, and the password point to a dedicated UTF-8 MySQL database. The user only needs privileges on that database.
- `HOLYMD_SITE_NAME`, `HOLYMD_SITE_URL`, `HOLYMD_AUTHOR_NAME`, and `HOLYMD_ABOUT` must be your real public identity; placeholder values block publishing.
- `HOLYMD_SITE_LANGUAGE` is a BCP 47 tag such as `en` or `zh-CN`.
- `HOLYMD_TIMEZONE` only controls how the admin displays times (default `Asia/Singapore`). The database, queue, and audit records always use UTC.
- `HOLYMD_ADMIN_LOCALE` sets the admin interface language (`en` or `zh-CN`). When unset, Chinese sites default to Chinese and all others to English; administrators can also switch per browser from the sidebar.
- Set `HOLYMD_BASE_PATH` for subdirectory installs. Set `HOLYMD_SYNC_PUBLISH="1"` only when the host cannot run a cron/CLI worker.
- Usually leave `HOLYMD_PUBLIC_TREE` unset. Set it only to place releases in a custom location, pointing at a pointer file that PHP can write and the public cannot.
- To enable GEO, configure an OpenAI-compatible HTTPS endpoint, a model, and encrypted credentials. The endpoint must resolve to a public global unicast address; private, loopback, reserved, and documentation addresses are rejected.

Never place `.env` inside `public/`, and never write API keys into articles or the database.

## 3. Installation and upgrades

Run the idempotent migration on every deployment:

```bash
php bin/holymd-migrate.php
```

An empty database is created from `database/schema.sql` and all migrations are recorded as applied. An existing database is brought up to date by the pending migrations, which are recorded in `schema_migrations`. If the command fails, do not continue publishing.

Create the first administrator:

```bash
HOLYMD_ADMIN_PASSWORD='use-a-password-manager-value' \
php bin/holymd-admin.php create --email you@example.com --display-name 'Your name'
```

The password is passed only through the current process environment; do not write it into scripts or `.env`.

Account operations:

```bash
php bin/holymd-admin.php list
HOLYMD_ADMIN_PASSWORD='...' php bin/holymd-admin.php password-reset --email you@example.com
php bin/holymd-admin.php disable --email old@example.com
php bin/holymd-admin.php enable --email old@example.com
php bin/holymd-admin.php unlock --email you@example.com
php bin/holymd-admin.php jobs
```

`password-reset` also clears the failure count and any lockout. `disable` refuses to disable the last active administrator. Five consecutive failed sign-ins lock an account for 15 minutes; clear it with `unlock` or `password-reset`. `jobs` prints a queue summary and recent jobs. The `/admin/jobs` page shows the same information, and permanently failed builds appear there.

## 4. Static release pointer

On the first deployment, keep any existing `public/site/` as the visible legacy site, then install the release pointer:

```bash
php bin/holymd-build.php --dry-run
php bin/holymd-prepare-release.php
php bin/holymd-check.php
```

`holymd-prepare-release.php` does not move or hide the legacy site; on a fresh checkout it creates an empty `public/site/`. It first pins an immutable `published_version` snapshot for every published article that does not have one yet. Creating, autosaving, restoring, and GEO reviews never advance the public version. A publish job binds to a hidden `publish-inputs/` snapshot, and only after the build and switch succeed does it register the new version and move the public version pointer. When the static site is complete, the **pointer file** `public/.holymd-current` is replaced atomically. It contains the relative path of a release under `public/..holymd-current-releases/`, which `public/index.php` resolves. Because the pointer does not rely on symbolic links, it also works on shared hosts where `symlink()` is disabled.

After every code upgrade, run `php bin/holymd-migrate.php` first. `20260817_normalize_legacy_timestamps.sql` idempotently corrects time columns that older versions wrote in the database's default time zone. Do not manually shift lock, completion, decision, or crawler visit times that were already stored as explicit UTC.

## 5. Apache and routing checks

Keep the repository's `public/.htaccess`. Admin requests and requests for files that do not exist go to `public/index.php`. For public pages, that lightweight resolver follows the pointer and serves pre-generated release files; it never re-renders Markdown. Real files such as `assets/admin.css|admin.js|fonts/*.woff2` are served directly by Apache, while `.env` and internal project paths are explicitly denied. AI crawler visits may be written to anonymized observability records, so "static-first" does not mean a public request can never touch PHP or MySQL.

After deploying, check:

```bash
curl -fsS https://your-domain.example/ >/dev/null
curl -fsS https://your-domain.example/sitemap.xml >/dev/null
curl -fsS https://your-domain.example/rss.xml >/dev/null
curl -fsS https://your-domain.example/admin/login >/dev/null
test "$(curl -sS -o /dev/null -w '%{http_code}' https://your-domain.example/not-a-real-page)" = 404
```

Also confirm that a canonical article URL with a trailing slash returns 200, that URLs without the trailing slash behave according to your linking policy, and that HTML, CSS, feeds, and the search index all come from the same release. Do not judge whether the static build is live by whether a request reaches `index.php`: a standard pointer deployment may legitimately serve pre-generated files through PHP.

301 redirects for historical slugs (`previous_slugs`) come from an `.htaccess` written at the root of each release tree, with a meta-refresh page kept as a fallback. To check:

```bash
curl -sI https://your-domain.example/articles/<old-slug>/ | grep -i '^HTTP\|^location'
```

The `.htaccess` inside the release directory depends on the host's `AllowOverride` for that path (usually enabled together with `public/.htaccess`). If the host disallows it and the 301 does not take effect, the meta-refresh page still redirects, so correctness is unaffected.

## 6. Cron queue

In the hosting panel, add a cron job that runs every minute, using the absolute path to the PHP CLI:

```cron
* * * * * /usr/local/bin/php /home/account/holymd/cron/holymd.php >> /home/account/holymd/content/cron.log 2>&1
```

The cron script holds a non-blocking file lock and claims one job per run. Transient GEO errors are retried according to the job policy; permanent authentication, configuration, or response errors are not retried endlessly against a paid API. After setting it up, publish a test draft from the admin and confirm that `jobs` and `builds` move from queued/running to succeeded, and that the GEO dashboard shows a new score for the published snapshot. Failure history stays on the Jobs page for auditing; do not delete it just because the queue has recovered.

If citation probes are configured, run them weekly. Each question is one paid API call, capped by `HOLYMD_PROBE_MAX_PER_RUN`:

```cron
0 3 * * 1 /usr/local/bin/php /home/account/holymd/bin/holymd-probe.php >> /home/account/holymd/content/probe.log 2>&1
```

Hosts without cron can use the probe button on the GEO dashboard instead; it asks two questions per click so the request stays short.

## 7. Releases and rollback

Back up before deploying code: `php bin/holymd-backup.php` (see the backup and restore guide). Recommended order: pause cron during a maintenance window, upload the new code, run `composer install --no-dev --classmap-authoritative`, the migrations, the full test suite or package tests, the dry run, and the check, then resume cron. When rolling back code, remember the database is only forward-compatible; never drop migrated columns.

To roll back the static site, atomically rewrite the `public/.holymd-current` pointer file to a verified older release in `public/..holymd-current-releases/` (write a temporary file and `mv` it into place; never delete the current pointer first). Then rerun the HTTP checks.

## 8. nginx rewrites and flat deployments (hosts with a fixed DocumentRoot)

Some shared hosts (for example, Alibaba Cloud Wanwang virtual hosts) fix the DocumentRoot at `htdocs/`, run only nginx, and ignore `.htaccess`. Deploy as follows:

1. Place the project files directly in `htdocs/` (project root = docroot), and move the contents of `public/` (index.php, .htaccess, assets/) up into `htdocs/`. When `public/index.php` finds `.env` in its own directory, it computes the project root, pointer, and asset paths for this flat layout; standard deployments (DocumentRoot pointing at `public/`) are unaffected.
2. In `.env`, set `HOLYMD_BASE_PATH` (for a subdirectory install such as `/holymd`; leave it empty at the root) and `HOLYMD_SYNC_PUBLISH="1"`. Hosts without `exec`/`proc_open` cannot run the cron worker, so publishing and GEO reviews run synchronously inside the request.
3. Add these nginx rules in the panel's rewrite settings:

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

4. These hosts usually disable `exec`, `proc_open`, `putenv`, and `symlink`. HolyMD supports them with an in-memory environment overlay, OpenSSL AES-256-GCM credential encryption, a plain pointer file, and the optional synchronous queue. However, `holymd-check.php` still lists `ext-sodium` as a baseline extension, so the check fails if the host lacks it. Enable it in the hosting panel rather than bypassing the check.
5. Without SSH, run the database migration and administrator creation through a one-off web script (`Migrator` + `AccountCommands`) and delete it immediately afterwards. The initial release can also be built locally, uploaded, and activated by rewriting the pointer.

Checks are the same as §5. Also confirm that `.env`, `/content/`, and `/src/` return 403, and that sign-in, publish preflight, explicit confirmation, synchronous publishing, withdrawal, and deletion all work end to end. Recommendations in the publish preflight do not guarantee indexing, ranking, or AI citation.
