# Site registry (C-2.1)

## What it does

One install of SightPress can run many sites. The site registry is the list of those sites: their names, web
addresses (domains), status, database and storage. It lives in one file, `config/sites.php`, so the install
itself needs no database (ADR-0002).

## Who uses it

- **Operators** look after it with `bin/sightpress site:*` commands. They may also edit the file by hand and then
  run `site:registry:check`.
- **Developers** read sites through the `SightPress\Core\Tenant\SiteRepository` service.

## The file

`config/sites.php` returns an array with a format `version` (now `1`) and a `sites` map keyed by the site's slug:

```php
return [
    'version' => 1,
    'sites' => [
        'demo' => [
            'id' => '0b6c5f8e-6a43-4c8e-9a1d-2f7d0c3e5b11',
            'name' => 'Demo',
            'status' => 'enabled',              // enabled, disabled, archived or deleted
            'owner_type' => 'organization',     // person or organization
            'domains' => ['demo.example.org'],  // lower case, no port; each domain belongs to one site only
            'db_dsn' => 'sodium:v1:…',          // encrypted; never write a plain DSN here
            'storage' => ['adapter' => 'local'], // optional; `path` sets an absolute folder
            'default_locale' => 'en-GB',
            'locales' => ['en-GB', 'cy'],       // must include the default locale
            'default_timezone' => 'Europe/London', // optional, IANA name, default UTC
            'created_at' => '2026-11-14T09:30:00Z', // UTC
        ],
    ],
];
```

Slugs use 1 to 63 lower-case letters, digits and hyphens. An invalid file stops SightPress with a message that
names the file, the site and the key, never a value (so it never shows a password).

## Secrets

- Set `APP_SECRET` in the environment: at least 32 random characters, for example the output of
  `php -r "echo bin2hex(random_bytes(32));"`. It derives the key that encrypts every site's database DSN.
- Back it up. Without it, the database DSNs can't be decrypted and the sites can't start.

## Commands

| Command | What it does |
| --- | --- |
| `bin/sightpress site:registry:check` | Validates the file and checks every DSN can be decrypted with this `APP_SECRET`. |
| `bin/sightpress site:registry:upgrade` | Moves the file to the format version this SightPress reads. |

Every change keeps the previous file as `config/sites.php.bak`. Writes are atomic: the web server never sees half
a file. If PHP-FPM runs with `opcache.validate_timestamps=0`, reload it after a change.

Commands write plain sentences, with no tables or colours, so they read well with a screen reader.

## Code and tests

- `src/Tenant/` (`Site`, `SiteRepository`, `FileSiteRepository`, `SiteRegistryFile`, `SiteRegistryFormat`,
  `SiteRegistryUpgrader`), `src/Infrastructure/Crypto/SecretBox.php`, `src/Site/`
- `tests/Unit/Tenant/`, `tests/Unit/Site/`, `tests/Unit/Infrastructure/Crypto/`,
  `tests/Integration/Tenant/SiteRegistryTest.php`
