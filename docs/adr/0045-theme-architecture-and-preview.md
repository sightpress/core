# 0045 — Themes: the architecture in theme-interface, and the static preview page

- Status: Accepted
- Date: 2026-11-20

## Context

The theme architecture, meaning presentation-only theme packages, a contract that describes and core that enforces,
validation on install and apply, and fallback to the default theme, is recorded where the contract lives:
`sightpress/theme-interface` ADR-0001. Core still needs to record what it builds on that contract. The first piece
is the preview page (D52). A theme package ships no screenshot, so every picture of a theme (the theme picker, the
docs gallery, a README or release, a pull request) must be made from something core renders the same way every time.

## Decision

- **Core follows `theme-interface` ADR-0001.** Core requires `sightpress/theme-interface` `^0.2`. Colour maths,
  contrast checks and `ThemeValidator` stay in core.
- **`theme:preview <theme|path> [--out=] [--locale=] [--screenshot]`** renders the contract's `page.json` through
  the real kernel and page handler. Nothing is mocked:
  - a throwaway tenant in a temporary folder holds one SQLite site named after the page's made-up site, with
    core's starter profile and core's sample media (`DemoMedia`);
  - the page is saved as the site's home page (`home` layout), with a level-2 heading per section;
  - the main menu holds one link per section, and the footer menu is empty;
  - a module loaded only by the preview (`ThemePreviewModule`) puts the Download button in the home layout's `hero`
    block (`hero` variable on `PublicPageRendering`), using the markup the planned `hero` block will have. The
    `hero` block replaces it when it lands.
- **Static and offline** (`StaticPage`): the page and every file it uses (stylesheets and the files they name,
  scripts, media, icons) are fetched from the site as a browser would fetch them and written with relative
  addresses. Links to other pages go to `#`, forms post to `#` with a visible note, and the web app manifest is
  left out. Canonical and alternate links stay absolute on `https://preview.invalid`, which never resolves. No
  request leaves the folder.
- **Deterministic:** the clock is frozen (`FrozenClock`, 2026-01-01 09:00 UTC), and CSRF tokens and CSP nonces are
  fixed. The random media keys and branding versions are replaced by content checksums and `preview`. Two runs give
  byte-identical folders, so the output can serve as a snapshot (T-0).
- **Screenshots are optional tooling:** `--screenshot` runs `resources/preview/screenshot.mjs` with Node.js and
  Playwright's Chromium. It writes 1280×800 light and dark shots of the top of the page and a full-page shot, with
  reduced motion and outside requests blocked. Neither tool is ever a runtime dependency of core or of a theme;
  without them the command says so and exits with an error.
- The preview code that drives several modules (`PreviewSite`) lives in shared code (`Cli\ThemePreview`), so the
  Theme module keeps to ADR-0009.

## Consequences

- One page is the style guide for every theme. T-0, T-1, T-4 and Q-1 validate and scan it, and its axe scan (light,
  dark, forced colours, 320 px, 200 % zoom) and keyboard walk run in `tests/A11y/theme-preview.spec.ts`.
- A preview takes a few seconds (a site is created each time); it is a build step, not a request-time feature.
- `@page/public.html.twig` prints a `hero` variable in layouts that have a `hero` block. Modules may set it with
  HTML they escaped.
