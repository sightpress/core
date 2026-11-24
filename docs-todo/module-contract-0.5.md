# What an external module can use (module-interface 0.5)

## What it does

Core implements every `module-interface` 0.5 contract (X-2, MI-0.5; ADR-0057 … ADR-0064), so a module written only
against `sightpress/module-interface` (no `SightPress\Core` import) can add admin screens and public pages, check
access, read settings, write the audit log, list its pages for search engines, run scheduled jobs, translate its
text and listen to core's events. `tests/Fixtures/modules/acme-events` is such a module and
`tests/Integration/Module/ExternalModuleContractTest.php` runs it.

## Who uses it

Developers who write modules. Nothing changes for site admins.

## Services in the site container

Ask for them in a handler's or listener's constructor; core builds module classes from the site's container.

| Type | What |
|---|---|
| `SightPress\Module\Http\Screens` | `admin()`, `public()`, `redirect()` (ADR-0059) |
| `SightPress\Module\Setting\SettingValues` | the site's settings, typed, with defaults |
| `SightPress\Module\Audit\AuditTrail` | the site's audit log |
| `SightPress\Module\Site\SiteInfo` | id, slug, name, languages |
| `Doctrine\DBAL\Connection` | the site's database, for the module's own tables (ADR-0016) |
| `Psr\Log\LoggerInterface`, `Psr\Clock\ClockInterface` | the log and the clock |
| `Psr\Http\Message\ResponseFactoryInterface`, `StreamFactoryInterface` | PSR-17 factories, for responses that aren't pages (Markdown, CSV) |
| `Psr\EventDispatcher\EventDispatcherInterface` | the site's dispatcher, for a module's own events |
| `Symfony\Contracts\HttpClient\HttpClientInterface` | the shared HTTP client (SSRF and redirect rules, ADR-0040); the module requires `symfony/http-client-contracts` itself |

`Access\Viewer::fromRequest($request)` gives the signed-in account and its permissions.

## Routes

- `permission: 'events.manage'`: core checks it first. Signed out → `303` to `/login?return=…`; under `/admin`
  without `admin.access` → the "no admin access" page; without the permission → `403`.
  (`Http\RoutePermissionMiddleware`, added by `Http\RouteInstaller`.)
- `critical: true` is stored and listed; two-step confirmation (N-6) acts on it.

## Templates (core's template API)

- Admin screen templates draw only what goes under the `<h1>`: core wraps them in `@admin/module-screen.html.twig`.
- Public page templates draw the main content, starting with their `<h1>`: core wraps them in
  `@page/module-screen.html.twig`, inside the theme's `master.html.twig`, and builds the head from the `SeoEntry`
  (description, canonical, `hreflang` for the site's languages, Markdown link, JSON-LD `@graph` with a `WebPage` node
  and the module's nodes). Errors (`4xx`) without an entry are `noindex`.
- Functions: `t(text, parameters, domain)`, `can(permission)`, `csrf_fields()` (every `POST` form),
  `current_locale()`, `current_path()`, `module_enabled(id)`, `asset(path)`; filters `user_date`, `user_datetime`.
  Core's form macros: `{% import 'form/macros.html.twig' as f %}`.
- Variables are only what the module passes: the module template is included `with … only`.

## Translations

`translations()->add($folder)`: `<domain>.<locale>.xlf` files, en-GB required, `messages` refused. They join core's
catalogue for the whole tenant; translate with `Text\Message(…, domain: 'acme_events')` or
`t('…', {}, 'acme_events')`.

## Scheduled jobs

`schedule:run` runs each module's `ScheduledJob` for every site where the module is on, after core's own jobs, built
from the site's container (`Infrastructure\Schedule\ModuleJobs`). A failing job is printed on stderr with its module,
the run exits with failure, and the other jobs and sites still run.

## Registered, used later

- `seo()` providers: kept in the registry; the sitemap, `llms.txt` and the cache purge use them from X-1.
- `sms()` providers: kept; N-2 lists them on the Text messages settings.
- `blockTypes()->addVariant()` / `addIcon()` and `StyledBlockType`: kept; BL-1 draws and edits them.

## Events

Core dispatches the classes in `SightPress\Module\Event` (ADR-0058). Core's own `src/*/Contract/` event classes are
gone; their enums are sent as strings (`PageTransitioned::$transition`, `MediaUploaded::$kind`, `MenuChanged::$menu`).

## Code and tests

- `src/Admin/ModuleScreens.php`, `src/Audit/ModuleAuditTrail.php`, `src/Infrastructure/Settings/SiteSettingValues.php`,
  `src/Infrastructure/Schedule/ModuleJobs.php`, `src/Http/RoutePermissionMiddleware.php`,
  `src/Module/Registrar/{Seo,Sms,Schedule,Translation}Collector.php`, `src/Tenant/SiteContainerFactory.php`
- `tests/Integration/Module/ExternalModuleContractTest.php` and the unit tests next to each class
