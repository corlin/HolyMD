# HolyMD Public Design System Refinement Implementation Plan

> **For Codex:** Execute this plan task by task. Keep the existing uncommitted simplification work intact. Do not commit, push, merge, deploy, or mutate production data.

**Goal:** Refine HolyMD's single warm-paper / ink-blue public design system so it is semantically organized, consistently applied, responsive at 375 px, accessible in System/Light/Dark display modes, and calmer on long-form articles.

**Architecture:** Keep one canonical visual identity and the existing static-rendering pipeline. Consolidate presentation decisions into semantic CSS custom properties and shared public templates; preserve `data-theme` and `holymd_theme` as compatibility mechanics while presenting the feature to readers as a display mode. Limit behavior changes to public presentation and accessibility labels.

**Tech Stack:** PHP 8.4 templates, static HTML/CSS/vanilla JavaScript, PHPUnit, PHP built-in server, in-app browser automation.

---

### Task 1: Lock the display-mode and semantic-token contract

**Files:**
- Modify: `tests/Render/StaticBuilderTest.php`
- Modify: `templates/public/_header.php`
- Modify: `templates/public/_footer.php`
- Modify: `templates/public/site.css`

**Step 1: Add failing rendering assertions**

Assert that every representative public page exposes `aria-label="Display mode"`, a mobile control labelled `Display mode: System`, and semantic tokens such as `--surface-canvas`, `--text-primary`, `--action-primary`, `--border-subtle`, `--focus-ring`, `--font-ui`, and `--font-reading`. Assert the old reader-facing phrases `Theme switcher` and `Change theme` are absent.

**Step 2: Run the targeted test and confirm it fails**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php`

Expected: failure on the new display-mode labels and semantic-token names.

**Step 3: Implement the shared contract**

Rename only reader-facing copy from theme to display mode. Preserve the storage key and `data-theme` values for backward compatibility. Replace primitive color/font/rhythm declarations with semantic tokens in `site.css`, including light, explicit dark, and system-dark values. Add a single shared `:focus-visible` treatment and explicit reduced-motion behavior.

**Step 4: Run the targeted test**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php`

Expected: pass.

### Task 2: Correct responsive header and long-reading details

**Files:**
- Modify: `tests/Render/StaticBuilderTest.php`
- Modify: `templates/public/_header.php`
- Modify: `templates/public/_footer.php`
- Modify: `templates/public/site.css`

**Step 1: Add failing structural assertions**

Assert that the public stylesheet includes a compact mobile header contract, `overflow-wrap` for long mixed-language content, a thin/transparent TOC scrollbar, hidden fourth-level items in the desktop rail, and a roomy footer-wrap contract. Assert the full mobile TOC remains present in generated markup.

**Step 2: Run the targeted test and confirm it fails**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php`

Expected: failure on the new responsive and TOC rules.

**Step 3: Implement professional detail corrections**

At the narrow breakpoint, guarantee the brand, primary Writing link, search, and display-mode cycle fit without horizontal scrolling. Keep touch targets at least 44 px. Let article titles/decks/code/tables wrap or scroll locally rather than expanding the page. On desktop, present H2/H3 in the rail, keep H4 in the collapsible mobile TOC, and style the rail scrollbar as a quiet affordance. Normalize card, control, footer, metadata, and long-form spacing/radii through tokens.

**Step 4: Run the targeted test**

Run: `vendor/bin/phpunit tests/Render/StaticBuilderTest.php`

Expected: pass.

### Task 3: Verify generated-output and interaction invariants

**Files:**
- Modify only if a regression is found: `templates/public/_head.php`, `templates/public/_header.php`, `templates/public/_footer.php`, `templates/public/site.css`

**Step 1: Run automated verification**

Run:
- `composer test`
- `find src tests public bin config templates -name '*.php' -print0 | xargs -0 -n1 php -l`
- `node --check public/assets/admin.js`
- `php bin/holymd-build.php --dry-run`

Expected: all code checks pass; dry-run output changes only in the generated CSS hash and intentional shared public markup/copy.

**Step 2: Build a non-production preview**

Render published content into a temporary directory with the current `StaticBuilder`, serve it on a temporary local port, and leave the existing public release tree untouched.

**Step 3: Perform desktop and mobile browser checks**

At 1440×1024 and 375×812, verify home, article, topic, and authored page routes. Confirm `scrollWidth <= innerWidth`, System/Light/Dark persistence, keyboard focus, search open/close, mobile TOC expansion, reading progress, long H2/H3/H4 content, footer wrapping, and zero new console errors.

**Step 4: Capture and inspect evidence**

Save before/after screenshots for home and the long article at desktop/mobile sizes. Inspect the exact files, compare matching viewports, and open the final local preview in Codex for handoff.

### Task 4: Close with a requirements audit

**Files:**
- Review: `docs/superpowers/specs/2026-08-19-public-design-system-refinement-design.md`
- Review: this plan
- Review: final scoped diff

**Step 1: Reconcile the diff with scope**

Confirm no theme marketplace, theme picker, database setting, routing, snapshot, GEO, feed, JSON-LD, or admin behavior was added or changed.

**Step 2: Re-run the original evidence checks**

Re-run the targeted render test, full test suite, lint, dry-run, and browser overflow/interaction checks after the last correction.

**Step 3: Report the outcome**

Summarize professional judgments, exact files changed, automated counts, browser viewport evidence, known environment limits, and the next recommended product step. Do not claim full WCAG compliance; report the specific checks actually performed.
