<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Storage;

use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteMediaLimits;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\FakeS3;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;

#[CoversClass(SiteFilesystems::class)]
#[CoversClass(StorageException::class)]
#[UsesClass(SecretBox::class)]
#[UsesClass(Site::class)]
#[UsesClass(SiteMediaLimits::class)]
#[UsesClass(SiteS3Storage::class)]
#[UsesClass(SiteStorage::class)]
#[UsesClass(OwnerType::class)]
#[UsesClass(SiteStatus::class)]
final class SiteFilesystemsTest extends TestCase
{
    private FakeS3 $s3;

    protected function setUp(): void
    {
        $this->s3 = new FakeS3();
    }

    private function filesystems(string $dir = '/tmp'): SiteFilesystems
    {
        return new SiteFilesystems(SiteFixture::secretBox(), $dir, fn (array $options): S3Client => new S3Client([...$options, 'http_handler' => $this->s3]));
    }

    private static function bucket(?string $endpoint = 'http://minio:9000', ?SecretBox $box = null): SiteS3Storage
    {
        $box ??= SiteFixture::secretBox();

        return new SiteS3Storage('media-bucket', 'eu-west-2', $endpoint, 'sites/one', true, $box->encrypt('id'), $box->encrypt('secret'));
    }

    public function testWritesUnderThePrefixAndChecks(): void
    {
        $storage = $this->filesystems()->bucket(self::bucket(null));
        $storage->write('media/a.txt', 'hello');
        self::assertSame('hello', $this->s3->objects['sites/one/media/a.txt']);
        self::assertSame('hello', $storage->read('media/a.txt'));
        $this->filesystems()->check(self::bucket());
        self::assertArrayNotHasKey('sites/one/.sightpress-storage-check', $this->s3->objects);
    }

    public function testStopsUnfinishedUploadsUnderThePrefixOnly(): void
    {
        $this->s3->uploads = [
            'mine' => ['key' => 'sites/one/incoming/a', 'parts' => [1 => 'x']],
        ];

        $this->filesystems()->abortUnfinishedUploads(self::bucket());

        self::assertSame([], $this->s3->uploads);
    }

    public function testPicksTheStorageOfTheSite(): void
    {
        $project = new TestProject();
        try {
            $site = SiteFixture::site();
            $filesystems = $this->filesystems($project->path());
            $filesystems->forSite($site)->write('local.txt', 'x');
            self::assertFileExists($project->path() . '/' . $site->id . '/local.txt');
            $cloud = $site->withStorage(new SiteStorage()->withS3(self::bucket())->switchedToS3());
            $filesystems->forSite($cloud)->write('cloud.txt', 'y');
            self::assertSame('y', $this->s3->objects['sites/one/cloud.txt']);
        } finally {
            $project->remove();
        }
    }

    public function testBuildsARealClientWithoutAFactory(): void
    {
        new SiteFilesystems(SiteFixture::secretBox(), '/tmp')->bucket(self::bucket(null));
        $this->addToAssertionCount(1);
    }

    public function testSaysWhatToInstallWithoutTheS3Packages(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Install league/flysystem-aws-s3-v3 to keep files in S3.');
        new SiteFilesystems(SiteFixture::secretBox(), '/tmp', packages: ['SightPress\\Missing\\S3Package'])->bucket(self::bucket());
    }

    public function testKeysFromAnotherSecretAreExplained(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('can\'t be read with this APP_SECRET');
        $this->filesystems()->bucket(self::bucket(box: new SecretBox(str_repeat('z', 40))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errors(): iterable
    {
        yield 'wrong key' => ['InvalidAccessKeyId', 'refused the access key or secret key'];
        yield 'wrong secret' => ['SignatureDoesNotMatch', 'refused the access key or secret key'];
        yield 'denied' => ['AccessDenied', 'aren\'t allowed to use the bucket "media-bucket"'];
        yield 'no bucket' => ['NoSuchBucket', 'There is no bucket named "media-bucket"'];
        yield 'region' => ['PermanentRedirect', 'is in another region'];
        yield 'other' => ['SlowDown', 'refused the request (SlowDown)'];
    }

    #[DataProvider('errors')]
    public function testErrorsArePlainAndNeverQuoteTheProvider(string $code, string $expected): void
    {
        $this->s3->errorCode = $code;
        try {
            $this->filesystems()->check(self::bucket());
            self::fail('Expected a refusal.');
        } catch (StorageException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringNotContainsString('AKIASECRETVALUE', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testAnErrorWithoutACodeMeansUnreachable(): void
    {
        self::assertStringContainsString('can\'t be reached', SiteFilesystems::explain(new RuntimeException('down'), self::bucket()));
    }

    public function testABucketThatGivesBackSomethingElseFailsTheCheck(): void
    {
        $this->s3->corruptWrites = 'other';
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('didn\'t give back the file');
        $this->filesystems()->check(self::bucket());
    }
}
