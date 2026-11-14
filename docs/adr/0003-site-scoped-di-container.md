# 0003 — Site-scoped DI container

- Status: Accepted
- Date: 2026-11-13

## Context

Every request belongs to exactly one site, found from the `Host` header. Each site has its own database
connection, entity manager, file storage, mailer settings, theme, locales and enabled modules. A service that leaks
from one site into another (a cached entity manager, a Twig loader with the wrong theme paths) would be a data leak.

We use PHP-DI as the container (ADR-0013). We need a clear split between services that are the same for the whole
tenant and services that belong to one site.

## Decision

- Build two levels of container:
  - The **tenant container**, built once per process from tenant config: kernel, logger, site registry (ADR-0002),
    module loader, console application, encryption service.
  - A **site container**, built per site by a `SiteContainerFactory` once the site is known. It holds the site's
    `SiteContext`, entity manager, filesystem, mailer, Twig environment (theme loader chain), translator, RBAC and
    every service the site's enabled modules register.
- Resolve the site in `SiteResolverMiddleware`: unknown host → 404, `disabled` → 503 with `Retry-After`,
  `archived` → 410, `deleted` → 404. Only an `enabled` site gets a container.
- Create site services lazily, so a request that doesn't touch the database never opens a connection.
- Cache the compiled container definition per site and module set. Evict a site's cached container when its
  status, database, theme or module switches change.
- Never let site-level services into the tenant container, and never keep a site container alive across requests
  in long-running workers without resetting it.
- CLI commands that act on a site (`site:*`) build that site's container the same way as HTTP requests.

## Consequences

- Site isolation is structural: code inside a site container can only see that site's connection and storage.
- Modules register services once; core decides at which level they live (site level by default).
- Tests can build a site container against SQLite in memory and check that two sites stay apart.
- Building a container per site adds cost on the first request; the compiled-definition cache keeps it low.
- Long-running runtimes (workers, FrankenPHP worker mode) need a reset step; this is left for a later ADR.
