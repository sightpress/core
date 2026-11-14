# 0013 — Framework and library baseline

- Status: Accepted
- Date: 2026-11-13

## Context

Core is built on modern, object-oriented PHP and a small set of well-known libraries. Modules must not depend on
these libraries (ADR-0009), but core, the skeleton and the docs need a clear baseline of major versions so that
upgrades are deliberate, reviewed decisions. Slim 5 is in development, and several libraries have new majors coming.

## Decision

- PHP `>=8.5`, with `declare(strict_types=1)` everywhere.
- Use these major versions, each as a caret constraint on the major so we get the latest minor and patch:

  | Purpose | Package | Constraint |
  | --- | --- | --- |
  | HTTP framework | `slim/slim` | `^4` |
  | PSR-7 / PSR-17 | `nyholm/psr7` + `nyholm/psr7-server` | `^1` |
  | Container | `php-di/php-di` | `^7` |
  | Database abstraction | `doctrine/dbal` | `^4` |
  | ORM | `doctrine/orm` | `^3` |
  | Migrations | `doctrine/migrations` | `^3` |
  | Templates | `twig/twig` | `^3` |
  | File storage | `league/flysystem` | `^3` |
  | Logging | `monolog/monolog` | `^3` |
  | Console, mailer, validator, HTML sanitiser, translation, workflow, rate limiter, cache | Symfony components | one shared current major |

- Pick one Symfony major for all Symfony components at a time, and upgrade them together.
- Moving any listed package to a new major (for example Slim 5, ORM 4, DBAL 5, Twig 4) needs a new ADR that
  supersedes this one or its row.
- Keep library types out of `module-interface`; only core's registrar implementations and infrastructure code
  touch them.

## Consequences

- Dependabot can raise minor and patch updates freely; major updates are blocked by the constraints and come with
  an ADR.
- Public descriptions (README, `composer.json`, release notes) still name only "modern, object-oriented PHP", not
  the libraries.
- When a pinned major reaches end of life, we must plan the upgrade before it does.
