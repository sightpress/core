<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;

#[CoversClass(SiteS3Storage::class)]
#[CoversClass(SiteStorage::class)]
#[UsesClass(SecretBox::class)]
final class SiteS3StorageTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function data(array $overrides = []): array
    {
        $box = new SecretBox(str_repeat('k', 32));

        return [...[
            'bucket' => 'media-bucket',
            'region' => 'eu-west-2',
            'prefix' => 'sites/one',
            'access_key' => $box->encrypt('id'),
            'secret_key' => $box->encrypt('secret'),
        ], ...$overrides];
    }

    public function testRoundTripsAndLeavesOutDefaults(): void
    {
        $data = self::data();
        self::assertSame($data, SiteS3Storage::fromArray($data)->toArray());
        $full = self::data(['endpoint' => 'http://minio:9000', 'path_style' => true]);
        $s3 = SiteS3Storage::fromArray($full);
        self::assertTrue($s3->pathStyle);
        self::assertSame('http://minio:9000', $s3->toArray()['endpoint']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'bucket' => [['bucket' => 'No_Such']];
        yield 'region' => [['region' => 'eu west']];
        yield 'endpoint scheme' => [['endpoint' => 'ftp://host']];
        yield 'endpoint path' => [['endpoint' => 'https://host/path']];
        yield 'endpoint credentials' => [['endpoint' => 'https://user@host']];
        yield 'endpoint query' => [['endpoint' => 'https://host?a=1']];
        yield 'endpoint fragment' => [['endpoint' => 'https://host#a']];
        yield 'empty prefix' => [['prefix' => '']];
        yield 'climbing prefix' => [['prefix' => 'sites/../other']];
        yield 'plain key' => [['access_key' => 'AKIAPLAINTEXT']];
        yield 'plain secret' => [['secret_key' => 'plain']];
        yield 'unknown key' => [['acl' => 'public']];
        yield 'endpoint type' => [['endpoint' => 5]];
        yield 'path style type' => [['path_style' => 'yes']];
        yield 'bucket type' => [['bucket' => 5]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalid')]
    public function testRefusesInvalidSettings(array $override): void
    {
        $this->expectException(SiteRegistryException::class);
        SiteS3Storage::fromArray(self::data($override));
    }

    public function testErrorsNeverQuoteAKey(): void
    {
        try {
            SiteS3Storage::fromArray(self::data(['secret_key' => 'super-secret-value']));
            self::fail('Expected a refusal.');
        } catch (SiteRegistryException $exception) {
            self::assertStringNotContainsString('super-secret-value', $exception->getMessage());
        }
    }

    public function testOverlapMeansSameOrNestedFolderOfOneBucket(): void
    {
        $base = SiteS3Storage::fromArray(self::data(['prefix' => 'sites/a']));
        self::assertTrue($base->overlaps(SiteS3Storage::fromArray(self::data(['prefix' => 'sites/a']))));
        self::assertTrue($base->overlaps(SiteS3Storage::fromArray(self::data(['prefix' => 'sites/a/b']))));
        self::assertTrue(SiteS3Storage::fromArray(self::data(['prefix' => 'sites/a/b']))->overlaps($base));
        self::assertFalse($base->overlaps(SiteS3Storage::fromArray(self::data(['prefix' => 'sites/ab']))));
        self::assertFalse($base->overlaps(SiteS3Storage::fromArray(self::data(['bucket' => 'other-bucket']))));
        self::assertFalse($base->overlaps(SiteS3Storage::fromArray(self::data(['endpoint' => 'http://minio:9000']))));
    }

    public function testStorageSwitchesOnlyWithBucketSettings(): void
    {
        $s3 = SiteS3Storage::fromArray(self::data());
        $storage = new SiteStorage()->withS3($s3);
        self::assertFalse($storage->isCloud());
        self::assertTrue($storage->switchedToS3()->isCloud());
        self::assertSame($s3, $storage->switchedToS3()->s3);
        $this->expectException(SiteRegistryException::class);
        new SiteStorage('s3');
    }

    public function testCdnAddressIsCheckedAndKeptThroughChanges(): void
    {
        $s3 = SiteS3Storage::fromArray(self::data());
        $storage = new SiteStorage()->withCdn('https://cdn.example');
        self::assertTrue($storage->signsPrivateUrls());
        self::assertSame('https://cdn.example', $storage->withS3($s3)->switchedToS3()->cdnUrl);
        self::assertNull($storage->withCdn(null)->cdnUrl);
        self::assertFalse(new SiteStorage()->signsPrivateUrls());
        self::assertTrue(new SiteStorage()->withS3($s3)->switchedToS3()->signsPrivateUrls());
        foreach (['http://cdn.example', 'https://cdn.example/path', 'https://u@cdn.example', 'https://cdn.example?a=1', 'https://cdn.example#a', 'cdn.example'] as $bad) {
            try {
                new SiteStorage()->withCdn($bad);
                self::fail('Expected a refusal of ' . $bad);
            } catch (SiteRegistryException $exception) {
                self::assertStringContainsString('storage.cdn_url', $exception->getMessage());
            }
        }
    }
}
