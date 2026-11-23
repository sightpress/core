<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Upload;

use Aws\S3\S3Client;
use DateTimeImmutable;
use Monolog\Level;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\DirectUploadAfterResponse;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadRefused;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\FakeS3;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use League\Flysystem\Filesystem;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DirectUploads::class)]
#[CoversClass(BucketUploads::class)]
#[CoversClass(DirectUploadAfterResponse::class)]
#[CoversClass(UploadSession::class)]
#[CoversClass(UploadSessions::class)]
#[CoversClass(MediaUploader::class)]
#[UsesNamespace('SightPress\Core')]
final class DirectUploadsTest extends TestCase
{
    private const string ORIGIN = 'https://demo.example';

    private const string CSV = "name,points\nAda,10\nGrace,12\n";

    private MediaFixture $media;

    private FakeS3 $s3;

    private Site $site;

    private SiteFilesystems $filesystems;

    private bool $cors = true;

    protected function setUp(): void
    {
        $this->s3 = new FakeS3();
        $box = SiteFixture::secretBox();
        $this->site = SiteFixture::site()->withStorage(new SiteStorage()->withS3(new SiteS3Storage('media-bucket', 'eu-west-2', 'http://minio:9000', 'sites/one', true, $box->encrypt('id'), $box->encrypt('secret')))->switchedToS3());
        $this->filesystems = new SiteFilesystems($box, '/tmp', fn (array $options): S3Client => new S3Client([...$options, 'http_handler' => $this->s3]));
        $this->media = new MediaFixture(new MediaLimits(documentBytes: 64 * 1024 * 1024));
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testAFileBecomesOneValidatedMediaRowAndLeavesNothingIncoming(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'Points.csv', self::CSV);

        $uploads->complete($session, $this->etags($session));
        self::assertSame(UploadSession::VALIDATING, $this->find($session)->state);
        $uploads->complete($this->find($session), []); // asking twice changes nothing

        $uploads->validate($session->id);

        $done = $this->find($session);
        self::assertTrue($done->isComplete());
        $media = $this->media->repository()->find((int) $done->mediaId);
        self::assertSame(hash('sha256', self::CSV), $media?->checksum());
        self::assertSame(self::CSV, $this->s3->objects['sites/one/' . MediaFiles::path($media->key(), 'csv')] ?? null);
        self::assertSame([], $this->incoming());
        self::assertSame([], $this->s3->uploads);
    }

    public function testAPictureIsReEncodedFromTheBucket(): void
    {
        $uploads = $this->uploads();
        $jpeg = MediaFixture::jpeg(700, 350, exif: true);
        $session = $this->arrived($uploads, 'photo.jpg', $jpeg);
        $uploads->complete($session, $this->etags($session));

        $uploads->validate($session->id);

        $media = $this->media->repository()->find((int) $this->find($session)->mediaId);
        self::assertSame([700, [320, 640]], [$media?->width(), $media?->variantWidths()]);
        self::assertStringNotContainsString('SightPressCam', $this->media->storage->read(MediaFiles::path((string) $media?->key(), 'jpg')));
        self::assertSame([], $this->incoming());
    }

    public function testTheSameFileTwiceIsOneRow(): void
    {
        $uploads = $this->uploads();
        $ids = [];
        foreach (['a.csv', 'b.csv'] as $name) {
            $session = $this->arrived($uploads, $name, self::CSV);
            $uploads->complete($session, $this->etags($session));
            $uploads->validate($session->id);
            $ids[] = $this->find($session)->mediaId;
        }

        self::assertCount(2, $ids);
        self::assertSame($ids[0], $ids[1]);
        self::assertSame([], $this->incoming());
    }

    public function testADisallowedRealTypeIsDeletedAndReported(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'report.csv', '<!doctype html><html><script>alert(1)</script></html>');
        $uploads->complete($session, $this->etags($session));

        $uploads->validate($session->id);

        $refused = $this->find($session);
        self::assertSame(UploadSession::REFUSED, $refused->state);
        self::assertIsArray($refused->refusal);
        self::assertSame([], $this->incoming());
    }

    public function testARefusedFileTheBucketWontDeleteIsStillReported(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'report.csv', '<!doctype html><html><script>alert(1)</script></html>');
        $uploads->complete($session, $this->etags($session));
        $this->s3->failing['AbortMultipartUpload'] = 'AccessDenied';

        $uploads->validate($session->id);

        self::assertSame(UploadSession::REFUSED, $this->find($session)->state);
    }

    public function testASizeThatChangedIsRefused(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $this->s3->objects['sites/one/incoming/' . $session->id] = 'shorter';

        $uploads->validate($session->id);

        self::assertSame(UploadSession::REFUSED, $this->find($session)->state);
    }

    public function testAStorageProblemIsTriedAgainByTheScheduler(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $this->s3->failing['CopyObject'] = 'SlowDown';

        $uploads->validate($session->id);
        self::assertSame(UploadSession::VALIDATING, $this->find($session)->state);

        $this->s3->failing = [];
        self::assertSame(1, $uploads->validateWaiting());
        self::assertTrue($this->find($session)->isComplete());
        self::assertSame(0, $uploads->validateWaiting());
    }

    public function testACheckThatStoppedHalfwayIsPickedUpLater(): void
    {
        $uploads = $this->uploads();
        $sessions = new UploadSessions($this->media->database->connection);
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $sessions->transition($session->id, UploadSession::VALIDATING, UploadSession::PROCESSING, $this->media->clock->now());

        $uploads->validate($session->id);
        self::assertSame(UploadSession::PROCESSING, $this->find($session)->state, 'another process may still be on it');
        self::assertSame(0, $uploads->validateWaiting());

        $this->media->clock->advance(1801);
        self::assertSame(1, $uploads->validateWaiting());
        self::assertTrue($this->find($session)->isComplete());
    }

    public function testNothingToCheck(): void
    {
        $uploads = $this->uploads();
        $uploads->validate(str_repeat('0', 32));
        $session = $this->arrived($uploads, 'a.csv', self::CSV);

        $uploads->validate($session->id);

        self::assertSame(UploadSession::RECEIVING, $this->find($session)->state);
    }

    public function testTheCheckStartsAfterTheCompleteRequestOnly(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $job = new DirectUploadAfterResponse($uploads);
        $answered = fn (string $method, string $path, int $status): RequestAnswered => new RequestAnswered(new ServerRequest($method, 'https://demo.example' . $path), $status, new AnsweredResource(), true, new DateTimeImmutable());

        $job($answered('GET', '/admin/media/direct/' . $session->id . '/complete', 202));
        $job($answered('POST', '/admin/media/direct/' . $session->id . '/complete', 409));
        $job($answered('POST', '/admin/media/direct/' . $session->id . '/urls', 202));
        self::assertSame(UploadSession::VALIDATING, $this->find($session)->state);

        $job($answered('POST', '/admin/media/direct/' . $session->id . '/complete', 202));
        self::assertTrue($this->find($session)->isComplete());
    }

    public function testCompleteRefusesMissingWrongSizedOrChangedParts(): void
    {
        $uploads = $this->uploads();
        $body = str_repeat('a', ChunkedUploads::CHUNK_BYTES) . 'tail';
        $session = $uploads->start(7, 'big.csv', 'text/csv', strlen($body), self::ORIGIN);
        self::assertNotNull($session);
        self::assertSame(2, $session->partCount());
        $remote = (string) $session->remoteId;
        $this->s3->uploads[$remote]['parts'][1] = substr($body, 0, ChunkedUploads::CHUNK_BYTES);

        $this->assertRefused(409, static fn () => $uploads->complete($session, [1 => md5('x')]));

        $this->s3->uploads[$remote]['parts'][2] = 'a much longer last part than announced';
        self::assertSame([1], array_keys($uploads->received($session)), 'a part of the wrong size does not count');
        $this->assertRefused(409, fn () => $uploads->complete($session, $this->etags($session)));

        $this->s3->uploads[$remote]['parts'][2] = 'tail';
        $this->assertRefused(409, static fn () => $uploads->complete($session, [1 => md5('changed'), 2 => md5('tail')]));

        $this->s3->failing['CompleteMultipartUpload'] = 'InternalError';
        $this->assertRefused(409, fn () => $uploads->complete($session, $this->etags($session)));
        $this->s3->failing = [];

        $uploads->complete($session, $this->etags($session));
        self::assertSame($body, $this->s3->objects['sites/one/incoming/' . $session->id]);
    }

    public function testPartAddressesAreSignedForOnePartOfThisUpload(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->start(7, 'big.csv', 'text/csv', 20 * MediaLimits::MEGABYTE, self::ORIGIN);
        self::assertNotNull($session);

        $urls = $uploads->urls($session, [2, 3]);

        self::assertSame([2, 3], array_keys($urls));
        parse_str((string) parse_url($urls[2], PHP_URL_QUERY), $query);
        self::assertSame(['2', $session->remoteId, '900'], [$query['partNumber'], $query['uploadId'], $query['X-Amz-Expires']]);
        self::assertStringStartsWith('http://minio:9000/media-bucket/sites/one/incoming/' . $session->id . '?', $urls[2]);
        self::assertSame(4 * MediaLimits::MEGABYTE, $session->partSize(3));
        self::assertSame(ChunkedUploads::CHUNK_BYTES, $session->partSize(2));

        $this->assertRefused(400, static fn () => $uploads->urls($session, []));
        $this->assertRefused(400, static fn () => $uploads->urls($session, [4]));
        $this->assertRefused(400, static fn () => $uploads->urls($session, [0]));
        $this->assertRefused(400, static fn () => $uploads->urls($session, range(1, DirectUploads::URL_BATCH + 1)));
    }

    public function testAnEndedUploadSignsNothingMore(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $waiting = $this->find($session);

        $this->assertRefused(409, static fn () => $uploads->urls($waiting, [1]));
        self::assertSame([], $uploads->received($waiting));

        $uploads->validate($session->id);
        $done = $this->find($session);
        $this->assertRefused(409, static fn () => $uploads->urls($done, [1]));
        $this->assertRefused(409, static fn () => $uploads->complete($done, []));
        self::assertSame([], $uploads->received($done));
    }

    public function testThePartSizeKeepsTheFileWithinThePartLimit(): void
    {
        self::assertSame(ChunkedUploads::CHUNK_BYTES, DirectUploads::partBytes(MediaLimits::MEGABYTE));
        self::assertSame(ChunkedUploads::CHUNK_BYTES, DirectUploads::partBytes(5 * 1024 ** 3));
        $huge = 200 * 1024 ** 3;
        self::assertLessThanOrEqual(DirectUploads::MAX_PARTS, ceil($huge / DirectUploads::partBytes($huge)));
        self::assertSame(0, DirectUploads::partBytes($huge) % MediaLimits::MEGABYTE);
    }

    public function testASiteWhoseBucketRefusesBrowsersUsesTheServer(): void
    {
        $this->cors = false;
        $uploads = $this->uploads();

        self::assertSame(DirectUploadCheck::NO_CORS, $uploads->status(self::ORIGIN));
        self::assertNull($uploads->start(7, 'a.csv', 'text/csv', 10, self::ORIGIN));
    }

    public function testABucketThatWontStartAnUploadUsesTheServer(): void
    {
        $this->s3->failing['CreateMultipartUpload'] = 'AccessDenied';

        self::assertNull($this->uploads()->start(7, 'a.csv', 'text/csv', 10, self::ORIGIN));
    }

    public function testTheSameChecksComeFirst(): void
    {
        $uploads = $this->uploads();

        $this->assertRefused(415, static fn () => $uploads->start(7, 'a.exe', 'application/x-msdownload', 10, self::ORIGIN));
        self::assertSame([], $this->s3->uploads);
    }

    public function testOnlyTheOwnerFindsADirectUpload(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->start(7, 'a.csv', 'text/csv', 10, self::ORIGIN);
        self::assertNotNull($session);

        self::assertSame($session->id, $uploads->find($session->id, 7)?->id);
        self::assertNull($uploads->find($session->id, 8));
        self::assertNull($this->chunked()->find($session->id, 7), 'the temporary-folder protocol can\'t touch it');
    }

    public function testCancelAbortsTheUploadInTheBucket(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        self::assertCount(1, $this->s3->uploads);

        $uploads->cancel($session);
        $uploads->cancel($session); // the bucket no longer knows it: nothing to do

        self::assertSame([], $this->s3->uploads);
        self::assertNull($uploads->find($session->id, 7));
    }

    public function testAProblemCancellingIsReported(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $this->s3->failing['AbortMultipartUpload'] = 'AccessDenied';

        $this->expectException(StorageException::class);
        $uploads->cancel($session);
    }

    public function testExpiryAbortsIdleUploadsAndRetriesThoseItCouldNot(): void
    {
        $uploads = $this->uploads();
        $idle = $this->arrived($uploads, 'a.csv', self::CSV);
        $finished = $this->arrived($uploads, 'b.csv', self::CSV . 'more');
        $uploads->complete($finished, $this->etags($finished));
        $uploads->validate($finished->id);

        $this->media->clock->advance(86401);
        $this->s3->failing['AbortMultipartUpload'] = 'SlowDown';
        self::assertSame(0, $uploads->purgeExpired());
        self::assertNotNull($uploads->find($idle->id, 7));

        $this->s3->failing = [];
        self::assertSame(1, $uploads->purgeExpired());
        self::assertSame([], $this->s3->uploads);
        self::assertNull($uploads->find($idle->id, 7));
        self::assertNotNull($uploads->find($finished->id, 7));

        $this->media->clock->advance(7 * 86400);
        self::assertSame(1, $uploads->purgeExpired());
        self::assertNull($uploads->find($finished->id, 7));
    }

    public function testBucketHelpers(): void
    {
        $local = new BucketUploads(SiteFixture::site(), $this->filesystems);
        self::assertFalse($local->isCloud());
        self::assertSame('', BucketUploads::text(['not text']));
        self::assertSame(0, BucketUploads::number('many'));

        $this->expectException(StorageException::class);
        $local->s3();
    }

    public function testAMissingFileInStorageIsAStorageProblem(): void
    {
        $bucket = new BucketUploads($this->site, $this->filesystems);

        $this->expectException(StorageException::class);
        $bucket->read('incoming/missing', static fn (string $data): null => null);
    }

    public function testAFileThatCantBeWrittenLocallyIsAStorageProblem(): void
    {
        $uploads = $this->uploads();
        $session = $this->arrived($uploads, 'a.csv', self::CSV);
        $uploads->complete($session, $this->etags($session));
        $file = new BucketUploads($this->site, $this->filesystems)->file($this->find($session));

        $this->expectException(StorageException::class);
        @$file->saveTo($this->media->directory->path . '/no/such/folder/file');
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertRefused(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('The upload was not refused.');
        } catch (UploadRefused $refused) {
            self::assertSame($status, $refused->status, $refused->getMessage());
        }
    }

    private function arrived(DirectUploads $uploads, string $name, string $body): UploadSession
    {
        $session = $uploads->start(7, $name, 'text/csv', strlen($body), self::ORIGIN);
        self::assertNotNull($session);
        $this->s3->uploads[(string) $session->remoteId]['parts'][1] = $body;

        return $session;
    }

    /**
     * @return array<int, string>
     */
    private function etags(UploadSession $session): array
    {
        return array_map('md5', $this->s3->uploads[(string) $session->remoteId]['parts']);
    }

    private function find(UploadSession $session): UploadSession
    {
        $found = new UploadSessions($this->media->database->connection)->find($session->id);
        self::assertNotNull($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function incoming(): array
    {
        return array_values(array_filter(array_keys($this->s3->objects), static fn (string $key): bool => str_contains($key, '/incoming/')));
    }

    private function chunked(): ChunkedUploads
    {
        $config = new TenantConfig('/srv', Environment::Test, false, '/srv/r', '/srv/s', '/srv/c', '/srv/l', Level::Error, uploadTempPath: rtrim($this->media->directory->path, '/') . '/parts');
        $sessions = new UploadSessions($this->media->database->connection);

        return new ChunkedUploads($sessions, new UploadFolder($config, $this->site), new MediaTypes(), $this->media->limits, $this->uploader(), ServerLimitsFixture::standard(), $config, $this->media->clock);
    }

    /**
     * The media library on the same fake bucket as the uploads.
     */
    private function uploader(): MediaUploader
    {
        $files = new MediaFiles(new Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($this->media->directory->path . '/site', lazyRootCreation: true)));

        return new MediaUploader(new MediaTypes(), $this->media->limits, $this->media->images(), $files, $this->media->repository(), $this->media->clock, $this->media);
    }

    private function uploads(): DirectUploads
    {
        $headers = $this->cors ? ['Access-Control-Allow-Origin' => self::ORIGIN, 'Access-Control-Allow-Methods' => 'PUT'] : [];
        $check = new DirectUploadCheck($this->site, new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204, 'response_headers' => $headers])), new ArrayAdapter());

        return new DirectUploads(new UploadSessions($this->media->database->connection), new BucketUploads($this->site, $this->filesystems), $check, $this->chunked(), $this->uploader(), $this->media->clock);
    }
}
