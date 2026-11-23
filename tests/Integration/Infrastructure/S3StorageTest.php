<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Infrastructure;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Infrastructure\Storage\StorageMigrator;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;

/**
 * Against a real MinIO, when `SIGHTPRESS_TEST_S3_ENDPOINT` (and `_KEY`, `_SECRET`, `_BUCKET`) are set.
 */
#[CoversNothing]
final class S3StorageTest extends TestCase
{
    private SiteS3Storage $s3;

    private SiteFilesystems $filesystems;

    protected function setUp(): void
    {
        $endpoint = getenv('SIGHTPRESS_TEST_S3_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            self::markTestSkipped('Set SIGHTPRESS_TEST_S3_ENDPOINT to run against MinIO.');
        }
        $box = SiteFixture::secretBox();
        $this->s3 = new SiteS3Storage((string) getenv('SIGHTPRESS_TEST_S3_BUCKET'), 'us-east-1', $endpoint, 'test/' . bin2hex(random_bytes(6)), true, $box->encrypt((string) getenv('SIGHTPRESS_TEST_S3_KEY')), $box->encrypt((string) getenv('SIGHTPRESS_TEST_S3_SECRET')));
        $this->filesystems = new SiteFilesystems($box, '/tmp');
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystems, $this->s3)) {
            $this->filesystems->bucket($this->s3)->deleteDirectory('');
        }
    }

    public function testUploadReadDeleteAndMigrateAHundredFiles(): void
    {
        $this->filesystems->check($this->s3);
        $bucket = $this->filesystems->bucket($this->s3);
        $bucket->write('one.txt', 'hello');
        self::assertSame('hello', $bucket->read('one.txt'));
        $bucket->delete('one.txt');
        self::assertFalse($bucket->fileExists('one.txt'));

        $project = new TestProject();
        try {
            $source = new Filesystem(new LocalFilesystemAdapter($project->path() . '/files'));
            for ($i = 0; $i < 100; ++$i) {
                $source->write(sprintf('media/%d/f%d.bin', $i % 7, $i), random_bytes(1024 + $i));
            }
            $first = new StorageMigrator()->migrate($source, $bucket);
            self::assertTrue($first->isComplete());
            self::assertSame(100, $first->copied);
            $second = new StorageMigrator()->migrate($source, $bucket);
            self::assertSame(100, $second->alreadyThere);
        } finally {
            $project->remove();
        }
    }

    public function testWrongCredentialsArePlainAndNeverShowTheSecret(): void
    {
        $box = SiteFixture::secretBox();
        $wrong = new SiteS3Storage($this->s3->bucket, 'us-east-1', $this->s3->endpoint, $this->s3->prefix, true, $box->encrypt('wrongaccesskey'), $box->encrypt('wrong-secret-value'));
        try {
            $this->filesystems->check($wrong);
            self::fail('Expected a refusal.');
        } catch (StorageException $exception) {
            self::assertStringContainsString('refused the access key', $exception->getMessage());
            self::assertStringNotContainsString('wrong-secret-value', $exception->getMessage());
            self::assertStringNotContainsString('wrongaccesskey', $exception->getMessage());
        }
    }
}
