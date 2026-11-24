# 0047 — Themes: discovery, a theme per site, and an admin that keeps the default theme

- Status: Accepted
- Date: 2026-11-20

## Context

T-0 moved the default theme into its own package. T-1 lets an operator install more themes with Composer and lets
each site use one of them (D26), as theme-interface ADR-0001 describes. Core needs to decide:

- where installed themes are found;
- how a site's choice reaches the template engine, which until now was one per tenant;
- what happens when a theme is removed or can't be used;
- how a third-party theme is kept from breaking the admin panel, whose screens core owns.

## Decision

- **Discovery:** `ThemeRegistry` reads `vendor/composer/installed.json` for packages of type `sightpress-theme` with
  `extra.sightpress.theme`, the same way modules are found. The default theme is always there, first. Outside debug
  mode, what it finds is cached in the cache folder until `installed.json` changes, and `cache:clear` (new) empties
  it. A theme whose `theme-interface` constraint the installed contract doesn't meet is listed as unavailable, with the
  reason. Composer's own `InstalledVersions::satisfies()` is used, so branch aliases of a checkout count.
- **Tenant settings:** `themes.allowed` (null = every installed theme) and `themes.default` (the theme of new sites,
  which must be allowed; checked when the configuration is loaded).
- **A theme per site:** the choice is the site setting `theme`. `SiteTheme` resolves it, and the site container
  defines its own `TemplatePaths`, Twig `Environment` and `TemplateRenderer` from it: the site's theme, then the
  default theme package, then core's views. Every module service is already built per site, so they all render with
  the site's theme. Twig's compiled templates are keyed by their full path, so the themes share one cache folder.
- **Fallback:** a site whose theme is missing, no longer allowed or can't be used is shown in the default theme. The
  reason is logged as a warning, and people with `theme.apply` see it on the dashboard. `theme:list` lists sites whose
  theme isn't installed.
- **Checks** (`ThemeChecker`, used by `theme:validate` and on every apply):
  - the manifest and `theme.json` agree on id and version;
  - the `theme.json` shape is valid;
  - contrast, chart colours included, passes in every colour mode, and the base font size is at least 16 px;
  - the required templates for every layout the manifest lists are there;
  - the shell includes core's head partial;
  - nothing would ship that looks like a screenshot or preview output (`export-ignore`d paths don't count).

  The deprecated `screenshot` and a missing `previewDescription` give warnings. `theme:validate --axe` also scans the
  preview page with axe where Node.js and Chromium exist.
- **Apply** (`ThemeApplier`, `site:theme:apply`): the theme must be installed, usable, allowed and pass the checks;
  then the setting changes and `ThemeApplied` goes to the audit log, with a null actor from the command line.
- **Core's head partial** `@seo/head.html.twig` (theme-interface `CorePartial::Head`) prints the head tags the
  modules give. Pages no longer pass them through the shell's `head` block, so a theme can't drop them.
- **The admin panel always uses the default theme's stylesheet, tokens and script** (`/assets/admin/…`), whatever
  the site's theme. The admin keeps its contrast and layout with any theme, and token overrides of a site (T-2)
  don't apply to it. The front-end pages a theme renders (sign-in, My account) use the site's theme.

## Consequences

- A theme can be installed, checked, applied and removed without touching core. Removing it never breaks a site.
- A site's templates come from its theme only in its own container. Tenant-level pages that render a template (the
  "no site configured" page) keep the default theme.
- `theme:publish-assets` (gap 8) lands with the admin theme picker (T-2), which needs the published preview.
