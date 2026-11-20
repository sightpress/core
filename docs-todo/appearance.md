# Appearance: the theme picker and the site's colours

What it does: admins choose the site's theme from the installed ones, preview each one, and change the main colours
of the site's theme, which are checked for contrast before they are saved.

Who uses it: site admins (permission `theme.apply`).

- `/admin/appearance` (admin menu **Appearance**): every installed theme in its own section.
  - Each section has the theme's name as a heading, its description, its status in words ("The site's theme",
    "Available", "Can't be used with this version of SightPress", "Not allowed on this server") and its version.
  - The screenshot of its preview page has the theme's `previewDescription` as alt text. Without a screenshot, that
    description is shown as text.
  - **Preview …** opens the theme's static preview page in a new tab, and the link says so.
  - **Use …** applies the theme. It is checked again, and the audit log records it.
- `/admin/appearance/colours`: background, text, links, buttons and highlights, text on buttons, borders and focus
  outline, in light and in dark mode.
  - Each colour is a text field with its hex value (`#0a57c2`).
  - Saving checks contrast with the theme's pairs. A failing pair is refused with both colours and the ratio in words,
    on the field that changed, and the form keeps what was typed.
  - **Use the theme's own colours** removes the site's colours.
  - Colours are stored per theme (site setting `theme.colours.<id>`). They change `tokens.css` and its address, so
    browsers fetch the new colours at once. The admin panel keeps the default theme's colours.
- `theme:publish-assets [--no-screenshot]` writes every installed theme's preview page (and, where Node.js and
  Playwright's Chromium are installed, its screenshots) to `public/themes/<id>/<version>/preview/`. The skeleton
  runs it after `composer install` and `composer update`.

Accessibility: statuses are words, never colour alone; the preview link says it opens a new tab; the error summary
names each failing pair; 0 axe violations in light, dark and forced colours at 320 px (`tests/A11y/appearance.spec.ts`).

Code: `src/Theme/Http/` (`AppearanceHandler`, `ThemeColoursHandler`), `ThemeColours`, `PublishedPreviews`,
`ThemeChanged`, `Command/PublishThemeAssetsCommand.php`, `resources/views/theme/`; ADR-0048.
