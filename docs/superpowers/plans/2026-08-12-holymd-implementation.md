# HolyMD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a small single-author PHP 8.4/MySQL Markdown blog manager that writes static public output and uses AI only for reviewed GEO metadata suggestions.

**Architecture:** Markdown files under `content/articles/` are the only article-body source of truth. A PHP 8.4 admin application reads and writes Markdown, stores operational indexes and review state in MySQL, and invokes an incremental static builder that renders a temporary public tree before an atomic switch. AI requests analyze saved Markdown and may propose front matter or GEO findings, but never mutate article body text or publish automatically.

**Tech Stack:** PHP 8.4; MySQL; Composer; PHPUnit; a maintained CommonMark parser with YAML front matter support; PDO; server-rendered HTML/CSS/vanilla JavaScript; Cron for queued jobs; configurable OpenAI-compatible HTTP API.

## Global Constraints

- Public article reads must not invoke PHP or MySQL.
- Markdown files are the sole source of truth for article content; MySQL must not store article bodies.
- AI does not draft, continue, rewrite, or replace article prose.
- Accepted AI proposals may update only Markdown front matter and related metadata.
- AI never publishes automatically; publishing requires an explicit administrator action.
- The target host is ordinary shared hosting with PHP 8.4, local MySQL, filesystem media, and Cron; no daemon is required.
- A failed build must leave the previous public static tree live.
- All generated structured data must reflect only real configured or article data.

---

### Task 1: Bootstrap the PHP application and operational database

**Files:**
- Create: `composer.json`
- Create: `public/index.php`
- Create: `src/Bootstrap.php`
- Create: `src/Config/Settings.php`
- Create: `src/Database/Connection.php`
- Create: `database/schema.sql`
- Create: `.env.example`
- Create: `tests/BootstrapTest.php`

**Interfaces:**
- `Bootstrap::createContainer(): Container` loads environment settings and PDO.
- `Connection::pdo(): PDO` returns a configured MySQL connection with exceptions enabled.
- Schema tables include `articles`, `article_versions`, `geo_reviews`, `geo_proposals`, `builds`, `jobs`, `admin_users`, and `audit_events`; none contain an article body column.

- [ ] Write a failing test that boots with a test DSN and asserts the PDO error mode and required table names.
- [ ] Run `vendor/bin/phpunit tests/BootstrapTest.php`; expect failure because the application classes and Composer setup do not exist.
- [ ] Add Composer dependencies, PSR-4 autoloading, environment parsing, PDO bootstrap, and the complete idempotent schema.
- [ ] Run `composer install` and `vendor/bin/phpunit tests/BootstrapTest.php`; expect PASS.
- [ ] Commit with `git add composer.json public src database .env.example tests && git commit -m "chore: bootstrap HolyMD runtime"`.

### Task 2: Implement Markdown files, front matter, and static rendering

**Files:**
- Create: `src/Content/ArticleDocument.php`
- Create: `src/Content/ArticleRepository.php`
- Create: `src/Content/FrontMatter.php`
- Create: `src/Render/StaticBuilder.php`
- Create: `src/Render/TemplateRenderer.php`
- Create: `templates/public/article.php`
- Create: `templates/public/index.php`
- Create: `templates/public/topic.php`
- Create: `templates/public/about.php`
- Create: `tests/Content/ArticleDocumentTest.php`
- Create: `tests/Render/StaticBuilderTest.php`

**Interfaces:**
- `ArticleRepository::read(string $path): ArticleDocument` and `write(ArticleDocument $document): void` operate on `content/articles/<slug>.md`.
- `StaticBuilder::build(BuildInput $input, string $temporaryRoot): BuildManifest` renders articles and discovery files into the supplied temporary root.
- `ArticleDocument` exposes `slug`, `title`, `bodyMarkdown`, `frontMatter`, and `sourcePath`; serialization preserves body text byte-for-byte unless front matter is explicitly changed.

- [ ] Write tests for YAML parsing, required title/slug/date fields, safe path rejection, and body-preserving front-matter updates.
- [ ] Run the focused PHPUnit tests; expect failures for missing parser/repository/builder classes.
- [ ] Implement parser adapters, path-safe file access, templates, semantic HTML, canonical/meta tags, truthful Article/Person/WebSite/BreadcrumbList JSON-LD, RSS, JSON Feed, sitemap, robots, and optional `llms.txt` generation.
- [ ] Add tests that build two articles and assert `/articles/slug/index.html`, feeds, sitemap, and JSON-LD content.
- [ ] Run focused tests and `php -l` over `src/` and `templates/`; expect PASS.
- [ ] Commit with `git add src/Content src/Render templates tests && git commit -m "feat: render markdown as static public site"`.

### Task 3: Add admin article editing and publish-only versions

**Files:**
- Create: `src/Http/Router.php`
- Create: `src/Http/Csrf.php`
- Create: `src/Auth/AdminGuard.php`
- Create: `src/Admin/ArticleController.php`
- Create: `src/Admin/VersionService.php`
- Create: `templates/admin/layout.php`
- Create: `templates/admin/articles/index.php`
- Create: `templates/admin/articles/edit.php`
- Create: `public/assets/admin.css`
- Create: `public/assets/admin.js`
- Create: `tests/Admin/ArticleControllerTest.php`

**Interfaces:**
- `ArticleController::saveDraft(ServerRequest $request): Response` writes Markdown without creating a content version; successful publication records the restorable version.
- `VersionService::snapshot(ArticleDocument $document): VersionId` and `restore(VersionId $id): ArticleDocument` provide rollback.
- CSRF and administrator authorization are mandatory for save, restore, publish, withdraw, and settings actions.

- [ ] Write request tests for unauthenticated rejection, CSRF rejection, draft save without version creation, publish-only version advancement, and body round-trip.
- [ ] Run the focused tests; expect failures for controllers, guard, and templates.
- [ ] Implement server-rendered three-column writing studio with Markdown textarea, rendered preview, autosave endpoint, version list, and explicit Publish button; do not add AI prose actions.
- [ ] Run focused tests plus PHP syntax checks; expect PASS.
- [ ] Commit with `git add src/Http src/Auth src/Admin templates/admin public/assets tests && git commit -m "feat: add markdown writing studio"`.

### Task 4: Implement GEO-only AI review and proposal acceptance

**Files:**
- Create: `src/Geo/GeoReviewService.php`
- Create: `src/Geo/GeoPrompt.php`
- Create: `src/Geo/AiClient.php`
- Create: `src/Geo/ProposalAcceptance.php`
- Create: `templates/admin/geo-panel.php`
- Create: `tests/Geo/GeoReviewServiceTest.php`
- Create: `tests/Geo/ProposalAcceptanceTest.php`

**Interfaces:**
- `GeoReviewService::review(ArticleDocument $document): GeoReview` returns typed proposals and findings for summary, metadata, entities, FAQ candidates, sources, hierarchy, alt text, internal links, and structured data.
- `AiClient::analyze(string $systemPrompt, string $articleMarkdown): AiResponse` sends the saved article for analysis only.
- `ProposalAcceptance::accept(GeoProposalId $id): ArticleDocument` may update front matter fields but must assert an unchanged body hash.

- [ ] Write tests with a fake AI client proving prompts forbid prose generation and that malformed responses are rejected.
- [ ] Write an acceptance test that records the original body hash and fails if a proposal changes body Markdown.
- [ ] Implement typed JSON response validation, review persistence, diff display, per-proposal accept/reject/edit, retryable job records, and encrypted/configured API credentials.
- [ ] Run focused tests; expect PASS and explicit proof that accepted proposals update only front matter.
- [ ] Commit with `git add src/Geo templates/admin/geo-panel.php tests/Geo && git commit -m "feat: add geo-only ai review"`.

### Task 5: Add atomic publishing, redirects, queue worker, and Cron entrypoint

**Files:**
- Create: `src/Publish/PublishService.php`
- Create: `src/Publish/AtomicPublicTree.php`
- Create: `src/Publish/ValidationReport.php`
- Create: `bin/holymd-worker.php`
- Create: `bin/holymd-build.php`
- Create: `cron/holymd.php`
- Create: `tests/Publish/PublishServiceTest.php`
- Create: `tests/Publish/AtomicPublicTreeTest.php`

**Interfaces:**
- `PublishService::publish(ArticleId $id): PublishResult` validates, builds to a temporary directory, atomically swaps the public tree, updates manifest/index state, and records an audit event.
- `AtomicPublicTree::swap(string $temporaryRoot, string $liveRoot): void` guarantees the old tree remains available when build or validation fails.
- Worker entrypoints claim MySQL jobs with locking, run GEO/build work, record failures, and support safe retry.

- [ ] Write tests that seed an existing live tree, force a renderer failure, and assert the live tree and manifest remain unchanged.
- [ ] Write tests for slug changes producing redirects and withdrawn articles disappearing from feeds and sitemap.
- [ ] Implement validation, temp-tree build, atomic rename/symlink strategy supported by shared hosting, redirect generation, job claiming, and Cron-safe locking.
- [ ] Run focused tests and execute `php bin/holymd-build.php --dry-run`; expect PASS and a readable validation report.
- [ ] Commit with `git add src/Publish bin cron tests/Publish && git commit -m "feat: publish static site atomically"`.

### Task 6: Harden deployment, documentation, and end-to-end verification

**Files:**
- Create: `README.md`
- Create: `docs/operations/shared-hosting.md`
- Create: `docs/operations/backup-and-restore.md`
- Create: `tests/EndToEnd/PublishFlowTest.php`
- Create: `tests/EndToEnd/GeoBoundaryTest.php`
- Create: `public/.htaccess`
- Modify: `.env.example`

**Interfaces:**
- Deployment documentation defines PHP 8.4, MySQL, Composer install, writable content/media/build paths, Cron command, and static web-root configuration.
- End-to-end tests cover Markdown draft → GEO review → accepted metadata → static publish → generated route/feed/sitemap.

- [ ] Write end-to-end tests covering the complete publish flow and asserting AI cannot alter body content.
- [ ] Run the full PHPUnit suite and syntax checks; expect failures until deployment configuration and tests are complete.
- [ ] Add safe Apache rules for the admin entrypoint and direct static serving, document backups of `content/`, media, database operational state, and generated public output.
- [ ] Run `composer validate`, `vendor/bin/phpunit`, `find src public bin cron -name '*.php' -print0 | xargs -0 -n1 php -l`, and a clean dry-run build; expect PASS.
- [ ] Commit with `git add README.md docs/operations public/.htaccess .env.example tests/EndToEnd && git commit -m "docs: verify shared hosting deployment"`.

## Self-review checklist

- Spec coverage: static source/output, MySQL operational state, GEO-only AI boundary, three-column editor, atomic rollback, Cron jobs, feeds/discovery, semantic metadata, and verification are each assigned to a task.
- Placeholder scan: no TODO/TBD steps or unspecified error-handling instructions remain; deferred library/provider choices are resolved during Task 1 setup through explicit dependency/configuration decisions.
- Interface consistency: `ArticleDocument`, `StaticBuilder`, `GeoReviewService`, `ProposalAcceptance`, and `PublishService` names are used consistently across task boundaries.
