# 0081 — Shared sessions: the database by default, Redis supported

- Status: Proposed
- Date: 2026-11-27

## Context

Plan §8 H-9 and D38. Sessions were files on the web server (`FileSessionStore`), so a site behind two or more web
servers signed people out, lost CSRF tokens and flash messages, and broke the two-step window whenever the proxy sent
a request to another server. `1.0.0` must work behind several servers without sticky sessions, and the privacy work
(GD-2, GD-4) needs to know where every session is kept. Stage-5 gap 6 chose the Redis client.

## Decision

- **One contract, three stores.** `Http\Session\SessionStore` (`read`, `write`, `touch`, `delete`) is public API
  (H-7). Stores that need expired sessions removed also implement `ExpiringSessionStore::forgetExpired()`.
  `tests/Integration/Http/Session/SessionStoreContract.php` is the contract suite; core runs it against every store
  and a store of your own can extend it.
- **`session.store`** in `config/tenant.php`: `database` (default), `redis`, `files`, or the name of a class
  implementing `SessionStore`, built from the site's container (so it can ask for the site's `Connection` or `Site`).
  Checked when SightPress starts: an unknown value, a class that isn't a store, `redis` without PHP's redis extension
  or without `SESSION_REDIS_DSN` stop it with a message that says what to do.
- **Per site.** Each site container has its own `SessionStore`; `SessionMiddleware` uses the store of the request's
  site, and the tenant's file store only for requests that belong to no site (they store nothing).
- **Database** (`DatabaseSessionStore`): the `session` table, a site migration of the always-on site module, so every
  site has it. Columns `id_hash` (SHA-256 of the id, primary key), `data` (JSON), `last_seen_at`, `expires_at`
  (indexed). A session past `expires_at` is refused at once; `schedule:run` deletes it (`ForgetExpiredSessions`). A
  request that changed nothing only writes when `last_seen_at` is over a minute old. Writes update first and insert
  when there is no row; a duplicate key from a parallel request (or MySQL counting an unchanged row as not updated)
  updates again. DBAL QueryBuilder only, so it runs on every supported engine.
- **Redis** (`RedisSessionStore`, gap 6): PHP's redis extension, a Composer `suggest`, not a requirement.
  `SESSION_REDIS_DSN` = `redis://[user:password@]host[:port][/db]`, `rediss://` for TLS or `unix:///path`. Keys are
  `sightpress:session:<site id>:<SHA-256 of the id>`, so sites sharing a server never meet and no raw id is a key. TTL =
  idle timeout, restarted on every request; Redis ends sessions itself. The connection opens on first use; a server
  that can't be reached is a `SessionStoreException` naming the server (never the password), and `system:check`
  reports it.
- **Files** stay for one host (`session.path`), now also cleaned by `schedule:run`, and touching an expired file no
  longer brings it back.
- **Switching** stores signs everyone out once: the new store doesn't know the old ids, so the cookie is cleared and a
  fresh session starts. Nothing is migrated.

## Consequences

- A site works behind several web servers with no sticky sessions, with `database` and no new service.
- Every request of a signed-in person reads one row; at most one write a minute when nothing changed.
- Upgrading needs `site:migrate` before serving requests (the `session` table), as for any release with migrations.
- Erasure (GD-4) deletes a person's sessions in the store of each site. Finding them by person is GD-4's to add.
- The privacy provider declaring the session cookie and table waits for the privacy contract (MI-0.6, ADR-0077).
