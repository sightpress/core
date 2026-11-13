# 0009 — Module architecture

- Status: Proposed
- Date: 2026-11-13

## Context

SightPress has to be extended by third parties without forking core, and core's own features should use the
same extension points, so those points are complete and tested every day. Third-party modules must not depend on
core's internals or on the libraries core uses (HTTP framework, container, ORM), because those may change between
core versions.

## Decision

### Separate contract package

- Put every module contract in `sightpress/module-interface` (namespace `SightPress\Module\`): interfaces and
  readonly value objects only, no implementation. It depends only on `psr/*` packages.
- Version it on its own with strict SemVer (a major release for any BC break), and release it before any core
  release that needs it. Modules declare compatibility with a constraint on `module-interface`, never on core.
- Any change to the contract needs an ADR.

### Core is built from modules

- Every core feature (users, RBAC, pages, blocks, media, themes, menus, SEO, audit, admin …) is a module that
  implements `ModuleInterface`, and registers itself only through the contract. Core has no back door that an
  external module couldn't use.
- The kernel boots only through the module loader.

### Module shape

- `ModuleInterface` has two methods: `manifest(): ModuleManifest` and `register(ModuleRegistrar $registrar): void`.
- `ModuleManifest` holds the module id, its version, the ids and version constraints of the modules it requires,
  and whether it is `required`. (A later minor release may add more fields, for example `enabledByDefault`.)
- `ModuleRegistrar` gives typed sub-registrars: `services()`, `routes()`, `blockTypes()`, `permissions()`,
  `migrations()` (each module has its own migration namespace), `adminMenu()`, `views()`, `subscribers()`,
  `commands()` and `settings()`.
- Registrars are interfaces in the contract package. Core implements them with its container, router, ORM and
  console, so no library type appears in the contract.

### Discovery and dependency resolution

- Core modules come from a static list in core. External modules are found from `extra.sightpress.module` in
  installed packages' `composer.json`.
- The loader validates each manifest, rejects a module whose `module-interface` constraint isn't met, and sorts
  modules topologically by `requires`. A missing dependency, an unmet version constraint or a cycle stops the boot
  with an error that names the modules involved.
- Modules register in that order; a module may use another module's services only if it requires that module and
  the service is declared public.

### Required and optional modules

- Modules marked `required` (for example `user`, `rbac`, `page`, `block`, `admin`) are always on and can't be
  turned off.
- Other modules can be turned on or off per site. A module that is off registers nothing for that site. Turning a
  module off is refused while an enabled module requires it, and its data is kept unless it is deleted separately.

## Consequences

- External modules get exactly the power core modules have; gaps found while building core become contract
  changes (with ADRs) instead of private shortcuts.
- Core can change its libraries without breaking modules, as long as the registrar implementations keep working.
- The contract must stay small and stable; adding to it is cheap (minor release), changing it is expensive (major).
- An architecture test in core will check that no module uses another module's internals.
