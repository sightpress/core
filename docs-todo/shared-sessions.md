# Shared sessions (H-9)

**What:** where SightPress keeps sessions (who is signed in, form tokens, messages for the next page, the two-step
window). Each site's own database by default, so a site can run behind several web servers with no sticky sessions;
Redis is supported; files stay for a single server.

**Who:** operators (whoever runs SightPress). Site admins and visitors see nothing, apart from being signed out once
when the store changes.

**Settings** (`config/tenant.php`, `session`):

- `store`: `database` (default), `redis`, `files`, or a class implementing `SightPress\Core\Http\Session\SessionStore`
  (public API; built from the site's container). Checked at start: a wrong value stops SightPress with a message.
- `idle_timeout`: seconds without a request before a session ends (default 7200, at least 60).
- `path`: the folder for `files` (default `var/sessions`).
- `SESSION_REDIS_DSN` (environment): `redis://[user:password@]host[:port][/db]`, `rediss://…` (TLS) or
  `unix:///path/to/redis.sock`. Needs PHP's redis extension (`pecl install redis`).

**How each store works:**

- `database`: the `session` table (added by `site:migrate`). Only a SHA-256 hash of the session id is stored; an ended
  session is refused at once and removed by `schedule:run`. A request that changed nothing writes at most once a
  minute.
- `redis`: keys `sightpress:session:<site id>:<hash>`, which Redis removes after the idle timeout. Several sites can
  share one server.
- `files`: one JSON file per session (named by the hash) in `session.path`; one server only.

**Commands:** `system:check` prints where sign-ins are kept and fails when the Redis server doesn't answer.
`schedule:run` removes ended sessions (database, files).

**Switching store** signs everyone out once (UPGRADE.md). Nothing to migrate.

**Skeleton:** `config/tenant.php` sets `database`; `docker compose --profile redis up` starts a Redis server
(`SESSION_REDIS_DSN=redis://redis:6379`); `docker-compose.multi.yml` runs two PHP servers behind nginx for testing.

**Non-dev how-to:** `docs/how-to/choose-where-sign-ins-are-kept.md`.

**Code:** `src/Http/Session/` (`SessionStore`, `DatabaseSessionStore`, `RedisSessionStore`, `FileSessionStore`,
`SessionStores`, `SessionStoreCheck`), `src/Tenant/RedisServer.php`, `src/Site/ForgetExpiredSessions.php`,
`src/Site/Migrations/Version20261127100000.php`; ADR-0081.
**Tests:** `tests/Integration/Http/Session/` (contract suite against all three stores, two-server flow),
`tests/Unit/Http/Session/`.

**Still to do:** the privacy provider declaring the session cookie and table (needs the MI-0.6 privacy contract).
