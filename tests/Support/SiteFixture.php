<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;

/**
 * Valid sites and registry entries for tests.
 */
final class SiteFixture
{
    public const string SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    public static function secretBox(): SecretBox
    {
        return new SecretBox(self::SECRET);
    }

    /**
     * @param list<string> $domains
     */
    public static function site(
        string $slug = 'demo',
        array $domains = ['demo.example'],
        SiteStatus $status = SiteStatus::Enabled,
        string $dsn = 'sqlite:///:memory:',
        ?SecretBox $secretBox = null,
    ): Site {
        return new Site(
            id: self::id($slug),
            slug: $slug,
            name: ucfirst($slug),
            status: $status,
            ownerType: OwnerType::Organization,
            domains: $domains,
            databaseDsn: ($secretBox ?? self::secretBox())->encrypt($dsn),
            storage: new SiteStorage(),
            defaultLocale: 'en-GB',
            locales: ['en-GB'],
            defaultTimezone: 'Europe/London',
            createdAt: new DateTimeImmutable('2026-11-14T09:30:00Z'),
        );
    }

    /**
     * A registry entry as it appears in `config/sites.php`.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function entry(string $slug = 'demo', array $overrides = []): array
    {
        return array_merge([
            'id' => self::id($slug),
            'name' => ucfirst($slug),
            'status' => 'enabled',
            'owner_type' => 'organization',
            'domains' => [$slug . '.example'],
            'db_dsn' => self::secretBox()->encrypt('sqlite:///:memory:'),
            'storage' => ['adapter' => 'local'],
            'default_locale' => 'en-GB',
            'locales' => ['en-GB'],
            'default_timezone' => 'Europe/London',
            'created_at' => '2026-11-14T09:30:00Z',
        ], $overrides);
    }

    /**
     * A UUID derived from the slug, so fixtures are stable.
     */
    public static function id(string $slug): string
    {
        $hash = md5($slug);

        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}
