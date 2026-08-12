# Task 2 report

## Completed

- Added a path-safe Markdown article repository with a deliberately small YAML front-matter adapter, required `title`/`slug`/`date` validation, and byte-preserving Markdown body serialization.
- Added static rendering for article, index, topic, and about pages plus canonical metadata, semantic article markup, Article/Breadcrumb JSON-LD, RSS, JSON Feed, sitemap, robots, and optional `llms.txt`.
- Added focused coverage for front matter, required fields, unsafe path rejection, body preservation, and a two-article generated site.

## Commit

- `4350f46 feat: render markdown as static public site`

## Verification

- `composer test` -- PASS (6 tests, 23 assertions).
- `find src templates -type f -name '*.php' -print0 | xargs -0 -n1 php -l` -- PASS.
- `git diff --check` -- PASS before commit.

## Review fixes

- Hardened Markdown rendering for headings, links, lists, blockquotes, and fenced code.
- Added Open Graph/Twitter metadata and Article, Person, WebSite, and BreadcrumbList JSON-LD with hex-safe JSON encoding.
- Added topic sitemap entries, duplicate article slug rejection, topic slug collision/empty rejection, and quoted/backslash front-matter round-trip coverage.
- Follow-up commits: `78ce1ac`, `df5cad8`.

## Concern

- The front-matter adapter intentionally supports the scalar and simple list subset used by this product; nested YAML objects and multiline YAML values are rejected rather than silently misparsed.
