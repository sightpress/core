# 0034 — Per-site module switches: one registry for the tenant, a filtered view per site

- Status: Proposed
- Date: 2026-11-18

## Context

ADR-0009 says optional modules are turned on or off per site, and that a module that is off "registers nothing" for
that site. Until F-2 the kernel resolved one module selection for the whole tenant at boot, so every site had the same
modules. F-2 adds the *Features* screen, `site:module:*` and `site:create --without`, and the tenant's
`modules.defaults` and `modules.locked` in `config/tenant.php`. One kernel serves every site of the tenant, so the
modules can't be chosen once per process.

## Decision

- **The kernel registers every installed module** except those the tenant locked off (and the optional modules that
  need one of them). `ModulePolicy` checks `modules.defaults` / `modules.locked` at boot: unknown or required module
  ids, or a module locked on while a module it needs is locked off, stop the boot with a message naming them.
- **Each site's choices** are in its own database, table `site_module` (module id, `enabled`, `changed_at`,
  `changed_by`), created by the new required `features` module. A module's state for a site is: the tenant's lock,
  else the site's row, else `modules.defaults`, else the manifest's `enabledByDefault`. Required modules are always on;
  a module whose required modules are off is off too. `site:create` stores a row for every optional module, so a later
  change of the tenant's defaults doesn't change existing sites.
- **Each site container gets its own `ModuleRegistry`**: the tenant's registry filtered to the modules that are on
  (`ModuleRegistry::only()`), read once per container from `site_module`. Everything built from it in the site
  container follows: the event listeners (so subscribers of a module that is off never run, which also drops its SEO
  contributions and its provisioning steps), the admin navigation, the settings catalogue, the migrations the site runs
  (`SiteMigrator`) and `module_enabled('<id>')` in templates. Routes are installed once; a route of an **optional**
  module checks the site's registry and answers 404 when its module is off (`ModuleGate`), so the routes of required
  modules never read `site_module`. A change evicts the site's container, so the next request sees it.
- **Services, commands, permissions and block types stay tenant-wide.** A module's service definitions are still in
  every site container, but nothing reaches them when the module is off (no route, listener or menu entry leads there,
  and another module may only use them if it requires that module, which is then off too). Commands take a site and
  are the tenant's; permissions stay in the roles screen so grants survive off → on; block types stay so pages that
  hold a block of a module that is off still render.
- **The switches are shared code, not a module** (`Tenant\SiteModules`), because modules may not use the module loader
  (ADR-0009). The `features` module only adds the table, the `module.manage` permission (admins), the screen and the
  commands. `SiteModuleChanged` is dispatched by the site's dispatcher: the audit log records it and the roles module
  gives the admin role the permissions of a module that was turned on.
- **Turning on** runs the module's pending migrations first; refused while a module it needs is off. **Turning off**
  is refused while a module that is on needs it, and keeps the data. **Deleting the data** (admin screen, or
  `--purge-data`) is only possible while the module is off: it runs the `down()` of the module's migrations that ran,
  newest first, so turning it on again starts afresh. Locked modules can't be changed from the admin or the CLI.
- **Names and descriptions on the *Features* screen** come from the package's `composer.json`
  (`extra.sightpress.features.<module id>`: `name`, `description`, `when-off`); without them the module id is shown.
  The module contract doesn't change (no `module-interface` release needed).

## Consequences

- Every routed request to an optional module's route, and every page whose template calls `module_enabled()`, reads
  `site_module` once per site container. Sites without optional modules never do.
- A module installed after a site was created follows the tenant's default for that site until an admin chooses.
- A module that wants to delete files on purge has no hook yet; its migrations' `down()` is all that runs. A
  `ModuleDataPurged` event can be added when a module needs it.
- A future `module-interface` minor can add a `name()` / `description()` to the contract; `ModuleAbout` would then
  read them first.
