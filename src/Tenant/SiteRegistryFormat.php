<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Reads and writes the array in `config/sites.php` (ADR-0002): a format `version` and a `sites` map keyed by slug.
 *
 * Reading validates everything (known keys, types, statuses, locales, time zones, every domain used by one site
 * only) and fails with a message that names the file, the site and the key, never a value.
 */
final class SiteRegistryFormat
{
    /** The format this version of core reads and writes; `site:registry:upgrade` moves older files to it. */
    public const int VERSION = 2;

    public const string DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const array KEYS = [
        'id',
        'name',
        'status',
        'owner_type',
        'domains',
        'db_dsn',
        'storage',
        'default_locale',
        'locales',
        'default_timezone',
        'created_at',
        'media_limits',
    ];

    private const array OPTIONAL = ['storage', 'default_timezone', 'media_limits'];

    /**
     * @param string $source the file, for error messages
     *
     * @return array<non-empty-string, Site> keyed by slug
     */
    public static function parse(mixed $registry, string $source): array
    {
        if (!is_array($registry)) {
            throw new SiteRegistryException(sprintf('%s must return an array.', $source));
        }
        foreach (array_keys($registry) as $key) {
            if (!in_array($key, ['version', 'sites'], true)) {
                throw new SiteRegistryException(sprintf('%s: unknown key "%s"; use version and sites.', $source, $key));
            }
        }

        // An older file without sites has nothing to convert. The skeleton ships one, and changing it would make
        // `git pull` conflict on every install that has added a site; the next write stores the current version.
        $version = self::version($registry, $source);
        if ($version > self::VERSION || ($version < self::VERSION && ($registry['sites'] ?? []) !== [])) {
            self::assertCurrentVersion($version, $source);
        }

        $sites = $registry['sites'] ?? [];
        if (!is_array($sites)) {
            throw new SiteRegistryException(sprintf('%s: "sites" must be an array keyed by site slug.', $source));
        }

        $parsed = [];
        $ids = [];
        $domains = [];
        foreach ($sites as $slug => $data) {
            $slug = (string) $slug;
            try {
                $site = self::site($slug, $data);
            } catch (SiteRegistryException $exception) {
                throw new SiteRegistryException(
                    sprintf('%s: site "%s": %s', $source, $slug, $exception->getMessage()),
                    previous: $exception,
                );
            }

            if (isset($ids[$site->id])) {
                throw new SiteRegistryException(sprintf(
                    '%s: site "%s": "id" is already used by site "%s".',
                    $source,
                    $slug,
                    $ids[$site->id],
                ));
            }
            $ids[$site->id] = $site->slug;

            foreach ($site->domains as $index => $domain) {
                if (isset($domains[$domain])) {
                    throw new SiteRegistryException(sprintf(
                        '%s: site "%s": "domains[%d]" is already used by site "%s".',
                        $source,
                        $slug,
                        $index,
                        $domains[$domain],
                    ));
                }
                $domains[$domain] = $site->slug;
            }

            $parsed[$site->slug] = $site;
        }

        return $parsed;
    }

    /**
     * The format version of a registry array, without checking anything else.
     *
     * @param array<mixed> $registry
     *
     * @return positive-int
     */
    public static function version(array $registry, string $source): int
    {
        $version = $registry['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new SiteRegistryException(sprintf('%s: "version" must be a whole number, at least 1.', $source));
        }

        return $version;
    }

    /**
     * How to move a registry to the next format, by the version it upgrades from.
     *
     * @return array<int, \Closure(array<mixed>): array<mixed>>
     */
    public static function upgradeSteps(): array
    {
        return [
            // Version 2 adds a site's optional `media_limits` and `storage.s3`; what version 1 stored stays as it is.
            1 => static fn (array $registry): array => $registry,
        ];
    }

    /**
     * @param array<Site> $sites
     *
     * @return array{version: int, sites: array<string, array<string, mixed>>}
     */
    public static function export(array $sites): array
    {
        uasort($sites, static fn (Site $a, Site $b): int => strcmp($a->slug, $b->slug));

        $exported = [];
        foreach ($sites as $site) {
            $storage = ['adapter' => $site->storage->adapter];
            if ($site->storage->path !== null) {
                $storage['path'] = $site->storage->path;
            }
            if ($site->storage->s3 !== null) {
                $storage['s3'] = $site->storage->s3->toArray();
            }

            $exported[$site->slug] = [
                'id' => $site->id,
                'name' => $site->name,
                'status' => $site->status->value,
                'owner_type' => $site->ownerType->value,
                'domains' => $site->domains,
                'db_dsn' => $site->databaseDsn,
                'storage' => $storage,
                'default_locale' => $site->defaultLocale,
                'locales' => $site->locales,
                'default_timezone' => $site->defaultTimezone,
                'created_at' => $site->createdAt->format(self::DATE_FORMAT),
            ];
            if (!$site->mediaLimits->isEmpty()) {
                $exported[$site->slug]['media_limits'] = $site->mediaLimits->toArray();
            }
        }

        return ['version' => self::VERSION, 'sites' => $exported];
    }

    private static function assertCurrentVersion(int $version, string $source): void
    {
        $messages = [
            -1 => '%s uses format version %d; this version of SightPress needs %d. '
                . 'Run `bin/sightpress site:registry:upgrade`.',
            1 => '%s uses format version %d, made by a newer SightPress; this version reads up to %d. '
                . 'Upgrade SightPress.',
        ];

        throw new SiteRegistryException(sprintf(
            $messages[$version <=> self::VERSION],
            $source,
            $version,
            self::VERSION,
        ));
    }

    private static function site(string $slug, mixed $data): Site
    {
        if (!is_array($data)) {
            throw new SiteRegistryException('must be an array.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new SiteRegistryException(sprintf('unknown key "%s".', $key));
            }
        }
        foreach (array_diff(self::KEYS, self::OPTIONAL) as $key) {
            if (!array_key_exists($key, $data)) {
                throw new SiteRegistryException(sprintf('"%s" is missing.', $key));
            }
        }

        $storage = $data['storage'] ?? [];
        if (!is_array($storage) || array_diff(array_keys($storage), ['adapter', 'path', 's3']) !== []) {
            throw new SiteRegistryException('"storage" must be an array with "adapter" and an optional "path" and "s3".');
        }
        $s3 = $storage['s3'] ?? null;
        if ($s3 !== null && !is_array($s3)) {
            throw new SiteRegistryException('"storage.s3" must be an array.');
        }

        return new Site(
            id: self::string($data, 'id'),
            slug: $slug,
            name: self::string($data, 'name'),
            status: SiteStatus::tryFrom(self::string($data, 'status')) ?? throw new SiteRegistryException(
                '"status" is not valid: use enabled, disabled, archived or deleted.',
            ),
            ownerType: OwnerType::tryFrom(self::string($data, 'owner_type')) ?? throw new SiteRegistryException(
                '"owner_type" is not valid: use person or organization.',
            ),
            domains: self::strings($data, 'domains'),
            databaseDsn: self::string($data, 'db_dsn'),
            storage: new SiteStorage(
                self::string($storage + ['adapter' => 'local'], 'adapter', 'storage.adapter'),
                isset($storage['path']) ? self::string($storage, 'path', 'storage.path') : null,
                $s3 === null ? null : SiteS3Storage::fromArray($s3),
            ),
            defaultLocale: self::string($data, 'default_locale'),
            locales: self::strings($data, 'locales'),
            defaultTimezone: self::string($data + ['default_timezone' => 'UTC'], 'default_timezone'),
            createdAt: self::date($data),
            mediaLimits: self::mediaLimits($data),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function mediaLimits(array $data): SiteMediaLimits
    {
        $limits = $data['media_limits'] ?? [];
        if (!is_array($limits)) {
            throw new SiteRegistryException('"media_limits" must be an array.');
        }
        try {
            return SiteMediaLimits::fromArray($limits);
        } catch (InvalidArgumentException $exception) {
            throw new SiteRegistryException('"media_limits": ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key, ?string $name = null): string
    {
        $value = $data[$key];
        if (!is_string($value)) {
            throw new SiteRegistryException(sprintf('"%s" must be a string.', $name ?? $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key];
        if (!is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            throw new SiteRegistryException(sprintf('"%s" must be a list of strings.', $key));
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function date(array $data): DateTimeImmutable
    {
        $value = self::string($data, 'created_at');
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            throw new SiteRegistryException('"created_at" is not valid: use a UTC date such as 2026-11-14T09:30:00Z.');
        }

        return $date;
    }
}
