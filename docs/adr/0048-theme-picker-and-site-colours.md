# 0048 — Themes: the admin theme picker, published previews and a site's own colours

- Status: Proposed
- Date: 2026-11-20

## Context

T-2 gives site admins a screen to choose the site's theme (D26) and to adjust its colours. A theme package ships no
screenshot (D52), so the picker needs pictures made on the server. Colours that a person types must not break the
contrast the theme was checked for.

## Decision

- **`/admin/appearance`** (permission `theme.apply`) lists every installed theme and applies one with `ThemeApplier`,
  the same as `site:theme:apply`. Each status is a word, never a colour. The screenshot's alt text is the theme's
  `previewDescription`, and without a screenshot that description is shown as text.
- **Published previews** (gap 8): `theme:publish-assets` renders every installed theme's preview page into
  `public/themes/<id>/<version>/preview/`, which the web server sends as static files. Screenshots are taken only where
  Node.js and Chromium are installed. The skeleton runs the command after `composer install` and `composer update`. The
  "Preview" link opens that page in a new tab and says so. It is a real page, not an iframe.
- **A site's colours** are stored per theme in the site setting `theme.colours.<id>`. Only the seven colours every theme
  defines can be changed, in light and dark mode. "More contrast" keeps the theme's own overrides. Before saving, the
  theme with the new colours runs through `ThemeValidator`. Failing pairs are refused on the field that changed, with
  both colours and the ratio in words. The colours apply wherever the theme is shown on the site (`tokens.css`), never
  in the admin panel.
- **Changes reach every request at once:** `ThemeApplied` and `ThemeColoursChanged` evict the site's container
  (`ThemeChanged`), so a long-running worker builds it again. The colours are part of the asset version, so browsers
  fetch the new `tokens.css` despite their one-year cache. Both events are in the audit log.

## Consequences

- Without Chromium on the server, the picker is text only. That is enough to choose, and the static preview is one
  click away.
- A theme update gets a new published folder (by version); old folders can be deleted by hand.
