# Site resolution and site containers (C-2.2)

## What it does

Every web request belongs to one site. SightPress finds it from the web address's host name (for example
`www.example.org`), using the domains listed in the site registry (`config/sites.php`). Each site then gets its own
set of services, including its own database connection, so one site can never see another's data (ADR-0003).

## Who uses it

- **Visitors** see a clear page when a site isn't available.
- **Operators** control it through the site's status and domains in the registry.
- **Developers** read the current site from the request:
  `SiteContext::fromRequest($request)` gives the `Site` and its container.

## What visitors see

| Site | Status code | Page |
| --- | --- | --- |
| Enabled | as the page answers | the page |
| Disabled | 503, with `Retry-After: 3600` | "Sorry, the service is unavailable … Try again in 60 minutes." |
| Archived | 410 | "This page has been removed" |
| Deleted, or no site uses the host | 404 | "Page not found" ("No site configured" while the install has no sites) |

Host names are matched without regard to case or a trailing dot. Ports are ignored.

## Databases

A site's database address (DSN) is a URL:

- `sqlite:////srv/data/demo.sqlite` (a file; note the four slashes for an absolute path) or `sqlite:///:memory:`
- `mysql://user:password@host:3306/database` or `mariadb://…`
- `postgresql://user:password@host:5432/database?serverVersion=17`

It is stored encrypted and only decrypted the first time a request needs the database; the connection opens on the
first query. Logs and messages describe a database by engine, host and name only, never the user or password.

## Code and tests

- `src/Http/SiteResolverMiddleware.php`, `src/Tenant/SiteContext.php`, `src/Tenant/SiteContainer.php`,
  `src/Tenant/SiteContainerFactory.php`, `src/Infrastructure/Database/DatabaseDsn.php`
- `tests/Unit/Http/SiteResolverMiddlewareTest.php`, `tests/Unit/Tenant/SiteContainerFactoryTest.php`,
  `tests/Unit/Infrastructure/Database/DatabaseDsnTest.php`, `tests/Integration/Tenant/SiteResolutionTest.php`
