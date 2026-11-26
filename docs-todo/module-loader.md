# Module loader (C-1.5)

## What it does

Everything SightPress does beyond starting up and showing error pages comes from **modules**: core's own features
(users, roles, pages, blocks, media …) and modules installed by the site owner. When SightPress starts, the module
loader finds every module, checks it, decides which ones are on, puts them in order and lets each one register what
it adds: services, web addresses (routes), templates, database migrations, event listeners, console commands,
permissions, settings, block types, admin menu entries and, since `module-interface` 0.4, site search content (or
a replacement search back-end), dashboard widgets and AI assistant providers and tasks.

## Who uses it

- **Operators** install modules with Composer and turn optional ones on or off per site (from M2 in the admin).
- **Module authors** describe their module in a manifest and register through the `sightpress/module-interface`
  contracts.

## Installing a module

A module is a Composer package that requires `sightpress/module-interface` and names its module class in
`composer.json`:

```json
{
    "require": {
        "sightpress/module-interface": "^0.5"
    },
    "extra": {
        "sightpress": {
            "module": "Acme\\Events\\EventsModule"
        }
    }
}
```

`module` can also be a list of class names. The class must implement `SightPress\Module\ModuleInterface` and be
creatable without arguments.

An optional module installed after a site was made starts **off** on that site; an admin turns it on (Features or
`site:module:enable`), which runs its migrations and seeds its permissions. Sites created afterwards store its default.

## Rules the loader checks

A problem with one of **core's own** modules stops SightPress from starting, with a message that names the modules
involved and what to do: that is a broken install.

A problem with a module **installed as a package** (an external module) never stops SightPress (X-1, ADR-0065). The
module is left out of every site as **unavailable**, together with the modules that need it, and the reason is shown
on the *Features* screen ("Features that can’t be used"), by `site:module:list`, by `system:check` (which then exits
with 1) and once in the log (again only when the list changes, or after `cache:clear`). Everything else keeps
working.

| Problem | Reason (example) |
| --- | --- |
| `extra.sightpress.module` isn't a class name or a list | "extra.sightpress.module" must be a module class name or a list of them. |
| The package doesn't require the contract | It declares a SightPress module but does not require sightpress/module-interface. |
| The class doesn't exist, isn't a module or needs arguments | The module class Acme\Events\EventsModule does not exist. Check "extra.sightpress.module" and the package's autoload. |
| The class fails while being created, or its manifest is invalid | The module … from acme/events has an invalid manifest: … |
| The module needs another contract version | Module "acme.events" from acme/events works with sightpress/module-interface ^0.1, but 0.5.0 is installed. |
| Two modules use the same id | Two modules use the id "acme.events": one from acme/events and one from other/events. Remove one of them. |
| A module it needs is missing, unavailable or the wrong version | It needs module "acme.calendar" ^2.0, but version 1.4.0 is installed. |
| Modules need each other in a loop | It needs modules that need it in turn, in a loop, so none of them can start. |
| It fails while registering (an exception, an invalid value, a clash) | It could not register: Route "events.list" is registered twice … |

What an external module registered before it failed is dropped with it. On a development checkout of
`sightpress/module-interface` without a version alias (`dev-<branch>`) the contract version isn't compared.

Packages that declare a module are read from `vendor/composer/installed.json` and kept in the cache folder
(`modules.php`) until that file changes (`composer require` / `remove` / `update`) or `cache:clear` empties the folder.
In debug mode nothing is cached.

Modules start in dependency order: a module always registers after the modules it requires. Otherwise the
order is the order they were found (core's first).

## Turning modules on and off

- **Required** modules (core's `user`, `page`, `admin` …) are always on and can't be turned off.
- A module can't be turned off while another module that is on requires it: turn that one off first.
- A module can't be turned on until the modules it requires are on.
- A module that is off registers nothing: its pages answer "Page not found" and its commands are not listed.
- On a site where an optional module is off, its block types aren't in the editor and its blocks aren't shown; a page
  that still holds one can't be saved until the block is removed or the module turned on again.
- Console commands: when someone runs a command at a terminal and leaves out a required argument or option, the
  console asks for it, using the parameter's description as the question (so write descriptions that read as a
  question or a prompt, ending with ":" or "?"). With `--no-interaction`, or when input doesn't come from a
  terminal (scripts, cron), it never asks and a missing required option stops the command.

## SEO

A module lists its public pages with `seo()` (`SeoProvider`, ADR-0060). They are added after core's pages to the
sitemap (with their other languages as alternates) and to `llms.txt` (linked to their Markdown version when they have
one). Nothing is added on installs that aren't in production, on members-only sites, or on sites where the module is
off. The files are rebuilt when a module is turned on or off and, because core can't see a module's content change,
at least every hour while a module adds pages (`SeoFiles::MODULE_CACHE_SECONDS`). A provider that fails is left out
and logged; the rest of the file is still served. `llms-full.txt` keeps core's pages only.

## Removing a module, and rolling back

- **Turning it off for a site** (Features screen or `site:module:disable`) keeps its tables and data; turning it on
  again brings everything back.
- **Deleting its data** (only while it is off) undoes its migrations, newest first.
- **Uninstalling the package** (`composer remove`) while sites have it on: those sites keep working without it, its
  data stays, and the *Features* screen and `site:module:list` say "turned on for this site but no longer installed".
  Reinstalling the package brings it back on with its data. To get rid of the data, reinstall it, turn it off and
  delete its data, then remove the package.
- A `modules.defaults` or `modules.locked` entry in `config/tenant.php` for a module that isn't installed (or is
  unavailable) is ignored, and `system:check` reports it as an error until the entry is removed or the package is
  installed again.

Core's own modules go through the same code (turning off, data deletion), but a problem with one of them still stops
SightPress from starting.

## Services between modules

A module's services are private unless it registers them as public. A module may use another module's public
services only if its manifest requires that module; otherwise the service factory gets a clear error.

## For core developers

Core modules may use another core module only through that module's `Contract` namespace
(`SightPress\Core\<Context>\Contract\`), and never the module loader itself. `composer phpstan` enforces this.

## Code and tests

- `src/Module/` (discovery, loader, catalogue, registry), `src/Module/Registrar/` (registrar implementations),
  `src/Http/RouteInstaller.php`, `src/Cli/`, `src/Infrastructure/Event/`, `src/Infrastructure/Migration/`
- `tests/Unit/Module/`, `tests/Integration/Module/ModuleMigrationsTest.php`,
  `tests/PHPStan/ModuleBoundaryRule.php` + `tests/Integration/PHPStan/ModuleBoundaryRuleTest.php`
