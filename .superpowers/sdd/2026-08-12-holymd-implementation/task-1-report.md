# Task 1 Implementation Report — PHP Runtime and Operational Schema

## Status

Completed and committed as `733b552` (`chore: bootstrap HolyMD runtime`), with review fixes committed as documented below.

## Files changed

- `.env.example` — MySQL operational database configuration template.
- `composer.json` and `composer.lock` — PHP 8.4 project setup, PHP-DI, PHPUnit 12, and PSR-4 autoloading.
- `public/index.php` — administration-only PHP entrypoint. Non-admin requests return 404 so future static public output can be served directly by the web server.
- `src/Bootstrap.php` — builds the DI container with settings, database connection, and PDO registrations.
- `src/Config/Settings.php` — loads `.env` without overriding real process environment variables and validates the DSN.
- `src/Database/Connection.php` — creates a PDO connection with exception errors, associative fetches, and native prepared statements.
- `database/schema.sql` — idempotent MySQL/InnoDB operational-state tables: `articles`, `article_versions`, `geo_reviews`, `geo_proposals`, `builds`, `jobs`, `admin_users`, and `audit_events`.
- `tests/BootstrapTest.php` — confirms container boot/PDO exception mode and verifies the required schema table set contains no article-body column.

Review follow-up:

- `composer.json` now exposes `composer test` as `phpunit tests`, which works without a `phpunit.xml` file.
- `tests/BootstrapTest.php` restores pre-existing `HOLYMD_*` environment values in `tearDown()` instead of unconditionally unsetting them.

## Verification

1. Red phase: `vendor/bin/phpunit tests/BootstrapTest.php`
   - Result: expected failure before Composer setup (`vendor/bin/phpunit` did not exist).
2. `composer install --no-interaction --prefer-dist`
   - Result: passed; generated `composer.lock` and installed development dependencies locally.
3. `vendor/bin/phpunit tests/BootstrapTest.php`
   - Result: passed — 2 tests, 5 assertions.
4. `composer validate --strict`
   - Result: passed.
5. `find src public tests -name '*.php' -print0 | xargs -0 -n1 php -l`
   - Result: passed; all five PHP files have no syntax errors.
6. `git diff --cached --check`
   - Result: passed before the implementation commit.
7. `composer test`
   - Result: passed — 2 tests, 5 assertions.
8. `vendor/bin/phpunit tests/BootstrapTest.php`
   - Result: passed after review fixes — 2 tests, 5 assertions.
9. `git diff --check`
   - Result: passed after review fixes.

## Constraint checks

- No schema column is named `body` or `article_body`; article content remains a filesystem concern.
- The public entrypoint does not bootstrap PHP for non-`/admin` paths, preserving the static-public-read architecture.
- The schema stores only operational state, checksums, snapshot/manifest paths, proposal metadata, jobs, and audit records.

## Concerns

- There is no local MySQL server configured in this checkout, so the idempotent MySQL DDL was reviewed statically rather than executed against a server. The PDO runtime is covered with `sqlite::memory:` as a test-only no-server DSN; production `.env.example` specifies MySQL with `utf8mb4`.
- Composer’s local `vendor/` directory is intentionally untracked; install dependencies with `composer install` after checkout.
