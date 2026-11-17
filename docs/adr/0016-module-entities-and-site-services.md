# 0016 — Module services live in the site container; core modules map entities from a core list

- Status: Accepted
- Date: 2026-11-14

## Context

The user and RBAC modules (C-3.1) are the first modules with database tables and services that read them. Two
gaps showed up:

1. Services that modules register were only defined in the tenant container. A repository that needs the
   site's database (`EntityManagerInterface`, `Connection`) couldn't be built there, although ADR-0003 says the
   site container holds every service the site's modules register.
2. The site's entity manager maps entities from a list of folders, but the module contract (ADR-0009) has no way
   for a module to add a folder. Adding one would mean a contract change (`module-interface` 0.3) and it would tie
   external modules to Doctrine's mapping attributes, which ADR-0009 wants to keep out of modules.

## Decision

- Every site container defines the module services again, so each site gets its own instances, built against
  its own database. The tenant container keeps them too (for console commands that don't touch a site). A module
  may not register a service with the id of a site service (`Site`, `Connection`, `EntityManagerInterface`,
  `EntityManager`), just as it may not replace a core service.
- Every site container also has its own event dispatcher and listener provider. Events that site services dispatch
  (for example a page moving through the workflow) reach listeners built from the site container, so a listener
  can use the site's database. Events dispatched in the tenant container (site provisioning) keep passing the site
  container in the event, as before.
- Core's own modules map their entities with Doctrine attributes. Their entity folders are listed by
  `CoreModules::entityPaths()`, next to the list of core modules.
- External modules use the site's `Connection` (DBAL query builder and schema API) for their own tables. If one
  needs mapped entities, we add an entity registrar to the contract with its own ADR, and core's modules move to
  it.
- Tables of different modules have no foreign keys between them (for example `user_role.user_id` → `users`):
  migrations of different modules run in namespace order, not dependency order. Modules refer to each other's
  rows by id.

## Consequences

- Site services are isolated per site, as ADR-0003 requires, with no new contract.
- The entity folder list is a core-only shortcut that ADR-0009 otherwise rules out. It is limited to mapping
  (no behaviour), written down here, and replaced by a registrar when an external module needs it.
- Without cross-module foreign keys, the module that owns a row must clean up rows that point to it when it is
  deleted (by event), once deleting is possible.
