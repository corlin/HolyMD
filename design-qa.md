# HolyMD Public Reading Experience — Design QA

## Comparison target

- Source visual truth: `.gstack/ui-audit/2026-08-13/selected-reading-workspace.png`
- Final desktop implementation: `.gstack/ui-audit/2026-08-13/09-article-implementation-desktop-final.png`
- Final mobile implementation: `.gstack/ui-audit/2026-08-13/10-article-implementation-mobile-final.png`
- Full-view comparison: `.gstack/ui-audit/2026-08-13/compare-desktop-final.png`
- Focused comparison: `.gstack/ui-audit/2026-08-13/compare-focused-final.png`
- Route: `http://127.0.0.1:8790/articles/test/`
- State: light theme, top of the published article, desktop table of contents visible.

## Normalization

- Requested CSS viewport: `1440 x 1024` at device density `1`.
- Source pixels: `1487 x 1058`; normalized to `1425 x 1013` for comparison.
- Implementation pixels: `1425 x 1013` from the browser-rendered desktop page.
- Mobile requested CSS viewport: `390 x 844`; browser content capture: `375 x 833` after scrollbar/chrome allocation.
- The source and implementation desktop images were combined side by side at equal pixel dimensions before review.

## Findings

- No actionable P0, P1, or P2 differences remain.
- Fonts and typography: the serif-led Chinese/English hierarchy, title scale, metadata weight, body line height, and drop-cap treatment preserve the selected visual direction. The implementation uses the project font stack rather than adding a remote font dependency.
- Spacing and layout rhythm: the desktop page matches the source's persistent left contents rail and wide reading column. The title, summary, metadata, divider, and opening prose keep the same reading order and spatial grouping.
- Colors and visual tokens: warm paper, dark ink, muted text, fine rules, and restrained blue accents map directly to project tokens and remain legible in both light and dark themes.
- Image quality and asset fidelity: the target contains no raster imagery, logo art, avatar, or illustration. Existing Material Symbols icons are used consistently; no placeholder image, handcrafted SVG, CSS illustration, or emoji substitute was introduced.
- Copy and content: dynamic article title, summary, headings, dates, author, and reading time remain truthful to the published Markdown and front matter. The source mock's short subtitle is replaced by the actual configured summary rather than fabricated copy.
- Responsiveness and accessibility: at mobile width the reading column remains `351px` with no horizontal document overflow, the desktop rail is removed, the mobile table of contents is collapsed by default, tap targets remain usable, focus styles are preserved, and reduced-motion behavior remains respected.

## Comparison history

### Pass 1 — blocked

- P1: the title wrapped across three lines and pushed the article body too far below the fold.
- P2: the content column began too far right and was narrower than the selected design.
- P2: the paper token was visibly darker and more yellow than the source.
- Evidence: `.gstack/ui-audit/2026-08-13/compare-desktop-v1.png`.

Fixes applied:

- Reduced the desktop title scale and increased its available line length.
- Expanded the reading column to `52rem` and tightened the grid gap.
- Lightened the warm-paper surface while retaining the existing brand palette.
- Reduced summary size and increased its usable measure.

### Pass 2 — passed

- The title remains on one line at the desktop comparison viewport.
- The contents rail, article masthead, divider, drop cap, and prose now align with the selected direction.
- The longer real summary creates extra height versus the mock; this is an accepted content constraint, not visual drift.
- The three-state theme control is intentionally retained because it is an existing product feature and remains visually subordinate.
- Evidence: `.gstack/ui-audit/2026-08-13/compare-desktop-final.png` and `.gstack/ui-audit/2026-08-13/compare-focused-final.png`.

## Browser verification

- Tested desktop contents navigation; the URL changed to `#heading-2` and the matching rail link received `aria-current="location"`.
- Tested mobile table-of-contents expansion.
- Tested light/dark theme switching and restored light theme for comparison.
- Tested reading progress from `0` to `100`.
- Checked browser console warnings and errors: none.
- Checked desktop and mobile rendered screenshots after the final build.

## Follow-up polish

- P3: an optional dedicated `subtitle` front-matter field could reproduce the mock's short deck while keeping the longer summary for metadata and feeds.
- P3: very long tables of contents could later group lower-level headings, but the current rail scrolls safely and all article sections remain reachable.

final result: passed
