# HolyMD Design Specification

**Status:** user-approved design, awaiting written-spec review
**Target environment:** shared hosting with PHP 8.4, local MySQL, and a web server that can directly serve static files.

## 1. Product Intent

HolyMD is a small, refined, single-author personal-brand blog manager. The author writes articles in Markdown and publishes a static public site. The product is domain-neutral: the author's identity is stable while topics are expressed through each article's metadata, themes, and structured entities.

The public site must be easy for readers and machine systems to understand. This means readable semantic pages, accurate metadata, clear authorship, citations where present, feeds, and structured data. It does not promise indexing, ranking, or citation by any search or generative-AI service.

## 2. Scope and Boundaries

### Included in the first release

- One administrator and one public personal site.
- Markdown authoring, preview, draft/published states, version snapshots, and publish/withdraw/restore actions.
- Static generation of public article, index, theme, about, feed, and discovery files.
- A PHP administration interface built around the selected writing-studio visual direction.
- AI-assisted GEO review and metadata proposals, reviewed by the administrator.
- Local media storage and MySQL-backed operational state.

### Explicitly excluded

- Multi-user collaboration, comments, newsletters, ecommerce, analytics dashboards, and multi-site management.
- AI drafting, continuation, or rewriting of article prose.
- Automatic public publishing by AI.
- Any claim that a generated output guarantees SEO/GEO visibility, indexing, rank, or citation.

## 3. Experience Design

The core screen is a quiet three-column writing studio:

- **Left rail:** product identity, Articles, Library/media, Settings, and the author profile.
- **Center:** the Markdown editor, rendered preview, article title, metadata summary, save state, and the single primary Publish action.
- **Right rail:** a focused GEO assistant that reports metadata and content-structure suggestions without editing the article body.

The visual language is editorial rather than dashboard-like: warm paper surfaces, dark type, restrained ink-blue accents, fine dividers, serif display headings, and generous whitespace. Interfaces prioritize rows, typography, and grouping over stacked cards and analytics panels.

## 4. Content and Storage Model

Markdown files are the sole source of truth for article content. Each article is stored at `content/articles/<slug>.md` with YAML front matter for its public metadata.

MySQL must not hold article bodies. It stores only operational state:

- article index and file path;
- draft/published/withdrawn state, slug history, and build manifest;
- version snapshot references and audit events;
- administrator account and configuration;
- AI GEO review runs, individual proposals, and explicit accept/reject decisions;
- queued work and build records.

Media lives in a managed filesystem directory. A published site can continue to serve its already-generated pages even if MySQL is unavailable.

## 5. Authoring and GEO Review Flow

1. The administrator creates or imports a Markdown draft and writes the article independently.
2. The editor autosaves safely without advancing content version history. A rendered preview is always available.
3. The administrator requests a GEO review bound to an immutable review input that is not exposed as a content version.
4. The AI returns proposals only for:
   - article summary and TL;DR;
   - title/description and URL-slug suggestions;
   - topics, entities, FAQ candidates, and reader questions;
   - front matter and structured-data fields;
   - missing clarity, source, author, hierarchy, image-alt, and internal-link signals.
5. When the article needs stronger evidence or explanation, the AI identifies the missing question or evidence. It never writes a replacement paragraph.
6. Every proposal is individually accepted, rejected, or manually changed. Acceptance changes only Markdown front matter and related metadata; it never edits body content.
7. Publishing runs validation and an incremental static build. Only a successful build becomes public and records a restorable content version; queued or failed publication inputs remain outside version history.

## 6. Static Generation and Public Output

Publishing transforms Markdown into static assets under a generated `public/` tree. Public article reads do not invoke PHP or MySQL.

Each article route uses a stable form such as `/articles/<slug>/index.html` and includes:

- semantic HTML with meaningful `article`, heading, author, time, citation, and navigation elements;
- visible article summary, author attribution, publish/update dates, sources, and related content where available;
- title, description, canonical URL, Open Graph, and Twitter Card metadata;
- truthful JSON-LD selected from `Article`, `Person`, `WebSite`, and `BreadcrumbList` based on available real data.

The build also produces the home/index pages, topic pages, about page, `sitemap.xml`, `robots.txt`, RSS, and JSON Feed. `llms.txt` may be generated as an optional experimental discovery file, never as a dependency or a claim of crawler support.

Changed slugs generate explicit redirects. Withdrawn or deleted content removes the generated target under a controlled build and updates indexes, feeds, and the sitemap.

## 7. Build Safety and Failure Handling

Before generation, the system validates front matter, duplicate slugs, target collisions, internal links, media references, citations, and required structured-data inputs.

Builds render into a temporary output directory. Only a fully validated result replaces the live public tree through an atomic switch. If any step fails, the previous static site remains live, the failed build is retained as an audit record, and the backend reports the affected article and field clearly.

Time-consuming GEO reviews and builds use a MySQL queue driven by scheduled Cron invocations; the release has no dependency on a daemon or long-running worker. Failed work supports safe retry without publishing side effects.

## 8. Verification Requirements

- Markdown-to-HTML snapshots preserve headings, links, code, quotes, lists, and front matter rendering.
- Static routing, canonical URLs, redirects, feeds, sitemap, and discovery files are tested from generated output.
- JSON-LD is syntactically valid and reflects only source data present in the article/site configuration.
- A failed build leaves the previously published static tree untouched.
- AI proposal tests assert that no operation mutates Markdown body text; only an explicit accepted metadata proposal may modify front matter.
- Authorization tests protect the admin interface, AI configuration, local media, publishing, and rollback actions.

## 9. Deferred Decisions for Implementation Planning

- The supported Markdown parser and front-matter library.
- The initial compatible AI API provider and the secure storage method for its credentials.
- The exact static-tree switching mechanism available on the selected host.
- The first public-site template details, derived from the selected writing-studio direction.
