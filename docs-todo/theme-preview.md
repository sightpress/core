# Theme preview

What it does: `theme:preview` writes a theme's preview page as a static folder you can open in any browser, with
no server and no internet connection. The page is the same for every theme (`page.json` from
`sightpress/theme-interface`): a top bar with links to each part of the page, a band with the theme's name,
description and a Download button, one block of every kind core has, and the footer. Screenshots of a theme are
made from this page; a theme package ships none.

Who uses it: theme authors (to see and test their theme), reviewers of a theme change (the pull request preview),
operators (the theme picker's pictures, later).

- Command: `theme:preview <theme|path> [--out=build/theme-preview/<id>] [--locale=en-GB] [--screenshot]`.
  `<theme>` is `default` or the folder of a theme (the one with `theme.json`).
- Without a tenant project (a theme's own repo, CI): `vendor/bin/sightpress-theme theme:preview .` and
  `vendor/bin/sightpress-theme theme:validate .`, with core as a dev dependency.
- Output: `index.html`, `assets/` (the theme's CSS, scripts, fonts and `tokens.css`), `media/` (core's sample
  picture, PDF, recording, video and captions) and the site's icons. All addresses are relative.
- The folder must be empty or hold an earlier preview, which is replaced as a whole.
- The same theme always gives the same bytes.
- `--screenshot` adds `screenshot.png` and `screenshot-dark.png` (1280×800, the top of the page) and `page.png` (the
  whole page). It needs Node.js and Playwright's Chromium (`npx playwright install chromium`); the dev-tools image
  and CI have them. Without them the command explains what is missing and fails.
- The theme's `previewDescription` (manifest) is the text alternative of those screenshots; the command reminds you
  when it's missing.

Accessibility:

- The page has 0 axe violations in light, dark and forced-colours mode at full width, 320 px and 200 % zoom, and works
  by keyboard: skip link, the top bar's links, Download, then every control (`tests/A11y/theme-preview.spec.ts`).
- The Download button's accessible name starts with its visible label ("Download <theme name>").
- The contact form shows a note that it is a preview and can't send.

Code: `src/Theme/Preview/`, `src/Theme/Command/PreviewThemeCommand.php`, `src/Cli/ThemePreview/PreviewSite.php`,
`resources/preview/screenshot.mjs`; ADR-0045.
