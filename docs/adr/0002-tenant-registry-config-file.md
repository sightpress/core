# 0002 — Tenant registry as a config file

- Status: Accepted
- Date: 2026-11-14

## Context

One SightPress install (a *tenant*, built from `sightpress/skeleton`) runs many sites. Each site has its own
database, storage and users. The tenant needs a small record of its own that says which sites exist, which domains
point at them, their status (`enabled`, `disabled`, `archived`, `deleted`) and how to reach each site's database.

That record is read on every request (host → site), is small (a handful to a few hundred sites), changes rarely
(only when someone adds, moves or changes the status of a site) and must work with nothing but PHP. A database for
it adds a connection, migrations and a backup story for very little data.

## Decision

- Keep the tenant registry in one config file, `config/sites.php`, separate from every site database. It is a
  plain PHP file that returns an array: a format `version` and a `sites` map keyed by site slug. Each site holds its
  name, status, domains, encrypted database DSN, storage config, `default_locale`, `locales`, `default_timezone`
  and `created_at`. The path is set in `config/tenant.php`.
- Read it once per process through a `SiteRepository` in the tenant container. With OPcache the file is compiled
  once, so a request pays no I/O or parsing cost. Validate it on load (known keys, valid statuses, valid IANA
  timezones, every domain lower-case and used by only one site) and fail with a clear message that names the site
  and key, never a value.
- Only CLI commands (`site:*`) write the file; the web process never does and needs no write access to `config/`.
  A write takes an exclusive lock, builds the whole array, exports it with `var_export()` (no hand-built PHP code),
  writes a temp file in the same directory and renames it over the old one, so readers never see half a file. The
  previous file is kept as `config/sites.php.bak`.
- People may also edit the file by hand; the next load validates it. `bin/sightpress site:registry:check` runs the
  same validation without starting the app.
- Store each site's database DSN encrypted (libsodium, key derived from `APP_SECRET`), so the file can be backed up
  or kept in version control without exposing credentials. Never log a DSN or print credentials in CLI output; logs
  name only the host and database.
- Write status changes to the tenant log (channel `sites`: slug, old status, new status, who, when) instead of a
  `site_status_log` table. The site's own audit log records them too while its database is reachable.
- Upgrade the file format with `bin/sightpress site:registry:upgrade` when `version` changes. Core refuses to boot
  on an older version and says which command to run.
- Treat a site's database as reachable from anywhere (local container, dedicated server or managed cloud database).
  Changing it is a CLI-only action that is allowed only while the site is `disabled`, and it never copies data.
- Hide storage behind the `SiteRepository` interface, so a later ADR can add a database-backed registry for very
  large tenants without changing callers.

## Consequences

- A tenant needs no database at all; a new install works with nothing but PHP and the file.
- No registry migrations: `core/migrations-tenant/` is not needed, and the format is upgraded by a CLI command.
- Site databases can use any engine Doctrine DBAL supports (SQLite, MySQL, MariaDB, PostgreSQL); integration tests
  use a temporary registry file and SQLite in memory for sites.
- With `opcache.validate_timestamps=0` (common in production), PHP-FPM keeps the old registry until it is reloaded.
  The deploy docs must say to reload PHP-FPM (or call the reset hook) after any `site:*` command that changes the
  registry; the command prints a reminder.
- Installs with several app servers must share the file (deploy it, or put `config/` on shared storage). Changes made
  by a CLI command on one server are not seen by the others until the file is copied.
- The whole registry is loaded on every request. That is fine for hundreds of sites; for thousands, a
  database-backed `SiteRepository` will be needed (new ADR).
- There is no status history query; it lives in the logs. Log retention decides how far back it goes.
- Losing `APP_SECRET` means site DSNs can't be decrypted. The deploy docs must say to back it up, and rotating it
  needs a re-encryption command later.
