# 0057 — Module contract audit: what an external module can reach (`module-interface` 0.5 scope)

- Status: Proposed
- Date: 2026-11-24

## Context

M4 opens SightPress to external modules installed with Composer (X-1). ADR-0009 says modules depend on
`sightpress/module-interface` only, never on core, but core's own modules reach many core classes directly: about
25 event classes under `src/*/Contract/`, `Rbac\Contract\Actor`, `Admin\Contract\AdminScreens`, the `Http\Form`
classes, `Template\Message`, `Site\Contract\SiteSettings`, `Site\Contract\ScheduleDue`, the audit recorder and the
SEO files. An external module can't use any of them without depending on core, which the release policy forbids.

X-2 lists every core class that core modules use across areas and an external module would need, and decides for
each one: **add** it to the contract, **keep** it internal (with the reason), or **defer** it. The test is the
"events" feature of the module tutorial (X-3): a permission, an admin screen with a menu item, a block type, a
migration, `Event` JSON-LD, Markdown, sitemap entries and search documents, written only against
`module-interface`. The table below sets the scope of `module-interface` 0.5 (MI-0.5); each area that is added has
its own ADR.

## Decision

| Area | Core today | Decision | Contract in 0.5 / reason |
|---|---|---|---|
| Events (X-6) | ~25 classes in `src/*/Contract/` | **Add** | `SightPress\Module\Event\*`, ids and plain values only, part of the 1.0 API. Core's classes are removed. ADR-0058 |
| Current user and permissions | `Rbac\Contract\Actor` (request attribute) | **Add** | `Access\Viewer` (request attribute: account id, permissions, `can()`). ADR-0059 |
| Access checks on routes | `AdminAccessMiddleware` per route | **Add** | `Route::$permission` (core checks sign-in, the permission and `admin.access` under `/admin`). ADR-0062 |
| `Authorizer` / voters | `Rbac\Contract\Authorizer`, `Voter` | **Keep** | Page-level rules (own pages, states) belong to the page module; modules check their own permissions with `Viewer::can()`. Revisit if a module needs per-object rules on core content |
| Current site | `Tenant\Site` | **Add** | `Site\SiteInfo` (id, slug, name, locales) in the site container. `Site` stays internal: it holds storage and database details. ADR-0059 |
| Admin screens and public pages | `Admin\Contract\AdminScreens`, `PublicScreenRendering` | **Add** | `Http\Screens` with `AdminScreen` / `PublicScreen` value objects; core keeps the shells, landmarks and headers. ADR-0059 |
| Translatable text | `Template\Message`, `Translations` | **Add** | `Text\Message` and a `translations()` registrar for the module's own XLIFF domain. ADR-0059 |
| Forms (PHP) | `Http\Form\Form`, `Field`, `FormValidator` | **Defer** | Tied to core's session, flash and error summary. Modules validate their own input in 0.5; a form contract comes with the form options of `blocks.md` §4 (BL track) |
| Forms and Twig (templates) | `form/macros.html.twig`, `t()`, `can()`, `csrf_fields()`, `current_locale()`, `user_date` | **Add (template API)** | Documented as core's template API in the module reference; covered by core's 1.0 promise, not by a PHP type. CSRF stays global middleware |
| Settings | `SiteSettings`, `SettingCatalogue` | **Add (read)** | `Setting\SettingValues::get()`, typed with defaults. Writes stay on core's settings screen. ADR-0059 |
| Audit log | `AuditRecorder`, `AuditLog` | **Add** | `Audit\AuditTrail::record(AuditRecord)`. ADR-0059 |
| Scheduled jobs | `Site\Contract\ScheduleDue` (carries the `Site` and the container) | **Add** | `Schedule\ScheduledJob` + `ScheduleRun` + `schedule()` registrar. `ScheduleDue` stays for core's own jobs. ADR-0061 |
| SEO | `SeoFiles`, `SeoHead`, `PublicPageRendering` | **Add** | `Seo\SeoProvider` / `SeoEntry` + `seo()` registrar; JSON-LD through `PublicScreen::$seo`. Wired in X-1. ADR-0060 |
| Search | — | Already in 0.4 | `SearchDocumentProvider`, `SearchBackend` (ADR-0023) |
| Assistant tasks | — | Already in 0.4 | `AssistantTask`, `AssistantProvider` (ADR-0023) |
| Dashboard | — | Already in 0.4 | `DashboardWidget` (ADR-0023) |
| SMS (N-1) | nothing yet | **Add** | `Sms\*`, `NotificationChannel`, `sms()` registrar. ADR-0063 |
| Critical actions (N-6) | nothing yet | **Add** | `Route::$critical`, default `false`. ADR-0062 |
| Blocks: variants, options, icons (BL-1, gap 2) | nothing yet | **Add** | `Block\StyledBlockType`, `BlockStyles`, `BlockOption`, `BlockVariant`, `BlockIcon`, `addVariant()` / `addIcon()`. Core rendering follows in BL-1. ADR-0064 |
| Verification codes | `Auth\Contract\VerificationCodes` | **Keep** | Security-sensitive and tied to sign-in; modules that need a confirmation mark the route `critical` |
| Mailer | `Infrastructure\Mail\MailSender` (Symfony `Email`) | **Defer** | Would put a mail library type in the contract. Comes with notification routing (N-4), as a notification contract |
| Media library | `Media\Contract\MediaLibrary`, `MediaUrlGenerator` | **Defer** | Blocks already get media addresses through `BlockContext::mediaUrl()`; a media picker for module forms waits for the form contract |
| Rate limits | `Http\RateLimit\RateLimiters` | **Defer** | No module needs it for the tutorial; spam protection on public forms is core's |
| Database | Doctrine DBAL `Connection` in the site container | **Keep (documented service)** | As ADR-0016: modules use the site's `Connection` and ship migrations (`MigrationProvider`); an entity registrar waits for a module that needs mapped entities |
| Logger, clock, HTTP client | PSR-3 logger, PSR-20 clock, shared HTTP client | **Keep (documented services)** | Available from the container under their interface names, documented in the module reference; the HTTP client keeps the SSRF and redirect rules (ADR-0040) |
| Cache | Symfony cache adapters | **Defer** | No need yet |
| `RequestAnswered` | after-response hook with the PSR request | **Keep** | Performance-sensitive and carries the whole request; analytics only |
| `SiteProvisioning`, `PublicPageRendering`, `PublicScreenRendering` | mutable core hooks | **Keep** | Core's own extension points between its modules; modules get `SeoProvider`, `Screens` and migrations instead |
| Block types and permissions are tenant-wide (stage 2 carry-over) | ADR-0034 | **X-1** | No contract change: X-1 hides a module's block types in the editor and its permissions on the roles screen on sites where it is off |

- Ship every **add** above in one additive minor release, `module-interface` `0.5.0`, before core requires it.
  Core then requires `^0.5` in one PR, which also moves the events and implements the new services.
- Check the result with a fixture module in core's tests that has no `SightPress\Core` import in its `src/`.

## Consequences

- The module template and the tutorial (X-3) are written once, against the final 0.5 shape.
- Core loses its own event classes; core modules listen to the contract's events like any external module.
- Deferred items (PHP forms, mailer, media, cache, rate limits) are not reachable without core in 0.5. Each comes
  back with the task named above, or with the first real module that needs it.
