# Database portability (C-2.5)

## What it does

A site's database can be SQLite, MySQL, MariaDB or PostgreSQL. Core never writes SQL by hand, but some problems
only show on a real server, so a CI job (`DB matrix`) runs the integration suite, including every module's
migrations, against MySQL 8.4, MariaDB 11.8 and PostgreSQL 18 from the skeleton's compose profiles. It runs nightly,
on demand and on pull requests labelled `db-matrix`, and must be green before a release.

## Who uses it

- **Core and module developers.** Operators get sites that work the same on every engine.

## For module developers

- Extend `SightPress\Core\Infrastructure\Migration\PortableMigration` instead of Doctrine's `AbstractMigration`.
  MySQL and MariaDB commit on every schema change, so there a migration runs without a transaction; on SQLite and
  PostgreSQL it still runs in one. A plain `AbstractMigration` breaks later writes on MySQL ("SAVEPOINT … does not
  exist").
- Don't name a table or a column after a reserved word of any engine (for example `system`, `order`, `user`,
  `group`, `rank`): the ORM doesn't quote names. Core's own schema is checked by `ReservedWordsTest`.

## Running the integration suite on a server

Set `SIGHTPRESS_TEST_DATABASE_URL` to a server URL whose user may create and drop databases, for example
`postgresql://sightpress:sightpress@postgres:5432/sightpress?serverVersion=18`. Each test gets its own `sp_test_*`
database, dropped again afterwards (at the latest by the next run). See core's README, "Continuous integration".

## Code and tests

- `.github/workflows/db-matrix.yml`, `src/Infrastructure/Migration/PortableMigration.php`
- `tests/Support/TestDatabases.php`, `tests/Support/TestDatabaseCleanup.php`,
  `tests/Integration/Module/ReservedWordsTest.php`
