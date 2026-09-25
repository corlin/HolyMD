# HolyMD Backup and Restore Guide

English | [简体中文](backup-and-restore.zh-CN.md)

A complete backup must cover both the filesystem and MySQL. Article Markdown is the only source of the text, so a database backup alone cannot restore the site.

## Backup

Run backups while no publish job is running. Confirm there are no queued or running jobs on the admin Jobs page or with `php bin/holymd-admin.php jobs`, then pause cron:

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

### One-command backup

`php bin/holymd-backup.php` automates the steps above. It writes `content.tar.gz`, `holymd.sql` (the database dump), `env.copy`, and `SHA256SUMS` to `backups/<UTC timestamp>/` in the project root, with directory permissions 0700. It requires `tar`, `mysqldump`, and `sha256sum`. The database password is passed through the `MYSQL_PWD` environment variable, so it never appears in process arguments. The output directory is outside `content/`, so it is never archived recursively. The manual commands remain as a fallback.

Copy backups to encrypted storage off the host, set a retention period, and regularly test a restore in an isolated environment. `.env` contains secrets and must be encrypted with restricted access. The generated static site can be rebuilt from Markdown, so there is usually no need to keep every release directory long-term.

## Restore

1. Stop cron and prevent administrators from publishing.
2. Deploy HolyMD code compatible with the backup and run `composer install --no-dev --classmap-authoritative`.
3. Enter the copied backup directory and verify it: `cd /path/to/restored-backup && sha256sum -c SHA256SUMS`. The manifest uses relative file names, so it only verifies the current restored copy.
4. Restore `content/` and the protected `.env`, and confirm ownership and minimum write permissions.
5. Create an empty database and import the dump: `mysql -h DB_HOST -u DB_USER -p DB_NAME < holymd.sql`.
6. Run `php bin/holymd-migrate.php` to bring the restored database up to the current schema.
7. Run `php bin/holymd-build.php --dry-run`, `php bin/holymd-prepare-release.php`, and `php bin/holymd-check.php`.
8. Run `php bin/holymd-build.php --rebuild` to rebuild the full public tree from the confirmed `published_version` snapshots. Do not restore by editing articles or faking a publish.
9. Check the home page, articles, RSS, Atom, JSON Feed, search index, sitemap, `llms.txt`, 404 page, and admin sign-in before resuming cron.

## Restore checklist

- The number of files in `content/articles` and the number of media files match the backup.
- Administrators can sign in and version history is visible.
- The Markdown body hashes of published articles match the backup.
- GEO suggestion states are visible; accepting a metadata suggestion does not change a body hash.
- Database sessions use UTC and the admin displays times in `HOLYMD_TIMEZONE`; do not manually shift explicit UTC fields after a restore.
- Public HTML has correct canonical URLs, language, Person/Article/Breadcrumb JSON-LD, sources, RSS, and sitemap.
- Generated HTML, JSON, XML, and `llms` text files are valid UTF-8 with no Unicode replacement characters.
- No `.env`, database dump, backup, or `content/` path is publicly reachable.
