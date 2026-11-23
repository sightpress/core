<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Upload;

use Monolog\Level;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Infrastructure\Storage\SiteFilesystems;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadCleanupJob;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadRefused;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(ChunkedUploads::class)]
#[CoversClass(UploadCleanupJob::class)]
#[CoversClass(UploadFolder::class)]
#[CoversClass(UploadRefused::class)]
#[CoversClass(UploadSession::class)]
#[CoversClass(UploadSessions::class)]
#[UsesNamespace('SightPress\Core')]
final class ChunkedUploadsTest extends TestCase
{
    private const string CSV = "name,points\nAda,10\nGrace,12\n";

    private MediaFixture $media;

    private Site $site;

    private ?float $free = null;

    protected function setUp(): void
    {
        $this->media = new MediaFixture(new MediaLimits(documentBytes: 4096));
        $this->site = SiteFixture::site();
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testPartsBecomeOneMediaRowAndTheTemporaryFileGoes(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'Points.csv', 'text/csv', strlen(self::CSV));

        $first = $uploads->append($session, 0, Stream::create(substr(self::CSV, 0, 10)), 10, hash('sha256', substr(self::CSV, 0, 10), true));
        self::assertNull($first['result']);
        self::assertSame(10, $uploads->find($session->id, 7)?->receivedBytes);

        $last = $uploads->append($first['session'], 10, Stream::create(substr(self::CSV, 10)), null, null);

        self::assertNotNull($last['result']);
        self::assertSame(hash('sha256', self::CSV), $last['result']->media->checksum());
        self::assertSame(7, $last['result']->media->uploadedBy());
        self::assertFileDoesNotExist($this->folder()->path($session->id));
        $done = $uploads->find($session->id, 7);
        self::assertTrue($done->isComplete());
        self::assertSame($last['result']->media->id(), $done->mediaId);
    }

    public function testAWrongOffsetIsAConflict(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);

        $this->assertRefused(409, static fn () => $uploads->append($session, 5, Stream::create('abc'), 3, null));
    }

    public function testADamagedPartIsRemovedAndCanBeSentAgain(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', strlen(self::CSV));

        $this->assertRefused(460, static fn () => $uploads->append($session, 0, Stream::create('name,'), 5, str_repeat("\0", 32)));
        self::assertSame(0, filesize($this->folder()->path($session->id)));
        self::assertSame(0, $uploads->find($session->id, 7)?->receivedBytes);

        self::assertNotNull($uploads->append($session, 0, Stream::create(self::CSV), null, null)['result']);
    }

    public function testAPartCutOffOnTheWayIsRemoved(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);

        $this->assertRefused(400, static fn () => $uploads->append($session, 0, Stream::create('abc'), 10, null));
        self::assertSame(0, filesize($this->folder()->path($session->id)));
    }

    public function testWhatARequestLeftBehindIsOverwritten(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', strlen(self::CSV));
        file_put_contents($this->folder()->path($session->id), 'torn write');

        self::assertNotNull($uploads->append($session, 0, Stream::create(self::CSV), null, null)['result']);
    }

    public function testAPartLargerThanWhatIsLeftIsRefused(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 2);

        $this->assertRefused(413, static fn () => $uploads->append($session, 0, Stream::create('abc'), 3, null));
        self::assertSame(0, filesize($this->folder()->path($session->id)));
    }

    public function testAStreamThatStopsGivingDataEndsThePart(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        $body = self::createStub(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturn('');

        self::assertSame(0, $uploads->append($session, 0, $body, null, null)['session']->receivedBytes);
    }

    public function testADiskThatFillsUpRefusesThePart(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        symlink('/dev/full', $this->folder()->path($session->id));

        $this->assertRefused(507, static fn () => $uploads->append($session, 0, Stream::create('abc'), 3, null));
    }

    public function testAPartWhileAnotherIsBeingWrittenIsAConflict(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        $held = fopen($this->folder()->path($session->id), 'c+b');
        self::assertIsResource($held);
        flock($held, LOCK_EX);

        try {
            $this->assertRefused(409, static fn () => $uploads->append($session, 0, Stream::create('abc'), 3, null));
        } finally {
            fclose($held);
        }
    }

    public function testAnEndedUploadTakesNoMoreParts(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', strlen(self::CSV));
        $uploads->append($session, 0, Stream::create(self::CSV), null, null);

        $done = $uploads->find($session->id, 7);
        self::assertNotNull($done);
        $this->assertRefused(409, static fn () => $uploads->append($done, strlen(self::CSV), Stream::create(''), 0, null));
        // A request that was already on its way when the upload ended leaves no file behind.
        $this->assertRefused(409, static fn () => $uploads->append($session, 0, Stream::create('abc'), 3, null));
        self::assertFileDoesNotExist($this->folder()->path($session->id));
    }

    public function testEarlierPartsMissingOnThisServerAreExplained(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        $next = $uploads->append($session, 0, Stream::create('abc'), 3, null)['session'];

        file_put_contents($this->folder()->path($session->id), 'a');
        $this->assertRefused(410, static fn () => $uploads->append($next, 3, Stream::create('d'), 1, null));

        unlink($this->folder()->path($session->id));
        $this->assertRefused(410, static fn () => $uploads->append($next, 3, Stream::create('d'), 1, null));
    }

    public function testAFolderThatCantBeOpenedIsExplained(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        rmdir(dirname($this->folder()->path($session->id)));

        $this->assertRefused(410, static fn () => $uploads->append($session, 0, Stream::create('abc'), 3, null));
    }

    public function testAFakeFileIsRefusedAfterTheLastPartAndDeleted(): void
    {
        $uploads = $this->uploads();
        $html = '<!doctype html><html><script>alert(1)</script></html>';
        $session = $uploads->create(7, 'photo.csv', 'text/csv', strlen($html));

        $this->assertRefused(422, static fn () => $uploads->append($session, 0, Stream::create($html), null, null));

        self::assertFileDoesNotExist($this->folder()->path($session->id));
        self::assertNull($uploads->find($session->id, 7));
        self::assertSame([], $this->media->stored());
    }

    public function testTheSameFileTwiceIsOneMediaRow(): void
    {
        $uploads = $this->uploads();
        $first = $uploads->append($uploads->create(7, 'a.csv', 'text/csv', strlen(self::CSV)), 0, Stream::create(self::CSV), null, null);
        $second = $uploads->append($uploads->create(7, 'b.csv', 'text/csv', strlen(self::CSV)), 0, Stream::create(self::CSV), null, null);

        self::assertTrue($second['result']?->duplicate);
        self::assertSame($first['result']?->media->id(), $second['result']->media->id());
    }

    public function testRefusedBeforeTheFirstPart(): void
    {
        $uploads = $this->uploads(max: 8192);

        $this->assertRefused(400, static fn () => $uploads->create(7, " \0/ ", 'text/csv', 10));
        $this->assertRefused(400, static fn () => $uploads->create(7, 'a.csv', 'text/csv', 0));
        $this->assertRefused(413, static fn () => $uploads->create(7, 'a.csv', 'text/csv', 8193));
        $this->assertRefused(413, static fn () => $uploads->create(7, 'a.csv', 'text/csv', 4097));
        $this->assertRefused(415, static fn () => $uploads->create(7, 'a.exe', 'application/x-msdownload', 10));
    }

    public function testTheTypeCanComeFromTheNameAlone(): void
    {
        self::assertSame('a.pdf', $this->uploads()->create(7, 'a.pdf', '', 10)->fileName);
    }

    public function testEachPersonHasACapOnUploadsInProgress(): void
    {
        $uploads = $this->uploads();
        for ($i = 0; $i < ChunkedUploads::MAX_ACTIVE_PER_USER; ++$i) {
            $uploads->create(7, 'a.csv', 'text/csv', 10);
        }

        $this->assertRefused(429, static fn () => $uploads->create(7, 'a.csv', 'text/csv', 10));
        self::assertSame(8, $uploads->create(8, 'a.csv', 'text/csv', 10)->userId);
    }

    public function testEachPersonHasACapOnBytesInProgress(): void
    {
        $uploads = $this->uploads(max: 4096);
        $uploads->create(7, 'a.csv', 'text/csv', 4096);
        $uploads->create(7, 'b.csv', 'text/csv', 4096);

        $this->assertRefused(429, static fn () => $uploads->create(7, 'c.csv', 'text/csv', 1));
    }

    public function testNotEnoughFreeSpaceIsRefused(): void
    {
        $this->free = 100.0;
        $uploads = $this->uploads();
        $uploads->create(7, 'a.csv', 'text/csv', 60);

        $this->assertRefused(507, static fn () => $uploads->create(8, 'b.csv', 'text/csv', 41));
        self::assertSame(40, $uploads->create(8, 'b.csv', 'text/csv', 40)->totalBytes);
    }

    public function testAFolderThatCantBeWrittenIsRefused(): void
    {
        $this->media->directory->write('blocked', 'a file, not a folder');
        $uploads = $this->uploads(temp: rtrim($this->media->directory->path, '/') . '/blocked');

        $this->assertRefused(507, static fn () => $uploads->create(7, 'a.csv', 'text/csv', 10));
    }

    public function testSomeoneElsesUploadLooksLikeNone(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 10);

        self::assertNull($uploads->find($session->id, 8));
        self::assertNull($uploads->find(str_repeat('0', 32), 7));
    }

    public function testCancelRemovesTheFileAndTheSession(): void
    {
        $uploads = $this->uploads();
        $session = $uploads->create(7, 'a.csv', 'text/csv', 20);
        $uploads->append($session, 0, Stream::create('abc'), 3, null);

        $uploads->cancel($session);

        self::assertFileDoesNotExist($this->folder()->path($session->id));
        self::assertNull($uploads->find($session->id, 7));
    }

    public function testAbandonedAndOldSessionsArePurgedAndTheJobSaysSo(): void
    {
        $uploads = $this->uploads();
        $idle = $uploads->create(7, 'idle.csv', 'text/csv', 20);
        $uploads->append($idle, 0, Stream::create('abc'), 3, null);
        $finished = $uploads->create(7, 'done.csv', 'text/csv', strlen(self::CSV));
        $uploads->append($finished, 0, Stream::create(self::CSV), null, null);

        $this->media->clock->advance(24 * 3600 - 1);
        self::assertSame(0, $uploads->purgeExpired());

        $this->media->clock->advance(2);
        $job = new ScheduleDue($this->site, $this->jobServices($uploads), $this->media->clock->now());
        new UploadCleanupJob()($job);
        self::assertSame(['Uploads: removed 1 expired session.'], $job->lines());
        self::assertNull($uploads->find($idle->id, 7));
        self::assertFileDoesNotExist($this->folder()->path($idle->id));
        self::assertNotNull($uploads->find($finished->id, 7));

        $this->media->clock->advance(5 * 86400);
        $quiet = new ScheduleDue($this->site, $this->jobServices($uploads), $this->media->clock->now());
        new UploadCleanupJob()($quiet);
        self::assertNotNull($uploads->find($finished->id, 7));
        self::assertSame([], $quiet->lines());

        $this->media->clock->advance(86400);
        $two = $uploads->create(7, 'x.csv', 'text/csv', 5);
        $this->media->clock->advance(86401);
        $last = new ScheduleDue($this->site, $this->jobServices($uploads), $this->media->clock->now());
        new UploadCleanupJob()($last);
        self::assertSame(['Uploads: removed 2 expired sessions.'], $last->lines());
        self::assertNull($uploads->find($two->id, 7));
    }

    public function testListsEverySessionNewestFirst(): void
    {
        $uploads = $this->uploads();
        $older = $uploads->create(7, 'older.csv', 'text/csv', 10);
        $this->media->clock->advance(60);
        $newer = $uploads->create(8, 'newer.csv', 'text/csv', 20);

        $all = new UploadSessions($this->media->database->connection)->all();

        self::assertSame([$newer->id, $older->id], array_map(static fn (UploadSession $session): string => $session->id, $all));
    }

    public function testThePartSizeFollowsTheServer(): void
    {
        self::assertSame(ChunkedUploads::CHUNK_BYTES, $this->uploads()->chunkBytes());
        self::assertSame(2 * MediaLimits::MEGABYTE, $this->uploads(server: ServerLimitsFixture::standard(upload: 2 * MediaLimits::MEGABYTE))->chunkBytes());
        self::assertSame(5 * 1024 ** 3, $this->uploads(max: 5 * 1024 ** 3)->maxBytes());
    }

    public function testTheFolderMeasuresItsFreeSpace(): void
    {
        $folder = new UploadFolder($this->config($this->parts()), $this->site);
        self::assertTrue($folder->prepare());
        self::assertGreaterThan(0, $folder->free());
        self::assertTrue($folder->prepare());

        $unknown = new UploadFolder($this->config($this->parts()), $this->site, static fn (string $path): false => false);
        self::assertNull($unknown->free());
        $unknown->delete(str_repeat('a', 32));
    }

    private function jobServices(ChunkedUploads $uploads): ArrayContainer
    {
        $sessions = new UploadSessions($this->media->database->connection);
        $bucket = new BucketUploads($this->site, new SiteFilesystems(SiteFixture::secretBox(), '/tmp'));
        $check = new DirectUploadCheck($this->site, new MockHttpClient(), new ArrayAdapter());

        return new ArrayContainer([
            ChunkedUploads::class => $uploads,
            DirectUploads::class => new DirectUploads($sessions, $bucket, $check, $uploads, $this->media->uploader(), $this->media->clock),
        ]);
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

    /**
     * @return non-empty-string
     */
    private function parts(): string
    {
        return rtrim($this->media->directory->path, '/') . '/parts';
    }

    /**
     * @param non-empty-string|null $temp
     * @param positive-int          $max
     */
    private function config(?string $temp = null, int $max = TenantConfig::DEFAULT_MAX_CHUNKED_BYTES): TenantConfig
    {
        return new TenantConfig('/srv', Environment::Test, false, '/srv/r', '/srv/s', '/srv/c', '/srv/l', Level::Error, uploadTempPath: $temp ?? $this->parts(), maxChunkedBytes: $max);
    }

    /**
     * @param non-empty-string|null $temp
     */
    private function folder(?string $temp = null): UploadFolder
    {
        return new UploadFolder($this->config($temp), $this->site, fn (string $path): float => $this->free ?? 1e12);
    }

    /**
     * @param positive-int          $max
     * @param non-empty-string|null $temp
     */
    private function uploads(int $max = TenantConfig::DEFAULT_MAX_CHUNKED_BYTES, ?string $temp = null, ?ServerLimits $server = null): ChunkedUploads
    {
        return new ChunkedUploads(
            new UploadSessions($this->media->database->connection),
            $this->folder($temp),
            new MediaTypes(),
            $this->media->limits,
            $this->media->uploader(),
            $server ?? ServerLimitsFixture::standard(),
            $this->config($temp, $max),
            $this->media->clock,
        );
    }
}
