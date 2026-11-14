# 0015 — Runtime dependencies for M1 (amends ADR-0013)

- Status: Proposed
- Date: 2026-11-14

## Context

ADR-0013 fixed the major versions of the main libraries (HTTP framework, container, Doctrine, Twig, Flysystem,
Monolog, Symfony components). Building the first release (M1: kernel, module loader, sites, users and roles,
pages and blocks, media, theme, admin) needs a few more runtime libraries, and some ADR-0013 rows need a concrete
package name. Every new runtime dependency adds code we must trust and upgrade, so each one is recorded here with
its pinned major.

## Decision

Core uses these runtime dependencies for M1. Each is a caret constraint on the major, as in ADR-0013. Rows marked
*new* are not in ADR-0013.

| Purpose | Package | Constraint | Added in |
| --- | --- | --- | --- |
| HTTP framework | `slim/slim` | `^4` | C-1.1 |
| PSR-7 / PSR-17 messages and server requests | `nyholm/psr7`, `nyholm/psr7-server` | `^1` | C-1.1 |
| Container | `php-di/php-di` | `^7` | C-1.1 |
| Logging | `monolog/monolog` | `^3` | C-1.1 |
| Version constraints of modules (*new*) | `composer/semver` | `^3` | C-1.5 |
| Console | `symfony/console` | `^8` | C-1.5 |
| Migrations | `doctrine/migrations` | `^3` | C-1.5 |
| CSRF tokens (*new*) | `slim/csrf` | `^1` | C-1.2 |
| Rate limiting | `symfony/rate-limiter` (+ `symfony/cache` for its storage) | `^8` | C-1.2 |
| Clock (*new*, interface only) | `psr/clock` | `^1` | C-1.2 |
| Templates | `twig/twig` | `^3` | C-1.3 |
| Translation | `symfony/translation` | `^8` | C-1.3 |
| Validation | `symfony/validator` | `^8` | C-1.4 |
| Database abstraction / ORM | `doctrine/dbal`, `doctrine/orm` | `^4`, `^3` | C-2 |
| Encryption of DSNs | PHP `sodium` extension | — | C-2.1 |
| Mail | `symfony/mailer` | `^8` | C-3.4 |
| Workflow | `symfony/workflow` | `^8` | C-4.4 |
| HTML sanitising | `symfony/html-sanitizer` | `^8` | C-4.2 |
| File storage | `league/flysystem` | `^3` | C-5.1 |
| Image processing (*new*) | `intervention/image` | `^4` | C-5.1 |
| SVG sanitising (*new*) | `enshrined/svg-sanitize` | `^1` | C-6.6 |

- Symfony components share one major (`^8`), as ADR-0013 requires, and are upgraded together.
- PSR-7 comes from `nyholm/psr7` (ADR-0013), not `slim/psr7`.
- Core needs the PHP extensions `intl` (dates, numbers and plural rules in the visitor's locale), `sodium`,
  `fileinfo` and `pdo` with at least `pdo_sqlite`. The skeleton's images and CI install them.
- A dependency is added in the task that first uses it, not before.
- Anything not listed here (for example a Markdown or search library) needs a new ADR or a row added to this one
  before it is required.

## Consequences

- Each new runtime library is a deliberate, reviewed choice with a known major version.
- Moving any of these packages to a new major needs an ADR, as for ADR-0013.
- Modules still depend only on `sightpress/module-interface` (ADR-0009); none of these types appear there.
