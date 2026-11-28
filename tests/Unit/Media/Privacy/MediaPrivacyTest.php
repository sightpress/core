<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Media\Privacy\StorageProcessors;
use SightPress\Core\Media\Privacy\UploadData;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\Privacy\UserRef;

#[CoversClass(UploadData::class)]
#[CoversClass(StorageProcessors::class)]
#[CoversClass(UploadSessions::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaPrivacyTest extends TestCase
{
    public function testExportsWhatThePersonAddedAndCancelsTheirUnfinishedUploads(): void
    {
        $connection = SiteTables::connection();
        $temp = new TemporaryDirectory();
        $site = SiteFixture::site();
        $config = TenantConfig::fromArray(['uploads' => ['temp_path' => $temp->path]], '/tmp/project');
        $folder = new UploadFolder($config, $site);
        $connection->insert('media', ['id' => 1, 'storage_key' => str_repeat('a', 32), 'checksum' => str_repeat('c', 64), 'kind' => 'image', 'mime_type' => 'image/png', 'extension' => 'png', 'file_name' => 'a.png', 'original_name' => 'Holiday.png', 'size' => 10, 'variants' => '[]', 'alt' => '{}', 'caption' => '{}', 'published' => 1, 'uploaded_by' => 7, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        $sessions = new UploadSessions($connection);
        $now = SiteTables::now();
        $sessions->add(new UploadSession('a-temp', 7, 'Passport scan.pdf', 100, 40, $now, $now));
        $sessions->add(new UploadSession('b-direct', 7, 'Video.mp4', 100, 0, $now, $now, mode: UploadSession::DIRECT, remoteId: 'r'));
        $sessions->add(new UploadSession('c-done', 7, 'Done.mp4', 100, 100, $now, $now, $now, 1, UploadSession::DIRECT));
        $sessions->add(new UploadSession('d-grace', 8, 'Grace.pdf', 100, 0, $now, $now));
        self::assertTrue($folder->prepare());
        file_put_contents($folder->path('a-temp'), 'part');
        $data = new UploadData($connection, $sessions, $folder, new BucketUploads($site, new SiteFilesystems(SiteFixture::secretBox(), $temp->path)));
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame('media.uploads', $data->describe()->key);
        $records = iterator_to_array($data->export($ada), false);
        self::assertSame(['file' => 'Holiday.png', 'media_id' => 1, 'kind' => 'image', 'bytes' => 10, 'added_at' => '2026-11-01T10:00:00Z'], $records[0]);
        self::assertSame(['unfinished_upload' => 'Passport scan.pdf', 'bytes' => 100, 'received_bytes' => 40, 'started_at' => '2026-11-28T12:00:00Z'], $records[1]);
        self::assertCount(3, $records, 'the finished upload is listed as a file');

        $erased = $data->erase($ada);
        self::assertSame([3, 1], [$erased->deleted, $erased->pseudonymised]);
        self::assertFileDoesNotExist($folder->path('a-temp'));
        self::assertSame(['d-grace'], array_map(static fn (UploadSession $session): string => $session->id, $sessions->all()));
        self::assertSame(7, Row::int($connection->fetchOne('SELECT uploaded_by FROM media WHERE id = 1')), 'files belong to the site');
        $temp->remove();
    }

    public function testDeclaresCloudStorageAndTheCdn(): void
    {
        $box = SiteFixture::secretBox();
        $s3 = static fn (string $region, ?string $endpoint, bool $pathStyle = false): SiteStorage => new SiteStorage()->withS3(new SiteS3Storage('media-bucket', $region, $endpoint, 'sites/one', $pathStyle, $box->encrypt('id'), $box->encrypt('secret')))->switchedToS3();

        self::assertSame([], [...new StorageProcessors(SiteFixture::site())->processors()]);
        self::assertSame([], [...new StorageProcessors(SiteFixture::site())->outboundHosts()]);

        $aws = new StorageProcessors(SiteFixture::site()->withStorage($s3('eu-west-2', null)));
        self::assertSame(['media.storage.aws', ['GB']], [[...$aws->processors()][0]->id, [...$aws->processors()][0]->countries]);
        self::assertSame(['media-bucket.s3.eu-west-2.amazonaws.com'], [...$aws->outboundHosts()]);
        self::assertSame(['ZZ'], [...new StorageProcessors(SiteFixture::site()->withStorage($s3('mars-north-1', null)))->processors()][0]->countries);

        $r2 = [...new StorageProcessors(SiteFixture::site()->withStorage($s3('auto', 'https://abc.eu.r2.cloudflarestorage.com')))->processors()];
        self::assertSame(['media.storage.cloudflare', ['EU']], [$r2[0]->id, $r2[0]->countries]);
        self::assertSame(['ZZ'], [...new StorageProcessors(SiteFixture::site()->withStorage($s3('auto', 'https://abc.r2.cloudflarestorage.com')))->processors()][0]->countries);

        self::assertSame('s3.example.org', [...new StorageProcessors(SiteFixture::site()->withStorage($s3('eu-west-1', 'https://s3.example.org', true)))->processors()][0]->name);
        $minio = new StorageProcessors(SiteFixture::site()->withStorage($s3('eu-west-1', 'http://minio:9000', true)));
        self::assertSame([], [...$minio->processors()], 'the operator\'s own server is not a third party');
        self::assertSame(['minio'], [...$minio->outboundHosts()]);
        self::assertSame([], [...new StorageProcessors(SiteFixture::site()->withStorage($s3('eu-west-1', 'http://10.0.0.5:9000', true)))->processors()]);

        $cdn = [...new StorageProcessors(SiteFixture::site()->withStorage(new SiteStorage(cdnUrl: 'https://cdn.example.org')))->processors()];
        self::assertSame(['media.cdn', 'cdn.example.org', []], [$cdn[0]->id, $cdn[0]->name, $cdn[0]->hosts]);
    }
}
