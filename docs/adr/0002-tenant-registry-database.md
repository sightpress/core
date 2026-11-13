# 0002 — Tenant registry database

- Status: Proposed
- Date: 2026-11-13

## Context

One SightPress install (a *tenant*, built from `sightpress/skeleton`) runs many sites. Each site has its own
database, storage and users. The tenant needs a small store of its own that says which sites exist, which domains
point at them, their status (`enabled`, `disabled`, `archived`, `deleted`) and how to reach each site's database.

That store is read on every request (host → site), is small, and must work with no extra server, so a first
install can run with nothing but PHP. Larger installs may want it on the same server as everything else.

## Decision

- Keep the tenant registry in its own database, separate from every site database. It holds the tables `sites`,
  `site_domains` and `site_status_log`.
- Use a SQLite file in the skeleton by default (`var/` path set in `config/tenant.php`).
- Allow any database that Doctrine DBAL supports (SQLite, MySQL, MariaDB, PostgreSQL) by changing the registry DSN
  in tenant config. No custom SQL: only the DBAL QueryBuilder, the Schema API and migrations.
- Keep registry migrations in `core/migrations-tenant/`, apart from site migrations.
- Store each site's database DSN encrypted (libsodium, key derived from `APP_SECRET`). Never log it or print
  credentials in CLI output; logs name only the host and database.
- Treat a site's database as reachable from anywhere (local container, dedicated server or managed cloud database).
  Changing it is a CLI-only action that is allowed only while the site is `disabled`, and it never copies data.

## Consequences

- A new install works with no database server at all.
- The registry and site databases can be on different engines; integration tests use SQLite in memory for both.
- Losing `APP_SECRET` means site DSNs can't be decrypted. The deploy docs must say to back it up, and rotating it
  needs a re-encryption command later.
- SQLite handles concurrent writes poorly, but the registry is written rarely (site admin actions only), so this is
  acceptable. Busy tenants can move the registry to a server database.
