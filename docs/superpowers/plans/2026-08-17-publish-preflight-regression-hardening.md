# Publish Preflight and Regression Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** completed, merged by PR #3, and archived. No checkbox in this file represents outstanding work.

**Closeout evidence:** commits `6054afe`, `57cd302`, `26b1f3f`, `e39f0d1`, `2a4c29f`, and `c5232f6`; final gate 284 tests / 1184 assertions; MySQL migration idempotence; real queued publication and GEO score row; desktop/375 px browser regression; UTF-8 artifact audit; temporary-data cleanup.

**Goal:** Repair the five verified regressions and add a server-enforced, checksum-bound publish preflight without weakening immutable publication.

**Architecture:** Normalize time at the PDO boundary and convert it only at administrator presentation boundaries. Extend `PublishService` with a read-only preflight that validates and renders the exact candidate publication set, returning an immutable result consumed by `ArticleController`; keep actual publication on the existing snapshot-bound path. Repair responsive behavior in the existing templates/assets, then verify the queue and generated tree end to end.

**Tech Stack:** PHP 8.4+, PDO MySQL, PHPUnit 12, League CommonMark, server-rendered PHP templates, vanilla JavaScript, CSS, Cron worker.

## Global Constraints

- Markdown remains the content source of truth.
- Publish and GEO jobs remain bound to immutable input snapshots.
- `published_version` advances only after a successful atomic public-tree switch.
- Database values and machine outputs use UTC; admin display uses `HOLYMD_TIMEZONE`, default `Asia/Singapore`.
- AI/GEO warnings remain advisory and make no ranking, indexing, or citation guarantees.
- No administrator credential or database secret may enter logs, fixtures, generated pages, or commits.

---

### Task 1: UTC Database Boundary and Administrator Timezone

**Files:**
- Create: `src/Config/SiteTimezone.php`
- Create: `src/Admin/AdminTimeFormatter.php`
- Create: `database/migrations/20260817_normalize_legacy_timestamps.sql`
- Modify: `src/Database/Connection.php`
- Modify: `src/Bootstrap.php`
- Modify: `src/Admin/GeoDashboardController.php`
- Modify: `src/Admin/JobStatusRepository.php`
- Modify: `templates/admin/geo-dashboard.php`
- Modify: `templates/admin/jobs.php`
- Modify: `templates/admin/settings.php`
- Test: `tests/Config/SiteTimezoneTest.php`
- Test: `tests/Database/ConnectionTest.php`
- Test: `tests/Admin/AdminTimeFormatterTest.php`
- Test: `tests/Admin/GeoDashboardControllerTest.php`
- Test: `tests/Admin/JobsControllerTest.php`

**Interfaces:**
- Produces: `SiteTimezone::fromEnvironment(): SiteTimezone`, `SiteTimezone::identifier(): string`, `AdminTimeFormatter::format(?string $utc, string $format = 'Y-m-d H:i'): string`.
- Produces: every MySQL PDO session has `@@session.time_zone = '+00:00'` and session variable `@holymd_legacy_offset_seconds` captured before normalization.

- [x] **Step 1: Write failing timezone and formatter tests**

```php
public function test_defaults_to_singapore_and_rejects_invalid_identifiers(): void
{
    $timezone = SiteTimezone::fromValue(null);
    self::assertSame('Asia/Singapore', $timezone->identifier());
    $this->expectException(InvalidArgumentException::class);
    SiteTimezone::fromValue('Mars/Olympus');
}

public function test_formats_stored_utc_in_the_site_timezone(): void
{
    $formatter = new AdminTimeFormatter(SiteTimezone::fromValue('Asia/Singapore'));
    self::assertSame('2026-08-17 20:00', $formatter->format('2026-08-17 12:00:00'));
}
```

- [x] **Step 2: Run focused tests and verify RED**

Run: `vendor/bin/phpunit tests/Config/SiteTimezoneTest.php tests/Admin/AdminTimeFormatterTest.php`

Expected: FAIL because the production classes do not exist.

- [x] **Step 3: Implement validated timezone and formatter**

```php
final readonly class SiteTimezone
{
    private function __construct(private DateTimeZone $timezone) {}
    public static function fromValue(?string $value): self
    {
        $identifier = trim((string) $value) ?: 'Asia/Singapore';
        try { return new self(new DateTimeZone($identifier)); }
        catch (Throwable) { throw new InvalidArgumentException('HOLYMD_TIMEZONE must be a valid PHP timezone identifier.'); }
    }
    public function identifier(): string { return $this->timezone->getName(); }
    public function zone(): DateTimeZone { return $this->timezone; }
}
```

- [x] **Step 4: Write and verify a failing real-MySQL connection test**

Assert that a PDO returned by `Connection::pdo()` reports `+00:00`, while `@holymd_legacy_offset_seconds` equals the pre-normalization server offset on the first connection.

Run: `vendor/bin/phpunit tests/Database/ConnectionTest.php`

Expected: FAIL because the current session remains `SYSTEM`.

- [x] **Step 5: Normalize PDO sessions and add the idempotent migration**

Immediately after PDO construction, execute:

```sql
SET @holymd_legacy_offset_seconds = TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW());
SET time_zone = '+00:00';
```

The migration subtracts `@holymd_legacy_offset_seconds` only from default-generated `created_at`/`updated_at` columns in the pre-existing operational tables. It must not shift explicit UTC completion, lock, decision, availability, or AI-bot visit columns.

- [x] **Step 6: Inject the formatter into administrator data/view boundaries**

Return both raw UTC values for calculations and a `created_at_display` value for templates. Add `HOLYMD_TIMEZONE` to the settings page without exposing unrelated environment values.

- [x] **Step 7: Run focused and full tests, then commit**

Run: `vendor/bin/phpunit tests/Config tests/Database tests/Admin/GeoDashboardControllerTest.php tests/Admin/JobsControllerTest.php`

Run: `composer test`

Commit: `git commit -m "fix: normalize operational timestamps to UTC"`

---

### Task 2: Asynchronous GEO Score Recording Parity

**Files:**
- Modify: `bin/holymd-build.php`
- Modify: `src/Publish/PublishService.php`
- Modify: `tests/Publish/PublishServiceTest.php`
- Modify: `tests/Queue/WorkerTest.php`

**Interfaces:**
- Consumes: UTC PDO from Task 1.
- Produces: one `geo_scores` row with trigger `publish` after a successful queued publish; no row for failed publish or withdrawal.

- [x] **Step 1: Write a failing behavior test using a real SQLite score table**

Construct `PublishService` with PDO and `GeoScoreCalculator`, publish a real article, and assert the row's slug and score. Add a failure-builder case and a withdrawal case asserting zero new rows.

- [x] **Step 2: Run the test and verify the worker composition path is still uncovered**

Run: `vendor/bin/phpunit tests/Publish/PublishServiceTest.php --filter geo_score`

Expected: the direct behavior passes only when dependencies are present; the new CLI/worker regression fails because `bin/holymd-build.php` omits them.

- [x] **Step 3: Inject standard PDO and calculator in the CLI composition root**

```php
$pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
$service = new PublishService(
    $articles, new StaticBuilder(), new AtomicPublicTree(), $liveRoot,
    $publication, $auditRoot, null, $lockPath, $versions, $pages,
    $pdo, new GeoScoreCalculator(),
);
```

Keep dry-run database-free and keep score insertion after public switch/version confirmation.

- [x] **Step 4: Make score-recording failure observable without reversing publication**

Record an audit event with action `geo-score` and status `failed` when insertion fails; do not throw after the public tree has switched.

- [x] **Step 5: Run focused tests and commit**

Run: `vendor/bin/phpunit tests/Publish/PublishServiceTest.php tests/Queue/WorkerTest.php`

Commit: `git commit -m "fix: record GEO scores for queued publishes"`

---

### Task 3: Responsive Header, Editor Actions, and Heading Semantics

**Files:**
- Modify: `templates/public/_header.php`
- Modify: `templates/public/_footer.php`
- Modify: `templates/public/site.css`
- Modify: `templates/admin/articles/edit.php`
- Modify: `public/assets/admin.css`
- Modify: `tests/Render/StaticBuilderTest.php`
- Modify: `tests/Admin/ArticleControllerTest.php`

**Interfaces:**
- Produces: `[data-theme-cycle]` compact mobile theme control while retaining desktop `[data-theme-set]` controls.
- Produces: exactly one page-level editor `h1`, visually hidden; publication actions wrap below 650 px.

- [x] **Step 1: Add failing rendered-output tests**

Assert generated pages contain a labelled theme-cycle button and editor HTML contains `class="sr-only"` `h1` with the escaped article title. Assert the responsive stylesheet exposes compact/wrapping behavior through rendered artifacts, not source-only symbol checks.

- [x] **Step 2: Run focused tests and verify RED**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php tests/Admin/ArticleControllerTest.php`

Expected: FAIL because no mobile cycle control or editor `h1` exists.

- [x] **Step 3: Implement the mobile controls and accessible heading**

The cycle order is `auto -> light -> dark -> auto`. The button label and icon update after every change. Desktop controls keep their existing pressed-state behavior. Add wrapping and width constraints at existing mobile breakpoints.

- [x] **Step 4: Run focused tests and JavaScript syntax validation**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php tests/Admin/ArticleControllerTest.php`

Run: `node --check public/assets/admin.js`

- [x] **Step 5: Commit**

Commit: `git commit -m "fix: keep publishing controls usable on mobile"`

---

### Task 4: Read-Only Publish Preflight Domain Contract

**Files:**
- Create: `src/Publish/PublishPreflightResult.php`
- Modify: `src/Publish/PublishService.php`
- Modify: `tests/Publish/PublishServiceTest.php`

**Interfaces:**
- Produces: `PublishService::preflight(ArticleDocument $candidate): PublishPreflightResult`.
- Produces result properties: `checksum`, `currentScore`, `candidateScore`, `changes`, `blockers`, `warnings`, plus `canPublish(): bool` and `requiresAcknowledgement(): bool`.

- [x] **Step 1: Write a failing clean/warning preflight test**

Use literal expectations: candidate checksum is SHA-256 of serialized candidate; a changed body reports `body`; a 50-character summary removes the summary warning; missing links and sources remain warnings. Assert repository serialization, version index, and public tree are byte-for-byte unchanged.

- [x] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Publish/PublishServiceTest.php --filter preflight`

Expected: FAIL because `PublishService::preflight()` is undefined.

- [x] **Step 3: Implement the immutable result and minimal candidate-set validation**

Preflight restores existing published snapshots, substitutes the candidate as published in memory, runs metadata/site/route checks, builds into a temporary directory, calculates scores, and always removes the temporary directory. It never calls repository write, version capture/stage/confirm, public-tree swap, audit publish, or queue operations.

- [x] **Step 4: Add a failing blocker test**

Supply invalid structured data and a colliding previous slug. Assert both appear as blockers and no generated/public state changes.

- [x] **Step 5: Implement blocker collection without throwing away other findings**

Convert validation/build exceptions to field/build blocker messages in the result. Programming/runtime failures unrelated to validation continue to throw.

- [x] **Step 6: Add and pass published-score comparison tests**

Restore the exact `published_version`, calculate its score, and warn when the candidate total decreases. A draft without a published pointer has `currentScore === null`.

- [x] **Step 7: Run focused and full tests, then commit**

Run: `vendor/bin/phpunit tests/Publish/PublishServiceTest.php`

Run: `composer test`

Commit: `git commit -m "feat: add immutable publish preflight"`

---

### Task 5: Server-Enforced Preflight and Confirmation UI

**Files:**
- Modify: `src/Admin/ArticleController.php`
- Create: `templates/admin/articles/preflight.php`
- Modify: `src/Http/Router.php`
- Modify: `templates/admin/articles/edit.php`
- Modify: `public/assets/admin.css`
- Modify: `public/assets/admin.js`
- Modify: `tests/Admin/ArticleControllerTest.php`

**Interfaces:**
- Consumes: `PublishPreflightResult` from Task 4.
- Produces: POST `/admin/articles/<slug>/preflight` and checksum-bound `preflight_acknowledgement` enforced again by POST `/publish`.

- [x] **Step 1: Write failing controller tests for warning and blocker flows**

Assert preflight does not mutate the article. Warning output includes current/candidate score and changed fields. Blocker output has status 422 and no confirm control.

- [x] **Step 2: Run and verify RED**

Run: `vendor/bin/phpunit tests/Admin/ArticleControllerTest.php --filter preflight`

Expected: FAIL because the route/controller response does not exist.

- [x] **Step 3: Implement server-rendered preflight**

Render escaped findings and a confirmation form containing all submitted article fields, `expected_checksum`, CSRF token, and `preflight_acknowledgement = result.checksum`. Large Markdown remains a hidden textarea rather than entering a query string or session log.

- [x] **Step 4: Write failing acknowledgement-binding tests**

Assert publish rejects a missing acknowledgement when warnings exist. Assert changing one body byte after preflight invalidates the old acknowledgement. Assert a valid acknowledgement proceeds through the existing immutable snapshot queue path.

- [x] **Step 5: Enforce preflight in publish and preserve CAS ordering**

Build the candidate, compare the current source checksum, rerun preflight, validate acknowledgement, then call `writeIfUnchanged`, capture `publish-inputs`, and enqueue/publish as before.

- [x] **Step 6: Enhance the editor submission path**

After autosave flush, submit to the preflight endpoint. Keep plain form submission valid without JavaScript. Provide clear Return to Editor and Confirm Publication actions.

- [x] **Step 7: Run focused/full checks and commit**

Run: `vendor/bin/phpunit tests/Admin/ArticleControllerTest.php tests/EndToEnd/PublishFlowTest.php tests/EndToEnd/GeoBoundaryTest.php`

Run: `composer test`

Run: `node --check public/assets/admin.js`

Commit: `git commit -m "feat: enforce publish preflight confirmation"`

---

### Task 6: Generated Artifact Integrity and Full Regression

**Files:**
- Modify: `tests/Render/StaticBuilderTest.php`
- Modify: `README.md`
- Modify: `.env.example`

**Interfaces:**
- Produces: regression assertions for valid UTF-8 without U+FFFD in HTML, JSON Feed, search index, and `llms` artifacts.

- [x] **Step 1: Write a failing artifact-integrity test using multilingual content**

The fixture contains Chinese, punctuation, emoji, and an image. Assert `mb_check_encoding`, absence of `"\u{FFFD}"`, successful `json_decode(..., JSON_THROW_ON_ERROR)`, and successful XML parsing for every generated discovery artifact.

- [x] **Step 2: Run and verify the test proves the stale artifact failure**

Run the assertion against the old generated fixture first and observe U+FFFD; then run it against a fresh builder output. If the fresh build already passes, retain the regression test and treat controlled rebuild as the production repair rather than changing the renderer.

- [x] **Step 3: Document timezone and preflight behavior**

Add `HOLYMD_TIMEZONE=Asia/Singapore` to `.env.example` and describe UTC storage, warning acknowledgement, queued publish scoring, and no-GEO-guarantee semantics in README.

- [x] **Step 4: Run the complete automated gate**

Run: `composer validate --strict`

Run: `composer test`

Run: `find src tests bin public templates -name '*.php' -print0 | xargs -0 -n1 php -l`

Run: `node --check public/assets/admin.js`

Run: `php bin/holymd-check.php`

Run: `php bin/holymd-build.php --dry-run`

Run: `git diff --check`

- [x] **Step 5: Exercise MySQL, Worker, and browser flows**

Migrate the local database; log in without recording credentials; use a temporary article/image to exercise edit, preflight, acknowledgement, queue, worker, score history, public rendering, image viewer, 375 px public header, and 375 px editor actions. Withdraw/delete the temporary article and media, drain its jobs, and rebuild the normal public tree.

- [x] **Step 6: Audit cleanup and commit**

Assert no temporary slug/media, queued/running job, replacement character, secret, or unrelated working-tree change remains.

Commit: `git commit -m "test: complete publish preflight regression coverage"`

## Archive Note

All six tasks are complete. Deferred items in the companion design are optional future product candidates, not hidden acceptance debt. Any new capability should start in a new dated design/plan and preserve the immutable snapshot, checksum acknowledgement, UTC, and atomic-release contracts recorded here.
