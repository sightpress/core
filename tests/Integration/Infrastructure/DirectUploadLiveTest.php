<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Infrastructure;

use Aws\S3\S3Client;
use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Direct uploads against a real S3 API (MinIO in the `Storage live` workflow): parts sent to signed addresses, joined,
 * checked and moved; a refused file and a cancelled upload leave nothing in the bucket.
 */
#[CoversNothing]
final class DirectUploadLiveTest extends TestCase
{
    private const string ORIGIN = 'https://live-test.example';

    private MediaFixture $media;

    private S3Client $client;

    private Site $site;

    private SiteFilesystems $filesystems;

    private string $bucket;

    protected function setUp(): void
    {
        $endpoint = (string) getenv('SIGHTPRESS_TEST_S3_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set SIGHTPRESS_TEST_S3_ENDPOINT to run against MinIO.');
        }
        $key = (string) getenv('SIGHTPRESS_TEST_S3_KEY');
        $secret = (string) getenv('SIGHTPRESS_TEST_S3_SECRET');
        $this->bucket = 'sp-direct-' . bin2hex(random_bytes(6));
        $this->client = new S3Client(['version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => $key, 'secret' => $secret]]);
        $this->client->createBucket(['Bucket' => $this->bucket]);
        $box = SiteFixture::secretBox();
        $this->site = SiteFixture::site()->withStorage(new SiteStorage()->withS3(new SiteS3Storage($this->bucket, 'us-east-1', $endpoint, 'sites/live', true, $box->encrypt($key), $box->encrypt($secret)))->switchedToS3());
        $this->filesystems = new SiteFilesystems($box, '/tmp');
        $this->media = new MediaFixture();
    }

    protected function tearDown(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->media->remove();
        foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket])->search('Contents[].Key') as $key) {
            self::assertIsString($key);
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        }
        $this->client->deleteBucket(['Bucket' => $this->bucket]);
    }

    public function testAFileInTwoPartsBecomesAValidatedMediaRow(): void
    {
        $body = "\0\0\0\x20ftypisom\0\0\x02\0isomiso2avc1mp41" . random_bytes(ChunkedUploads::CHUNK_BYTES + 1024);
        $uploads = $this->uploads();
        $session = $uploads->start(7, 'talk.mp4', 'video/mp4', strlen($body), self::ORIGIN);
        self::assertNotNull($session);
        self::assertSame(2, $session->partCount());

        $etags = $this->send($uploads, $session, $body);
        self::assertSame($etags, $uploads->received($session));
        $uploads->complete($session, $etags);
        $uploads->validate($session->id);

        $done = new UploadSessions($this->media->database->connection)->find($session->id);
        self::assertTrue($done?->isComplete());
        $media = $this->media->repository()->find((int) $done->mediaId);
        self::assertSame([hash('sha256', $body), 'video/mp4', strlen($body)], [$media?->checksum(), $media?->mimeType(), $media?->size()]);
        $stored = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => 'sites/live/' . MediaFiles::path((string) $media?->key(), 'mp4')]);
        self::assertInstanceOf(StreamInterface::class, $stored['Body']);
        self::assertSame(hash('sha256', $body), hash('sha256', (string) $stored['Body']));
        $this->assertNothingIncoming();
    }

    public function testADisallowedRealTypeIsDeletedFromTheBucket(): void
    {
        $body = '<!doctype html><html><script>alert(1)</script></html>';
        $uploads = $this->uploads();
        $session = $uploads->start(7, 'talk.mp4', 'video/mp4', strlen($body), self::ORIGIN);
        self::assertNotNull($session);
        $uploads->complete($session, $this->send($uploads, $session, $body));

        $uploads->validate($session->id);

        self::assertSame(UploadSession::REFUSED, new UploadSessions($this->media->database->connection)->find($session->id)?->state);
        $this->assertNothingIncoming();
    }

    public function testCancelAndExpiryLeaveNoParts(): void
    {
        $uploads = $this->uploads();
        foreach (['cancel', 'expire'] as $how) {
            $session = $uploads->start(7, $how . '.mp4', 'video/mp4', 1024, self::ORIGIN);
            self::assertNotNull($session);
            $this->send($uploads, $session, str_repeat('x', 1024));
            if ($how === 'cancel') {
                $uploads->cancel($session);
            }
        }
        $open = $this->client->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [];
        self::assertIsArray($open);
        self::assertCount(1, $open);

        $this->media->clock->advance(86401);
        self::assertSame(1, $uploads->purgeExpired());

        $this->assertNothingIncoming();
    }

    /**
     * Sends each part to its signed address, as a browser does.
     *
     * @return array<int, string> part number => ETag
     */
    private function send(DirectUploads $uploads, UploadSession $session, string $body): array
    {
        $http = HttpClient::create();
        $etags = [];
        $numbers = range(1, $session->partCount());
        foreach ($uploads->urls($session, $numbers) as $number => $url) {
            $part = substr($body, ($number - 1) * (int) $session->partBytes, $session->partSize($number));
            $response = $http->request('PUT', $url, ['body' => $part]);
            self::assertSame(200, $response->getStatusCode());
            $etags[$number] = trim($response->getHeaders()['etag'][0], '"');
        }

        return $etags;
    }

    private function assertNothingIncoming(): void
    {
        self::assertSame([], $this->client->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? []);
        self::assertSame([], $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => 'sites/live/incoming/'])['Contents'] ?? []);
    }

    private function uploads(): DirectUploads
    {
        $s3 = $this->site->storage->s3;
        self::assertInstanceOf(SiteS3Storage::class, $s3);
        $config = new TenantConfig('/srv', Environment::Test, false, '/srv/r', '/srv/s', '/srv/c', '/srv/l', Level::Error, uploadTempPath: rtrim($this->media->directory->path, '/') . '/parts');
        $sessions = new UploadSessions($this->media->database->connection);
        $files = new MediaFiles($this->filesystems->bucket($s3));
        $uploader = new MediaUploader(new MediaTypes(), new MediaLimits(), $this->media->images(), $files, $this->media->repository(), $this->media->clock, $this->media);
        $chunked = new ChunkedUploads($sessions, new UploadFolder($config, $this->site), new MediaTypes(), new MediaLimits(), $uploader, ServerLimitsFixture::standard(), $config, $this->media->clock);
        $check = new DirectUploadCheck($this->site, HttpClient::create(), new ArrayAdapter());

        return new DirectUploads($sessions, new BucketUploads($this->site, $this->filesystems), $check, $chunked, $uploader, $this->media->clock);
    }
}
