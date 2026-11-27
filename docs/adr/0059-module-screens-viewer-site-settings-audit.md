# 0059 — Module screens, the viewer, site information, settings, audit and translations

- Status: Accepted
- Date: 2026-11-24

## Context

An external module's admin screen needs what core's own screens use: who is signed in and what they may do, the
admin shell, translated titles and notices, the site's settings, and the audit log (ADR-0057). All of these are
core classes today.

## Decision

- **`Access\Viewer`**: final value (account id, permissions) that core puts on every request as the attribute
  `Viewer::class`, next to its own `Actor`. `Viewer::fromRequest()` falls back to a signed-out visitor.
- **`Http\Screens`** (implemented by core, in the site container): `admin(request, AdminScreen)`,
  `public(request, PublicScreen)` and `redirect(request, path, ?notice)`. The module's template draws only the
  content; core draws the shell, skip link, landmarks, `<h1>` (admin), notices (a status message), menus and
  footer (public), the head from `PublicScreen::$seo`, and the security headers. Templates must be in the module's
  own view namespace; statuses are 2xx, 4xx or 5xx (redirects go through `redirect()`).
- **`Text\Message`**: text, `{name}` parameters and a translation domain, translated by core in the viewer's
  language. A **`translations()` registrar** adds a folder of `<domain>.<locale>.xlf` files; core refuses the
  `messages` domain, which is its own.
- **`Site\SiteInfo`**: id, slug, name, default locale and locales, in the site container.
- **`Setting\SettingValues::get(key)`**: the saved value typed by its `SettingType`, or the registered default;
  an unknown key throws. Settings are still edited on core's settings screen.
- **`Audit\AuditTrail::record(AuditRecord)`**: action (dotted, starting with the module id), one-sentence summary,
  actor, subject type and id, and scalar details. No secrets or full personal data.
- Forms stay template-level in 0.5: core's form macros, `csrf_fields()` and the global CSRF check are documented
  as core's template API.

## Consequences

- The tutorial's admin screen needs no core import.
- Core implements four small adapters (`Screens`, `SettingValues`, `AuditTrail`, `SiteInfo`) over the classes it
  already has, and sets one more request attribute.
- A PHP form contract is deferred (ADR-0057), so modules write their own validation and error summary for now.
