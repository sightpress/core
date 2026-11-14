# Kernel and error pages (C-1.1)

## What it does

The kernel starts SightPress for one install (a *tenant*): it reads `config/tenant.php` and the environment,
builds the service container and answers web requests. When something goes wrong, visitors get a plain,
accessible error page; programs that ask for JSON get a JSON error instead.

## Who uses it

- **Operators** set the tenant config and the environment variables.
- **Visitors** see the error pages.
- **Developers** boot the kernel from `public/index.php` with `Kernel::serve(dirname(__DIR__));`.

## Settings

Environment variables:

- `APP_ENV`: `production` (default when empty), `staging`, `dev` or `test`. Anything but production sends
  `X-Robots-Tag: noindex, nofollow` so search engines don't index the install.
- `APP_DEBUG`: `1` or `0`. Shows technical details on error pages. On by default only in `dev`. Never turn it on
  in production.

`config/tenant.php` (every key is optional):

| Key | Default | Meaning |
| --- | --- | --- |
| `registry.path` | `config/sites.php` | The site registry file. |
| `sites_dir` | `sites/` | Where site files are stored. |
| `cache_dir` | `var/cache/<env>` | Cache folder. |
| `log.path` | `var/log/<env>.log` | Log file, or a stream such as `php://stderr`. |
| `log.level` | `notice` | Lowest level written: `debug`, `info`, `notice`, `warning`, `error` … |
| `admin.default_locale` | `en-GB` | The admin panel's default and fallback language. |
| `timezone` | `UTC` | Time zone used to show dates to visitors when the site and the user have none. Dates are always stored in UTC. |

An invalid setting stops the start with a message that names it; visitors then see the "problem with the
service" page and the reason goes to the PHP error log.

## Error pages

| Status | Heading |
| --- | --- |
| 400 | Sorry, something is wrong with this request |
| 401 | Sign in to see this page |
| 403 | You do not have permission to see this page |
| 404 | Page not found (or "No site configured" while the install has no site) |
| 405 | This page cannot do that (with an `Allow` header) |
| 410 | This page has been removed |
| 429 | Too many requests (with `Retry-After`, said in words) |
| 500 | Sorry, there is a problem with the service |
| 503 | Sorry, the service is unavailable (with `Retry-After`, said in words) |

Clients that send `Accept: application/json` get Problem Details JSON (`application/problem+json`, RFC 9457).
Server errors (5xx) are logged with the exception; the page never shows it unless `APP_DEBUG` is on.

## Accessibility

Each page has `lang="en-GB"`, a unique `<title>`, one `<main>` landmark and one `<h1>`, uses system colours
that follow light and dark mode, and shows a visible focus outline on its links. The pages need no template
engine, so they still work when something else is broken.

## Code and tests

- `src/Kernel.php`, `src/Tenant/`, `src/Http/Error/`, `src/Infrastructure/`
- `tests/Unit/KernelTest.php`, `tests/Unit/Http/Error/`, `tests/Unit/Tenant/`
