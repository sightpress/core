<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(SiteRegistryFormat::class)]
#[UsesClass(Site::class)]
#[UsesClass(SiteStorage::class)]
#[UsesClass(SecretBox::class)]
#[UsesClass(LocaleCode::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
final class SiteRegistryFormatTest extends TestCase
{
    public function testReadsTheSitesKeyedBySlug(): void
    {
        $sites = SiteRegistryFormat::parse([
            'version' => 2,
            'sites' => [
                'demo' => SiteFixture::entry('demo'),
                'blog' => SiteFixture::entry('blog', [
                    'status' => 'archived',
                    'owner_type' => 'person',
                    'storage' => ['adapter' => 'local', 'path' => '/srv/blog'],
                ]),
            ],
        ], 'config/sites.php');

        self::assertSame(['demo', 'blog'], array_keys($sites));
        self::assertSame(SiteStatus::Archived, $sites['blog']->status);
        self::assertSame(OwnerType::Person, $sites['blog']->ownerType);
        self::assertSame('/srv/blog', $sites['blog']->storage->path);
        self::assertSame('Europe/London', $sites['demo']->defaultTimezone);
    }

    public function testAnEmptyRegistryHasNoSites(): void
    {
        self::assertSame([], SiteRegistryFormat::parse(['version' => 2], 'config/sites.php'));
    }

    public function testOptionalKeysHaveDefaults(): void
    {
        $entry = SiteFixture::entry();
        unset($entry['storage'], $entry['default_timezone']);

        $site = SiteRegistryFormat::parse(['version' => 2, 'sites' => ['demo' => $entry]], 'x')['demo'];

        self::assertSame('UTC', $site->defaultTimezone);
        self::assertSame('local', $site->storage->adapter);
    }

    public function testExportsWhatItReads(): void
    {
        $registry = [
            'version' => 2,
            'sites' => [
                'blog' => SiteFixture::entry('blog', ['storage' => ['adapter' => 'local', 'path' => '/srv/blog']]),
                'demo' => SiteFixture::entry('demo'),
            ],
        ];
        $sites = SiteRegistryFormat::parse($registry, 'x');

        self::assertSame($registry, SiteRegistryFormat::export(array_reverse($sites)));
    }

    public function testAnOlderFileWithoutSitesHasNothingToConvert(): void
    {
        self::assertSame([], SiteRegistryFormat::parse(['version' => 1, 'sites' => []], 'sites.php'));
        self::assertSame([], SiteRegistryFormat::parse(['version' => 1], 'sites.php'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'not an array' => ['sites', 'config/sites.php must return an array.'];
        yield 'unknown top key' => [['version' => 2, 'site' => []], 'config/sites.php: unknown key "site"; use version and sites.'];
        yield 'no version' => [['sites' => []], 'config/sites.php: "version" must be a whole number, at least 1.'];
        yield 'version zero' => [['version' => 0], '"version" must be a whole number, at least 1.'];
        yield 'newer version' => [['version' => 3], 'uses format version 3, made by a newer SightPress; this version reads up to 2.'];
        yield 'older version with sites' => [['version' => 1, 'sites' => ['demo' => SiteFixture::entry('demo')]], 'uses format version 1; this version of SightPress needs 2. Run `bin/sightpress site:registry:upgrade`.'];
        yield 'sites not an array' => [['version' => 2, 'sites' => 'demo'], '"sites" must be an array keyed by site slug.'];
        yield 'site not an array' => [self::with('demo', 'x'), 'config/sites.php: site "demo": must be an array.'];
        yield 'bad slug' => [self::with('Demo', SiteFixture::entry()), 'site "Demo": "slug" is not valid'];
        yield 'unknown key' => [self::with('demo', ['colour' => 'red'] + SiteFixture::entry()), 'site "demo": unknown key "colour".'];
        yield 'missing key' => [self::without('db_dsn'), 'site "demo": "db_dsn" is missing.'];
        yield 'status' => [self::entry(['status' => 'paused']), '"status" is not valid: use enabled, disabled, archived or deleted.'];
        yield 'owner type' => [self::entry(['owner_type' => 'robot']), '"owner_type" is not valid: use person or organization.'];
        yield 'name not a string' => [self::entry(['name' => 7]), '"name" must be a string.'];
        yield 'domains not a list' => [self::entry(['domains' => 'demo.example']), '"domains" must be a list of strings.'];
        yield 'domains with a number' => [self::entry(['domains' => [1]]), '"domains" must be a list of strings.'];
        yield 'domains as a map' => [self::entry(['domains' => ['a' => 'demo.example']]), '"domains" must be a list of strings.'];
        yield 'storage not an array' => [self::entry(['storage' => 'local']), '"storage" must be an array with "adapter"'];
        yield 'storage key' => [self::entry(['storage' => ['bucket' => 'x']]), '"storage" must be an array with "adapter"'];
        yield 'storage adapter' => [self::entry(['storage' => ['adapter' => 1]]), '"storage.adapter" must be a string.'];
        yield 'storage path' => [self::entry(['storage' => ['path' => 1]]), '"storage.path" must be a string.'];
        yield 'created at' => [self::entry(['created_at' => '14/11/2026']), '"created_at" is not valid: use a UTC date'];
        yield 'created at overflow' => [self::entry(['created_at' => '2026-02-30T09:30:00Z']), '"created_at" is not valid'];
        yield 'same id' => [
            ['version' => 2, 'sites' => ['a' => SiteFixture::entry('a'), 'b' => SiteFixture::entry('b', ['id' => SiteFixture::id('a')])]],
            'config/sites.php: site "b": "id" is already used by site "a".',
        ];
        yield 'same domain' => [
            ['version' => 2, 'sites' => ['a' => SiteFixture::entry('a'), 'b' => SiteFixture::entry('b', ['domains' => ['b.example', 'a.example']])]],
            'config/sites.php: site "b": "domains[1]" is already used by site "a".',
        ];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnInvalidRegistry(mixed $registry, string $message): void
    {
        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage($message);

        SiteRegistryFormat::parse($registry, 'config/sites.php');
    }

    public function testMessagesNeverContainValues(): void
    {
        $secretDsn = 'mysql://user:hunter2@db/site';

        try {
            SiteRegistryFormat::parse(self::entry(['db_dsn' => $secretDsn]), 'config/sites.php');
            self::fail('The DSN was accepted.');
        } catch (SiteRegistryException $exception) {
            self::assertStringNotContainsString('hunter2', $exception->getMessage());
            self::assertStringContainsString('site "demo": "db_dsn" must be encrypted', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function with(string $slug, mixed $entry): array
    {
        return ['version' => 2, 'sites' => [$slug => $entry]];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function entry(array $overrides): array
    {
        return self::with('demo', SiteFixture::entry('demo', $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private static function without(string $key): array
    {
        $entry = SiteFixture::entry();
        unset($entry[$key]);

        return self::with('demo', $entry);
    }
}
