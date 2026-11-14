# Module loader (C-1.5)

## What it does

Everything SightPress does beyond starting up and showing error pages comes from **modules**: core's own features
(users, roles, pages, blocks, media …) and modules installed by the site owner. When SightPress starts, the module
loader finds every module, checks it, decides which ones are on, puts them in order and lets each one register what
it adds: services, web addresses (routes), templates, database migrations, event listeners, console commands,
permissions, settings, block types and admin menu entries.

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
        "sightpress/module-interface": "^0.2"
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

## Rules the loader checks

Each problem stops SightPress from starting, with a message that names the modules involved and what to do:

| Problem | Message (example) |
| --- | --- |
| Two modules use the same id | Two modules use the id "acme.events": one from acme/events and one from other/events. |
| The module needs another contract version | Module "acme.events" from acme/events works with sightpress/module-interface ^0.1, but 0.2.0 is installed. |
| A required module is missing | Module "acme.events" requires module "calendar", which is not installed. |
| A required module has the wrong version | Module "page" requires module "user" ^2.0, but version 1.4.0 is installed. |
| A required module is off | Module "gallery" requires module "media", which is off. |
| Modules require each other in a loop | These modules require each other in a loop, so none of them can start: a → b → c → a. |
| Two modules register the same thing | Route "events.list" is registered twice: by module "acme.events" and by module "other.events". |

Modules start in dependency order: a module always registers after the modules it requires. Otherwise the
order is the order they were found (core's first).

## Turning modules on and off

- **Required** modules (core's `user`, `page`, `admin` …) are always on and can't be turned off.
- A module can't be turned off while another module that is on requires it: turn that one off first.
- A module can't be turned on until the modules it requires are on.
- A module that is off registers nothing: its pages answer "Page not found" and its commands are not listed.

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
