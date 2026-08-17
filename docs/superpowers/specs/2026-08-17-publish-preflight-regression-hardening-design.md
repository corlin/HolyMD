# HolyMD Publish Preflight and Regression Hardening Design

**Status:** user-approved design, awaiting written-spec review

**Selected approach:** repair the verified regressions and add a bounded publish-preflight MVP

**Target environment:** PHP 8.4, MySQL, Cron-driven queue worker, and an atomically switched static public tree

## 1. Objective

Restore trust in the current publishing workflow after several feature and refactoring passes, then add one controlled product capability: a server-enforced publish preflight that explains material changes and GEO risks before publication.

The work must preserve HolyMD's existing publication contract:

- Markdown remains the content source of truth.
- A publish or GEO job is bound to an immutable input snapshot.
- A restorable version and `published_version` advance only after the static build succeeds and becomes public.
- AI proposals never publish automatically and never rewrite article body text.

## 2. Scope

### Included

1. Record a GEO score after a successful asynchronous publish, with the same behavior as synchronous publishing.
2. Store operational timestamps in UTC and display administrator-facing timestamps in a configurable site timezone.
3. Normalize historical timestamps that were written using the previous MySQL session timezone, without changing fields that were already explicitly written in UTC.
4. Remove the verified 375 px overflow in the public header.
5. Keep every editor publication action visible and usable on narrow screens.
6. Add a page-level accessible heading to the existing-article editor without disturbing its visual hierarchy.
7. Add a server-enforced publish preflight with blockers, warnings, GEO score comparison, and a bounded change summary.
8. Rebuild and validate generated public artifacts, including UTF-8 integrity.

### Deferred

- Automatic internal-link or source generation.
- AI-authored prose or automatic acceptance of GEO proposals.
- A general workflow engine or redesigned publication dashboard.
- Full line-by-line Markdown diffing in the first preflight release.
- Multi-timezone accounts; HolyMD remains a single-administrator product.

## 3. Verified Failure Causes

### 3.1 Missing GEO score history

The HTTP synchronous publisher receives both PDO and `GeoScoreCalculator`. The CLI publisher invoked by the queue worker does not, so `PublishService::recordGeoScore()` returns without inserting a row. The fix is dependency parity at the composition root, not a second score-writing path in the worker.

### 3.2 Mixed timestamps

MySQL uses its `SYSTEM` timezone, currently UTC+08:00, for `CURRENT_TIMESTAMP` defaults. Queue and authentication code explicitly write UTC. This creates records in which a UTC completion time appears earlier than a locally generated creation time. Every application-created PDO session must become UTC before normal queries execute.

### 3.3 Narrow-screen overflow

The public header keeps search and a three-button theme switcher beside the brand at 375 px. The editor publication row also forbids wrapping. Both failures are layout constraints rather than routing or JavaScript defects.

### 3.4 Missing editor heading

The existing-article editor begins with navigation and form labels but has no page-level `h1`. A visually hidden descriptive heading supplies document structure without introducing a competing visual title.

## 4. Time Model

### Storage

- New PDO connections capture the legacy server offset for migration use, then set the MySQL session timezone to `+00:00`.
- Database defaults and explicit application writes consequently use the same UTC basis.
- Queue lease comparisons, retries, locks, and completion timestamps remain UTC.

### Historical normalization

A one-time migration adjusts only columns known to have been produced by `CURRENT_TIMESTAMP` or `ON UPDATE CURRENT_TIMESTAMP` under the former session timezone. Explicit UTC columns such as worker completion/lock fields and `AiBotDetector` visit timestamps are not shifted.

The captured pre-UTC session offset is used instead of hard-coding eight hours. The migration is idempotent through the existing `schema_migrations` mechanism. Fresh installations create no legacy rows before the migration and therefore need no correction.

### Presentation

- Add `HOLYMD_TIMEZONE`, defaulting to `Asia/Singapore`.
- Validate it against PHP's timezone identifiers; invalid configuration fails clearly rather than silently falling back.
- Administrator views convert stored UTC values at the display boundary.
- Machine-facing timestamps, feeds, manifests, audit files, and database values remain UTC or include an explicit offset.

## 5. Publish Preflight

### 5.1 Interaction

Selecting Publish or Update Public first requests a preflight for the exact candidate represented by the current form. The editor displays:

- current published GEO score, when a published snapshot exists;
- candidate GEO score and increase/decrease;
- changed content areas: title, date, body, summary, topics, and advanced GEO metadata;
- blocking errors;
- non-blocking recommendations.

The first release reports field-level and body-change summaries rather than a full text diff. The administrator may return to editing or explicitly confirm publication when only warnings remain.

### 5.2 Server authority

The browser is an enhancement, not the authority. The publish endpoint reruns preflight against the submitted candidate and current repository state.

- A blocker always rejects publication.
- Warnings require an explicit acknowledgement value.
- The acknowledgement is bound to the candidate checksum so a later edit cannot reuse it.
- The existing compare-and-swap checksum remains the final concurrent-edit guard.
- A non-JavaScript submission receives a server-rendered preflight result and can confirm through the same rules.

No preflight operation writes the article, creates a visible content version, queues work, or changes the public tree.

### 5.3 Blockers

- Invalid or incomplete required article metadata.
- Unsafe or malformed structured metadata.
- Duplicate/invalid slug or public route collision.
- Concurrent source change since the editor loaded.
- Static build validation failure for the candidate publication set.
- Invalid internal media reference or another existing hard publication invariant.

### 5.4 Warnings

- Candidate GEO score is lower than the currently published score.
- Missing or weak summary.
- Missing sources when the content presents external factual support.
- Missing internal links.
- Images without usable alternative text.
- Other existing GEO scorecard deficiencies that do not make the output invalid.

Warnings are advisory and must not be described as guarantees of ranking, indexing, or AI citation.

### 5.5 Publication continuation

After confirmation, the existing controller writes the candidate with compare-and-swap protection, captures an immutable `publish-inputs/` snapshot, and either enqueues a build or publishes synchronously. The queue job stays bound to that snapshot. A successful atomic release then confirms the published version and records the GEO score. Failures leave the former public tree and published pointer unchanged.

## 6. GEO Score Recording

- The CLI composition root constructs its database connection through the standard connection factory and injects PDO plus `GeoScoreCalculator` into `PublishService`.
- Score insertion remains inside the successful publish transaction/sequence already owned by `PublishService`.
- Withdraw and failed publish operations do not create score snapshots.
- Rebuild behavior remains explicit and does not fabricate a user publication event unless the implementation already treats it as one.
- Repeated processing of the same completed job must not create duplicate score rows; existing job-state guards remain authoritative, and a focused regression test covers the worker path.

## 7. Responsive and Accessibility Changes

### Public header

At the narrow breakpoint, replace the permanently expanded three-option theme switcher with one compact control that cycles through system, light, and dark modes while preserving the current stored preference and accessible label. Search remains available. Wider layouts keep the existing three-button presentation.

### Editor actions

The publication action group wraps within its container at narrow widths. Primary publish/update stays first; View Public and Withdraw remain reachable without horizontal scrolling. Touch targets and focus indication remain intact.

### Editor heading

Add one visually hidden `h1` describing the page as editing the current article. Preview Markdown headings remain subordinate and do not create a second page-level heading.

## 8. Generated Artifact Integrity

The `public/site` files containing replacement characters predate the current source and renderer output and are treated as stale generated artifacts, not as evidence of current Markdown corruption. A successful controlled rebuild replaces them.

Build verification adds assertions that generated HTML, JSON Feed, search index, and `llms` outputs are valid UTF-8 and contain no Unicode replacement character introduced by the pipeline. JSON and XML outputs must also parse successfully.

## 9. Error Handling and Observability

- Preflight errors identify the affected field or build invariant and retain the administrator's submitted content.
- Queue build failures continue to appear in the Jobs view without advancing publication state.
- Invalid timezone configuration produces a clear configuration error.
- Failure to insert a GEO score after the public switch must be observable and tested. It must not roll back an already completed atomic public switch; the operational result should report the secondary failure so it can be retried or repaired deliberately.
- No logs, pages, or test artifacts expose administrator credentials or database secrets.

## 10. Verification and Acceptance

### Automated

- Unit tests for timezone validation and UTC connection initialization.
- Migration test for representative mixed-time records and idempotence.
- Preflight tests for clean, warning, blocker, stale-checksum, changed-after-acknowledgement, and non-JavaScript flows.
- Publish service and real MySQL/worker regression proving one score row is recorded after a successful queued publish and none after failure/withdrawal.
- Responsive markup/style assertions for both public and administrator layouts.
- Editor heading accessibility assertion.
- Generated artifact UTF-8, JSON, XML, routing, and immutable-publication tests.

### Runtime regression

- Run the full Composer suite, strict Composer validation, PHP lint, JavaScript syntax check, database health check, and static dry run.
- Exercise login, edit, preflight, warning acknowledgement, queued publish, worker completion, public article, GEO dashboard trend, and withdrawal against the local MySQL runtime.
- Test desktop and 375 px public/editor layouts in a real browser.
- Publish a temporary article containing an image, validate image viewer interaction and alt behavior, then withdraw and remove the temporary content and media through controlled cleanup.
- Confirm the live generated tree contains no stale test article or Unicode replacement characters after final rebuild.

## 11. Delivery Boundaries

Implementation is divided into independently verifiable checkpoints:

1. UTC storage, legacy normalization, and timezone presentation.
2. Asynchronous GEO score parity.
3. Responsive and heading fixes.
4. Publish-preflight domain service and server enforcement.
5. Enhanced browser interaction and full regression.

Each checkpoint must preserve the immutable snapshot and atomic-publication tests. Internal-link/source assistance begins only after this release is stable.
