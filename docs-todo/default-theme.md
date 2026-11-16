# Default theme (C-6.1)

## What it does

Every site starts with the default theme. It gives each page the same accessible frame: a "Skip to main content"
link, a header with the site's name and main menu, the page's content, and a footer. It follows the visitor's
settings for light or dark mode, more contrast and reduced motion, and it works at any width down to a phone held
upright (320 pixels) and at 200 % zoom.

## Who uses it

- **Visitors and everyone signing in** see it on public pages and on the sign-in pages.
- **Theme authors** copy or override its templates and tokens. A theme only needs the files it changes: anything
  missing comes from the default theme.

## Files

| Path | What it is |
| --- | --- |
| `theme.json` | Name, label, version and the design tokens (below) |
| `templates/master.html.twig` | The page frame: `lang` / `dir`, skip link, landmarks, messages, styles, script |
| `templates/layouts/default.html.twig` | One reading-width column; the page title is the only `<h1>` |
| `templates/layouts/home.html.twig` | A wide introduction band with the title, then full-width content |
| `templates/partials/*.html.twig` | Buttons, alerts, cards, tables, pagination, disclosure, dialog, icons, messages |
| `assets/css/site.css` | The stylesheet; uses only the tokens' custom properties |
| `assets/js/site.js` | Optional: opens dialogs in browsers without invoker buttons. Pages work without it |

Theme files are served from `/assets/<path>` (for example `/assets/css/site.css`); `asset()` adds a version so
browsers keep them for a year and fetch the new file after an update. Only `.css`, `.js`, `.svg`, `.png`, `.jpg`,
`.webp`, `.ico` and `.woff2` files inside `assets/` are served — never `theme.json` or templates.

## Design tokens (`theme.json`)

| Group | Becomes | Example |
| --- | --- | --- |
| `colors.light` | `--color-<name>` | `"text": "#1a1a1f"` → `--color-text` |
| `colors.dark` | the same names, in dark mode | must define the same colours as `light` |
| `colors.more.light` / `colors.more.dark` | changes with "more contrast" | only the colours that change |
| `typography` | `--font-<name>` | `"size-base": "1.125rem"` → `--font-size-base` |
| `spacing` | `--space-<name>` | `"3": "1rem"` → `--space-3` |
| `radius` | `--radius-<name>` | `"small": "0.25rem"` |
| `focus` | `--focus-<name>` | `"width": "3px"` |
| `contrast` | nothing (checked by `theme:validate`) | see `theme-validation.md` |

Colours are `#rgb` or `#rrggbb`. Every theme must define `background`, `text`, `link`, `primary`, `on-primary`,
`border` and `focus`, and `typography.size-base`. Other values must be plain CSS values (no `;`, `{`, `}` or
comments). The generated `/assets/tokens.css` holds the light values, then dark mode, more contrast, and both.

## Templates: context and blocks

`master.html.twig` takes (all optional) `site_name`, `locale` (the page's language; default: the viewer's),
`main_menu` and `footer_menu` (lists of `{label, url, current}`; a menu landmark appears only when it has items),
`messages` (shown in a polite live region) and `form` (when it has errors the title starts with "Error:").
Blocks: `title`, `head`, `brand`, `breadcrumbs`, `main`, `footer`, `scripts`. The layouts take `title` and
`lead` and fill `content`.

## Partials

Import a partial's macros, for example `{% import 'partials/alerts.html.twig' as alerts %}`. Each file starts with
a comment showing how to use it. Rules they keep:

- Buttons and pagination links are at least 44 × 44 CSS pixels.
- Alerts say their type in words ("Error:", "Success:" …), not only by colour or icon; they are live regions only
  when `live: true` (for alerts a script adds later).
- Cards have one link (in the heading), so there is one tab stop per card.
- Tables have a caption and header cells; on narrow screens they scroll inside a labelled region you can tab to.
- Pagination says "Previous page" / "Next page" and marks the current page with `aria-current="page"`.
- The disclosure is a native `<details>`; the dialog is a native `<dialog>` opened by an invoker button
  (`commandfor`), so focus moves in, Escape closes it and focus returns to the button.
- Icons are hidden from screen readers unless they get a name: `icons.icon('external', t('opens in a new tab'))`.

## Accessibility

- Base font 1.125 rem (never below 16 px); text sizes in `rem`, so they follow zoom.
- Links are always underlined; focus is a 3 px outline in the focus colour.
- `prefers-color-scheme: dark`, `prefers-contrast: more`, `prefers-reduced-motion: reduce` and forced colours
  (Windows contrast themes) are supported.
- Tested with axe (WCAG 2.2 AA) in light and dark mode, with and without more contrast, at 320 px and at 200 %
  zoom, plus keyboard tests for the skip link, dialog, disclosure and table — see `tests/A11y/theme.spec.ts`.
- Still needs a manual screen-reader pass (NVDA + Firefox, VoiceOver + Safari) at milestone end.

## Code and tests

- `src/Theme/` (`ThemeModule`, `ThemeLoader`, `ThemeTokens`, `TokenStylesheet`, `ThemeAssets`,
  `Http/ThemeAssetHandler`), `resources/themes/default/`
- `tests/Unit/Theme/`, `tests/Integration/Theme/`, `tests/A11y/theme.spec.ts` (style guide at `/theme/home` and
  `/theme/default` in `tests/A11y/gallery`)
