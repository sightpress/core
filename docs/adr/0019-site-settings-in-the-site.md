# 0019 — Site settings live in the site, and some of them win over the registry

- Status: Proposed
- Date: 2026-11-17

## Context

C-7.3 lets a site's admins change its settings in the admin panel: the name, the default language and time zone,
who runs the site, the search & AI options and the branding. The tenant registry (`config/sites.php`, ADR-0002)
holds the name, default locale and time zone too, but only CLI commands write it, and the web process must not.
Modules also need a way to offer settings without writing screens: the module contract already has `Setting`.

## Decision

- Site settings are stored in the site's own `site_settings` table (the `SiteSettings` contract).
- The admin screen `/admin/settings` (Admin module, `settings.manage`) shows the name, default language and time
  zone, then a field for every `Setting` the modules registered (`SettingCatalogue`, shared core code), grouped by
  module, with its label, help and type. Core checks the format of the settings it knows (email, profile links,
  search console codes in `Seo\Contract\SiteVerification`).
- The name, default language (one of the site's languages) and time zone the admins chose are stored as
  `site.name`, `site.default_locale` and `site.default_timezone`. When a site's container is built, they replace the
  registry's values in its `Site` (`Site::withDisplay()`, `SiteProfile::display()`). The registry keeps the values
  the site was created with; its domains, languages, status and database stay CLI-only.
- Saving dispatches `Site\Contract\SettingsChanged` with the keys that changed. The Site module then forgets the
  site's container (so long-running workers pick up a new name), the Branding module makes its generated pictures
  again, and the audit log records the change.
- Who runs the site (`SiteProfile`: description, legal name, email, phone, address, profiles elsewhere) feeds the
  `Organization` / `Person` node of the structured data and `llms.txt`.
- IndexNow is a boolean setting, off by default (D12). When it is on, publishing or unpublishing a page in
  production posts the page's address to `api.indexnow.org` with the site's key, served at `/indexnow-key.txt`.
  Core adds `symfony/http-client` for it (ADR-0015).

## Consequences

- Modules get settings fields for free by registering `Setting`s.
- Inside a site, `Site::$name` may differ from the registry; CLI output that reads the registry shows the original
  name.
- Settings changes reach the next request, also in long-running workers.
