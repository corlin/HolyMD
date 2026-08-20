# HolyMD Public Design System Refinement

**Status:** implemented and verified
**Decision:** keep one recognizable public identity; do not build a multi-theme system.

## 1. Goal

Strengthen HolyMD as a calm, single-author personal-brand publication. Public pages should feel consistent, readable, and recognizably authored without adding template choice or appearance-management complexity.

## 2. Product decision

HolyMD has one canonical visual direction: warm paper, dark ink, restrained ink-blue accents, serif-led editorial typography, fine rules, and generous whitespace.

The existing system/light/dark control is a reader display preference. Product copy and code should call it a **display mode**, not a theme. It does not change layout, typography hierarchy, content structure, or brand identity.

## 3. Included scope

### Design tokens

Organize the existing public CSS around a small semantic token set:

- surfaces: paper and raised paper;
- text: primary and muted ink;
- action: accent and focus colors;
- structure: rules and borders;
- typography: display, reading, interface, and code families;
- rhythm: page width, reading width, spacing, and corner treatment.

Light and dark display modes override color tokens only. Components consume semantic tokens and must not introduce page-specific brand colors.

### Public-page consistency

Refine the shared visual hierarchy across:

- home and article streams;
- article reading pages;
- topic archives;
- About and other authored pages;
- search, table of contents, feeds/discovery links, image viewer, and empty states.

The shared header, footer, metadata, article rows, typography, focus states, and responsive spacing should behave consistently across these routes.

### Display-mode behavior

- Preserve System, Light, and Dark modes.
- Preserve the compact mobile cycle control and expanded desktop control.
- Apply the saved preference before first paint to avoid a visible flash.
- Keep keyboard access, clear pressed state, meaningful accessible labels, system preference fallback, and reduced-motion support.

### Administration

The administration interface keeps one stable writing-studio appearance. Settings may describe the public display modes, but there is no theme picker, theme preview gallery, or per-article appearance override.

## 4. Explicitly excluded

- multiple public themes or template packs;
- theme upload, marketplace, or custom CSS editor;
- per-page or per-article themes;
- arbitrary color, font, radius, and layout controls;
- seasonal skins;
- separate branded administration themes.

These become relevant only if HolyMD adds multi-site, white-label, multiple-brand, or template-distribution use cases.

## 5. Implementation shape

The existing static publishing model remains unchanged. `templates/public/site.css` remains the public style source, generated into a content-hashed asset by `StaticBuilder`.

Refinement should proceed in three small passes:

1. Normalize semantic tokens without changing rendered appearance.
2. Remove inconsistent one-off values and align shared public patterns.
3. Perform route-by-route desktop and mobile visual QA, fixing only demonstrated drift.

No database schema, publication snapshot, queue, Markdown, GEO, feed, routing, or JSON-LD change belongs in this work.

## 6. Acceptance criteria

- System, Light, and Dark modes preserve their preference and accessible state.
- Home, article, topic, and authored pages share the same brand hierarchy.
- Public routes have no horizontal overflow at 375 px.
- Text, controls, focus indicators, and muted states meet WCAG AA contrast.
- Long titles, summaries, tables, code blocks, contents lists, and images remain readable.
- Reduced-motion preference is respected.
- Generated HTML, feeds, discovery files, canonical URLs, and content hashes remain behaviorally unchanged except for the expected CSS asset hash.
- Desktop and mobile browser comparisons show no unintended regression from the delivered warm-paper/ink-blue reading experience.

## 7. Success measure

The work succeeds when HolyMD looks more internally consistent while readers still recognize the same publication. It does not succeed by offering more appearance choices.
