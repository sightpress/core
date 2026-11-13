# SightPress core

The core of SightPress, a powerful multi-site, multilingual CMS for everyone, designed with blind and low-vision
creators and audiences in mind. It is built on modern, object-oriented PHP.

Core holds the kernel, the module loader and the built-in modules. Every feature is a module, built on the same
contracts that third-party modules use (`sightpress/module-interface`).

To start a new site, use the [skeleton](https://github.com/sightpress/skeleton) project instead of this package.

## Requirements

- PHP 8.5 or later

## Development

Clone `module-interface` next to this repo (Composer finds it at `../module-interface` through path repositories), then:

```sh
composer install
composer check
```

Composer commands:

- `composer check`: everything that must pass before a commit. It validates `composer.json`, checks the code
  style, runs static analysis, runs the unit suite with the coverage gate, then runs the integration suite.
- `composer test`: both test suites. `composer test:unit` and `composer test:integration` run one suite each.
- `composer test:coverage`: the unit suite with coverage. It fails if unit tests cover less than 100% of the lines
  or methods in `src/`, and it lists what is not covered. `@codeCoverageIgnore` is not allowed. The HTML report
  is written to `build/coverage/html/`.
- `composer fix`: applies Rector refactorings, then fixes the code style.

Coverage needs the PCOV extension, or Xdebug in coverage mode. If your PHP has neither, use the
development image in `docker/dev-php/`, which has PHP 8.5, PCOV, SQLite and Composer. Run it from this folder:

```sh
docker build -t sightpress/dev-php:8.5 docker/dev-php
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD/..:/workspace" -w /workspace/core \
  sightpress/dev-php:8.5 composer check
```

The parent folder is mounted so that the sibling repos are visible inside the container.

### Accessibility tests

Playwright and axe-core check the skeleton's pages against WCAG 2.2 AA. You need Node.js and a running skeleton
(`docker compose up` in `../skeleton`), then:

```sh
npm ci
npx playwright install chromium
composer a11y
```

Set `SIGHTPRESS_BASE_URL` if the skeleton is not on `http://localhost:8080`. The tests live in `tests/A11y/`.

### Continuous integration

Every pull request and every push to `main` runs two GitHub Actions workflows:

- `CI` (`composer check`): `composer validate`, code style, PHPStan, the unit suite with the 100% coverage gate,
  the integration suite on SQLite in memory, and `composer audit`. The coverage report is uploaded as an artifact.
- `Accessibility` (`Playwright and axe`): boots the skeleton with docker compose, using this branch of core, and
  runs `composer a11y`.

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/); release-please uses them to
keep a release pull request and `CHANGELOG.md` up to date.

`main` is protected by the ruleset in `.github/rulesets/main.json`. Changes reach it only through a pull request that
passes the `composer check` and `Playwright and axe` checks and has one approval. Pull requests are squash-merged.
Admins can bypass the ruleset.

## Licence

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
