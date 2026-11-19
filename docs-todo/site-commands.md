# Creating and managing sites (C-2.3, C-2.4, C-8.1)

## What it does

One install can run many sites. `bin/sightpress site:create` makes a new one: it gets its database ready, creates
the tables, sets up the roles, creates the first admin and prints the setup code they use to choose their password. The
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

`APP_SECRET` must be set: it encrypts the database address in `config/sites.php`.

### Guided questions

Type just `bin/sightpress site:create` at a terminal and the command asks for everything, one question at a time:

- each question says what to type, the choices (such as `sqlite, mysql, mariadb or postgresql`) and the default
  that Enter takes;
- the database is asked piece by piece: engine (default SQLite), then the SQLite file (default
  `sites/<slug>.sqlite`), or the server (`localhost`), port (3306 or 5432), database (`sightpress_<slug>`), user
  (`sightpress`) and password (not shown as you type, and typed twice to check you typed what you meant);
- the main language can be typed in any case, with `-` or `_` (`pt_br`, `EN-gb`), and is read back in plain words
  for you to confirm: "That is Brazilian Portuguese (pt-BR). Is that right?";
- an answer that is not accepted (such as an email address without a domain) is explained and asked again, up to 3
  times; then the command stops and creates nothing;
- at the end, every answer is listed (the password is not shown) and you confirm it. Answer `no` to start again
  from the first question: your answers so far are the defaults, so Enter keeps them.

When the command line already has every required value and they are all right, nothing is asked, so scripts keep
working. With `--no-interaction`, or without a terminal, nothing is ever asked: defaults apply and a wrong value
stops the command with the same explanation.

Every command asks this way: a module declares its questions with `CommandParameter` (`label`, `default`,
`choices`, `normalise`, `describe`, `when`, `secret`, `ask`; see ADR-0021).

| Option | Required | What it is |
| --- | --- | --- |
| `slug` (argument) | yes | Short name: lower-case letters, digits and hyphens. |
| `--domain` | yes | Web addresses without `https://`, separated by commas. Each belongs to one site only. |
| `--db` | no | Whole database DSN: `sqlite:////path/file.sqlite`, `mysql://…`, `mariadb://…` or `postgresql://user:password@host:5432/name`. Given, it replaces the pieces below. |
| `--db-engine` | without `--db` | `sqlite` (default), `mysql`, `mariadb` or `postgresql`. |
| `--db-path` | for SQLite | The file (default: `<sites folder>/<slug>.sqlite`); a relative path is inside the sites folder. |
| `--db-host`, `--db-port`, `--db-name`, `--db-user` | for a server | Defaults: `localhost`, 3306 or 5432, `sightpress_<slug>`, `sightpress`. |
| `--db-password` | no | Password of the database user. |
| `--owner-type` | yes | `person` or `organization`, in any case. |
| `--locale` | yes | Main language, for example `en-GB` (the default when asked); any case, `-` or `_`. |
| `--admin-email`, `--admin-first-name`, `--admin-last-name` | yes | The first admin. |
| `--name` | no | Name shown to visitors (default: the slug). |
| `--timezone` | no | IANA time zone, in any case (default: the tenant's `timezone`). |
| `--url` | no | Address of the site, for the printed setup page, for example `http://localhost:8080` in development (default: `https://` and the first domain). |
| `--create-db` | no | Create the database if it doesn't exist. The database user must be allowed to. Asked for server engines (default yes). |
| `--with-demo-content` | no | Add demo content for trying SightPress out (see below). Don't use it for a real site. |

`site:db:change <slug>` takes the same `--db` or database pieces.

What happens, in order:

1. Everything given is checked. Nothing is created if something is wrong; the message says what.
2. The database is checked. A SQLite file and its folder are created. On MySQL, MariaDB and PostgreSQL the
   database must exist, unless you add `--create-db`.
3. The tables of every module are created (migrations).
4. Each module sets up its part: the first admin's account (300), the roles admin, editor, contributor and member
   with the admin role for the first admin (200), the setup code (100). Starter pages and branding come later.
5. Only then is the site added to `config/sites.php`, enabled. If a step fails, the site is not added; the message
   says its database may already have tables.

The command prints, for example: "Give the setup code 123 456 to ada@example.org. They go to
https://demo.example.org/login/setup, type their email address and the code, and choose their password. The code
works once, for 7 days." Pass it on by a channel you trust (in person, by phone). The account is pending until they
choose a password. Nothing is emailed: SightPress never sends links.

## Demo content

`--with-demo-content` fills the new site so you can try everything out (and so the accessibility tests have something
to check). After the starter pages and menus it adds:

1. Five files in the media library, described and published: a picture with alt text, a short video with captions and
   a transcript, a recording with a transcript, and a one-page PDF. They are made for SightPress, with no
   third-party material (`resources/demo/`).
2. One account for each other role: Bea Okafor (editor, `bea@example.org`), Cleo Martins (contributor,
   `cleo@example.org`) and Max Müller (member, `max@example.org`). Each waits for its setup code, which the command
   prints like the first admin's.
3. A published page "Every kind of block" at `/every-block`, with one block of every kind core has, and a published
   page under it (`/every-block/under`) written by Cleo.
4. A published page "For members" (`/for-members`) that only signed-in people can read, a page "Old news" in the
   trash (`/old-news` answers "gone") and a redirect from `/visit` to `/every-block`.
5. On a site in several languages (`--locale=en-GB,pt-PT,he`), these pages copied into every other language and
   published there, as "Translate from" starts them (the text is still to translate).
6. The demo pages and "For members" in the main menu (the page under it one level down); "For members" shows only to
   people who are signed in.

The `example.org` addresses can't receive email; give the printed codes to whoever tries the site out.

## Another admin, or a new setup code

```sh
bin/sightpress site:user:create-admin demo --email=grace@example.org --first-name=Grace --last-name=Hopper
```

Creates a pending admin and prints their setup code. Run it again for an admin who hasn't chosen a password yet to
get a new code (the old one stops working). An account that can already sign in is refused: that person uses
"Forgot your password?" instead.

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
highest priority down (`ACCOUNTS` 300, `ROLES` 200, `SIGN_IN` 100, `CONTENT` 0, `DEMO` -100 and below); take
services from `$event->services` (the new site's container) and tell the operator what you did with `$event->report()`.

Demo steps only act when `$event->demo` is true (`--with-demo-content`). A step can pass the id of something it made to
later steps with `$event->share('demo.picture', $id)`; they read it with `$event->shared('demo.picture')` (`null` when
nothing was shared under that name).

## Code and tests

- `src/Site/Command/`, `src/Site/Contract/SiteProvisioning.php`, `src/Site/Contract/FirstAdmin.php`,
  `src/Tenant/SiteMigrator.php`, `src/Infrastructure/Database/DatabaseProvisioner.php`,
  `src/User/Provisioning/`, `src/Rbac/Provisioning/`, `src/Auth/Provisioning/`, `src/Cli/ModuleCommand.php`
- Demo content: `src/Media/Admin/Provisioning/DemoMedia.php`, `src/Auth/Provisioning/DemoAccounts.php`,
  `src/Page/Provisioning/DemoPages.php`, `src/Menu/Provisioning/DemoMenu.php`, `resources/demo/`
- `tests/Unit/Site/`, `tests/Unit/Infrastructure/Database/DatabaseProvisionerTest.php`,
  `tests/Integration/Site/SiteCreationTest.php`, `tests/Integration/Site/SiteDatabaseChangeTest.php`,
  `tests/Unit/Site/DemoContentTest.php`; the accessibility crawl `tests/A11y/crawl.spec.ts` runs on a demo site
  (`tests/A11y/demo/`)
