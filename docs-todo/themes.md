# Themes: install, check, apply

What it does: an operator installs themes with Composer; each site uses one of them; core checks a theme before it is
applied and falls back to the default theme when a site's theme is missing.

Who uses it: operators (install, allow, list, check), site admins (apply, with `theme.apply`).

- Install: `composer require vendor/some-theme` in the project, then `bin/sightpress cache:clear`. Core finds every
  package of type `sightpress-theme` that names its theme class in `extra.sightpress.theme`.
- `config/tenant.php`: `'themes' => ['allowed' => null, 'default' => 'default']`. `allowed` lists the theme ids sites
  may use (null = every installed theme); `default` is the theme of new sites and must be allowed.
- `theme:list`: every installed theme with its version and status (available; unavailable, with the reason; or
  installed but not allowed), whether it is the default, and the sites that use it. Sites whose theme isn't installed
  are listed in the errors.
- `theme:validate [<theme>|<path>] [--axe]`: the checks an apply runs.
  - the manifest and `theme.json` agree on id and version;
  - `theme.json` has the right shape;
  - contrast (charts included) passes in light and dark mode, with and without more contrast, and the base font size
    is at least 16 px;
  - the required templates are there, and the shell includes `{% include '@seo/head.html.twig' %}`;
  - the package ships no `screenshot.*`, `build/` or `theme-preview/` (unless `.gitattributes` export-ignores them).

  Warnings: a manifest `screenshot` (deprecated) or no `previewDescription`. `--axe` scans the theme's preview page
  with axe in every colour mode at 1280 and 320 px (needs Node.js and Playwright's Chromium).
- `site:theme:apply <site> <theme>`: the theme must be installed, usable with this SightPress, allowed and pass
  the checks; the site's audit log records it (actor: command line).
- `cache:clear`: empties the cache folder (compiled templates, the list of installed themes …).
- Fallback: a site whose theme was removed, is no longer allowed or needs another `theme-interface` is shown in the
  default theme. A warning goes to the log, and admins with `theme.apply` see "Your site is shown in the default
  theme" on the dashboard, with the reason.
- The admin panel always uses the default theme's look, whatever the site's theme.

Accessibility: contrast is checked again on every apply; a theme that fails is refused with the colour pair and the
ratio in the message. The dashboard notice is a region with its own heading, in words.

Code: `src/Theme/` (`ThemeRegistry`, `SiteTheme`, `ThemeChecker`, `ThemeApplier`, `Command/`),
`src/Tenant/TenantThemes.php`, `src/Site/Command/ClearCacheCommand.php`, `resources/views/seo/head.html.twig`; tests in
`tests/Unit/Theme/` (`Command/SiteThemesTest.php` runs the whole flow on two sites); ADR-0047.
