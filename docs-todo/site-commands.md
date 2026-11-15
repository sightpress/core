# Creating and managing sites (C-2.3, C-2.4)

## What it does

One install can run many sites. `bin/sightpress site:create` makes a new one: it gets its database ready, creates
the tables, sets up the roles, creates the first admin and prints the link they use to choose their password. The
other `site:*` commands list sites, change their status, update their databases and remove them.

Commands write plain sentences, with no tables or colours, so they read well with a screen reader. A database
address is only ever shown as the engine, host and database name: never with a user name or a password.

## Who uses it

- **Operators** (whoever runs the install).
- **Developers**: a module can set things up on every new site by listening to the `SiteProvisioning` event.

## Create a site

```sh
bin/sightpress site:create demo \
  --domain=demo.example.org,www.demo.example.org \
  --db=sqlite:////srv/sightpress/sites/demo.sqlite \
  --owner-type=organization --locale=en-GB \
  --admin-email=ada@example.org --admin-first-name=Ada --admin-last-name=Lovelace \
  --name="Riverside Library"
```

Leave out any required option and the command asks for it. `APP_SECRET` must be set: it encrypts the database
address in `config/sites.php`.

| Option | Required | What it is |
| --- | --- | --- |
| `slug` (argument) | yes | Short name: lower-case letters, digits and hyphens. |
| `--domain` | yes | Web addresses without `https://`, separated by commas. Each belongs to one site only. |
| `--db` | yes | Database DSN: `sqlite:////path/file.sqlite`, `mysql://…`, `mariadb://…` or `postgresql://user:password@host:5432/name`. |
| `--owner-type` | yes | `person` or `organization`. |
| `--locale` | yes | Main language, for example `en-GB`. |
| `--admin-email`, `--admin-first-name`, `--admin-last-name` | yes | The first admin. |
| `--name` | no | Name shown to visitors (default: the slug). |
| `--timezone` | no | IANA time zone (default: the tenant's `timezone`). |
| `--url` | no | Where the printed links start, for example `http://localhost:8080` in development (default: `https://` and the first domain). |
| `--create-db` | no | Create the database if it doesn't exist. The database user must be allowed to. |

What happens, in order:

1. Everything given is checked. Nothing is created if something is wrong; the message says what.
2. The database is checked. A SQLite file and its folder are created. On MySQL, MariaDB and PostgreSQL the
   database must exist, unless you add `--create-db`.
3. The tables of every module are created (migrations).
4. Each module sets up its part: the first admin's account (300), the roles admin, editor, contributor and member
   with the admin role for the first admin (200), the password link (100). Starter pages and branding come later.
5. Only then is the site added to `config/sites.php`, enabled. If a step fails, the site is not added; the message
   says its database may already have tables.

The first admin's link works once, for 7 days. Their account is pending until they choose a password.

## Change a site's status

| Command | Status | Visitors get |
| --- | --- | --- |
| `site:enable <slug>` | enabled | the site |
| `site:disable <slug>` | disabled | "temporarily unavailable" (503) |
| `site:archive <slug>` | archived | "gone" (410); the data is kept |
| `site:delete <slug>` | deleted | "not found" (404); the data is kept until `site:purge` |

Any status can change to any other; `site:enable` brings back a deleted site. Every change is written to the
`sites` log channel.

## Other commands

| Command | What it does |
| --- | --- |
| `site:list` | One sentence per site: status, domains, language, time zone and database. |
| `site:migrate <slug>` / `site:migrate --all` | Runs the migrations a site doesn't have yet (after an update). `--all` skips deleted sites, and one failure doesn't stop the others. |
| `site:db:change <slug> --db= [--migrate]` | Points a **disabled** site at another database (see below). |
| `site:purge <slug> [--drop-database]` | Removes a **deleted** site from the registry for good. Its database is kept unless you add `--drop-database`. |

## Move a site to another database

`site:db:change` changes only where the site's data is read from. **It copies nothing**: move the data yourself
first (for example with your database's dump and restore tools), or start the site on an empty database.

```sh
bin/sightpress site:disable demo
# copy the data to the new database
bin/sightpress site:db:change demo --db=postgresql://user:password@db.internal:5432/demo
bin/sightpress site:enable demo
```

- The site must be **disabled**. An enabled, archived or deleted site is refused, and the message says to run
  `site:disable` first.
- The new database must exist and be reachable (a SQLite file is created when missing).
- The new database must be up to date with the installed modules. If it is behind, the command stops and says by
  how many migrations; add `--migrate` to run them. A database with migrations no installed module has (from a newer
  version, or a module that isn't installed) is always refused.
- Only then is the new address stored, encrypted, in `config/sites.php`. The change is written to the `sites` log
  channel with the engine, host and database name of both databases, never a user name or a password. A running
  process stops using the old database at once.
- If anything fails, the site keeps its old database, and the message says so.

## For developers: setting up a new site

`site:create` dispatches `SightPress\Core\Site\Contract\SiteProvisioning` after the migrations ran. Listen to it with
`$registrar->subscribers()->listen(SiteProvisioning::class, MyStep::class, $priority)`. Listeners run from the
highest priority down (`ACCOUNTS` 300, `ROLES` 200, `SIGN_IN` 100, `CONTENT` 0); take services from
`$event->services` (the new site's container) and tell the operator what you did with `$event->report()`.

## Code and tests

- `src/Site/Command/`, `src/Site/Contract/SiteProvisioning.php`, `src/Site/Contract/FirstAdmin.php`,
  `src/Tenant/SiteMigrator.php`, `src/Infrastructure/Database/DatabaseProvisioner.php`,
  `src/User/Provisioning/`, `src/Rbac/Provisioning/`, `src/Auth/Provisioning/`, `src/Cli/ModuleCommand.php`
- `tests/Unit/Site/`, `tests/Unit/Infrastructure/Database/DatabaseProvisionerTest.php`,
  `tests/Integration/Site/SiteCreationTest.php`, `tests/Integration/Site/SiteDatabaseChangeTest.php`
