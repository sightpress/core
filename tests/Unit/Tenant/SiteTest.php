<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(Site::class)]
#[CoversClass(SiteStorage::class)]
#[UsesClass(SecretBox::class)]
#[UsesClass(LocaleCode::class)]
final class SiteTest extends TestCase
{
    public function testHoldsTheSite(): void
    {
        $site = $this->site(['name' => '  Demo  ', 'createdAt' => new DateTimeImmutable('2026-11-14 10:30:00+01:00')]);

        self::assertSame('demo', $site->slug);
        self::assertSame('Demo', $site->name);
        self::assertSame(['demo.example', 'www.demo.example'], $site->domains);
        self::assertSame(['en-GB', 'cy'], $site->locales);
        self::assertSame('2026-11-14T09:30:00+00:00', $site->createdAt->format(DATE_ATOM));
        self::assertTrue($site->isEnabled());
        self::assertFalse($this->site(['status' => SiteStatus::Disabled])->isEnabled());
        self::assertSame('local', $site->storage->adapter);
        self::assertNull($site->storage->path);
        self::assertSame('/srv/files', new SiteStorage('local', '/srv/files')->path);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'id' => [['id' => 'not-a-uuid'], '"id" is not valid: use a UUID.'];
        yield 'slug' => [['slug' => 'Demo'], '"slug" is not valid'];
        yield 'slug with an end hyphen' => [['slug' => 'demo-'], '"slug" is not valid'];
        yield 'empty name' => [['name' => ' '], '"name" must not be empty.'];
        yield 'domain' => [['domains' => ['Demo.example']], '"domains[0]" is not valid'];
        yield 'domain with a port' => [['domains' => ['demo.example:8080']], '"domains[0]" is not valid'];
        yield 'domain twice' => [['domains' => ['a.example', 'a.example']], '"domains" lists the same domain twice.'];
        yield 'plain DSN' => [['databaseDsn' => 'sqlite:///x'], '"db_dsn" must be encrypted'];
        yield 'empty DSN' => [['databaseDsn' => ''], '"db_dsn" must be encrypted'];
        yield 'default locale' => [['defaultLocale' => 'english'], '"default_locale" is not valid'];
        yield 'locale' => [['locales' => ['en-GB', 'xx_XX']], '"locales[1]" is not valid'];
        yield 'no default in locales' => [['locales' => ['cy']], '"locales" must include the default locale.'];
        yield 'no locales' => [['locales' => []], '"locales" must include the default locale.'];
        yield 'locale twice' => [['locales' => ['en-GB', 'en-GB']], '"locales" lists the same locale twice.'];
        yield 'timezone' => [['defaultTimezone' => 'Nowhere/Land'], '"default_timezone" is not valid'];
        yield 'empty timezone' => [['defaultTimezone' => ''], '"default_timezone" is not valid'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidValuesNamingTheKey(array $overrides, string $message): void
    {
        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage($message);

        $this->site($overrides);
    }

    public function testRejectsUnknownStorage(): void
    {
        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('"storage.adapter" is not valid: use local.');

        new SiteStorage('s3');
    }

    public function testRejectsARelativeStoragePath(): void
    {
        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('"storage.path" must be an absolute path.');

        new SiteStorage('local', 'files');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function site(array $overrides = []): Site
    {
        $values = $overrides + [
            'id' => SiteFixture::id('demo'),
            'slug' => 'demo',
            'name' => 'Demo',
            'status' => SiteStatus::Enabled,
            'ownerType' => OwnerType::Person,
            'domains' => ['demo.example', 'www.demo.example'],
            'databaseDsn' => SiteFixture::secretBox()->encrypt('sqlite:///:memory:'),
            'storage' => new SiteStorage(),
            'defaultLocale' => 'en-GB',
            'locales' => ['en-GB', 'cy'],
            'defaultTimezone' => 'UTC',
            'createdAt' => new DateTimeImmutable('2026-11-14T09:30:00Z'),
        ];

        /** @phpstan-ignore argument.type (the test passes whatever it needs) */
        return new Site(...$values);
    }
}
