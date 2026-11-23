<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Http;

use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\Http\MediaFileHandler;
use SightPress\Core\Media\MediaAccess;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\SiteFixture;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(MediaFileHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaFileHandlerTest extends TestCase
{
    private const string LAST_MODIFIED = 'Sat, 14 Nov 2026 12:00:00 GMT';

    private MediaFixture $fixture;

    private MediaFileHandler $handler;

    private TestHandler $log;

    private Media $photo;

    private Media $notes;

    private ArraySiteSettings $settings;

    private MediaUrlSigner $signer;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture();
        $uploader = $this->fixture->uploader();
        $this->photo = $uploader->upload($this->fixture->file('photo.jpg', MediaFixture::jpeg(700, 400)), 'Harbour at dawn.jpg', 1)->media;
        $this->notes = $uploader->upload($this->fixture->file('notes.txt', 'Opening times: 9 to 5.'), 'Notas de reunião.txt', 1)->media;
        $this->log = new TestHandler();
        $this->settings = new ArraySiteSettings();
        $this->signer = new MediaUrlSigner(SiteFixture::SECRET, SiteFixture::site()->id, new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z')));
        $this->handler = $this->handlerFor(SiteFixture::site());
    }

    private function handlerFor(Site $site): MediaFileHandler
    {
        $http = new Psr17Factory();

        return new MediaFileHandler(
            $this->fixture->repository(),
            $this->fixture->files(),
            new MediaAccess(),
            $http,
            $http,
            new Logger('test', [$this->log]),
            $this->signer,
            $this->settings,
            $site,
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function signedGet(Media $media, string $query, array $attributes = []): ResponseInterface
    {
        parse_str($query, $params);
        $request = new ServerRequest('GET', 'https://demo.example/media/' . $media->key() . '/' . $media->fileName() . '?' . $query)
            ->withQueryParams($params)
            ->withAttribute(MediaFileHandler::KEY_ATTRIBUTE, $media->key())
            ->withAttribute(MediaFileHandler::NAME_ATTRIBUTE, $attributes['name'] ?? $media->fileName());

        return $this->handler->handle($request);
    }

    public function testAValidSignatureOpensAPrivateFileAndIsNeverCached(): void
    {
        $response = $this->signedGet($this->photo, $this->signer->query($this->photo->key(), null, MediaUrlSigner::PREVIEW_TTL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testASignatureForAnotherFileOpensNothing(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->signedGet($this->photo, $this->signer->query($this->notes->key(), null, MediaUrlSigner::PREVIEW_TTL));
    }

    public function testARedirectToTheRightNameKeepsTheSignature(): void
    {
        $query = $this->signer->query($this->photo->key(), null, MediaUrlSigner::PREVIEW_TTL);
        $response = $this->signedGet($this->photo, $query, ['name' => 'old-name.jpg']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/media/' . $this->photo->key() . '/' . $this->photo->fileName() . '?' . $query, $response->getHeaderLine('Location'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAMembersOnlySitesFilesAreNeverPubliclyCached(): void
    {
        $this->publish($this->photo);
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);

        self::assertSame('private, no-store', $this->get($this->photo)->getHeaderLine('Cache-Control'));
    }

    public function testBehindACdnPublishedFilesAreCachedBriefly(): void
    {
        $this->publish($this->photo);
        $site = SiteFixture::site();
        $this->handler = $this->handlerFor($site->withStorage($site->storage->withCdn('https://cdn.example')));

        self::assertSame('public, max-age=300, s-maxage=300', $this->get($this->photo)->getHeaderLine('Cache-Control'));
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testAPublishedPictureIsShownAndCachedForAYear(): void
    {
        $this->publish($this->photo);

        $response = $this->get($this->photo);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'ETag' => ['"' . $this->photo->key() . '"'],
            'Last-Modified' => [self::LAST_MODIFIED],
            'Cache-Control' => ['public, max-age=31536000, immutable'],
            'Accept-Ranges' => ['bytes'],
            'X-Content-Type-Options' => ['nosniff'],
            'Content-Security-Policy' => ["default-src 'none'; sandbox"],
            'Content-Type' => ['image/jpeg'],
            'Content-Disposition' => ['inline; filename="harbour-at-dawn.jpg"; filename*=UTF-8\'\'Harbour%20at%20dawn.jpg'],
            'Content-Length' => [(string) $this->photo->size()],
        ], $response->getHeaders());
        self::assertSame($this->stored($this->photo), (string) $response->getBody());
    }

    public function testASmallerCopyHasItsOwnTag(): void
    {
        $this->publish($this->photo);

        $response = $this->get($this->photo, ['width' => '320']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"' . $this->photo->key() . '-320"', $response->getHeaderLine('ETag'));
        self::assertSame($this->stored($this->photo, 320), (string) $response->getBody());
        self::assertSame(320, getimagesizefromstring((string) $response->getBody())[0] ?? null);
    }

    public function testDocumentsAreDownloadedUnderTheirOriginalName(): void
    {
        $this->publish($this->notes);

        $response = $this->get($this->notes);

        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="notas-de-reuniao.txt"; filename*=UTF-8\'\'Notas%20de%20reuni%C3%A3o.txt', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('Opening times: 9 to 5.', (string) $response->getBody());
    }

    public function testUnpublishedFilesAreOnlyForPeopleWhoManageMediaAndNeverCached(): void
    {
        $response = $this->get($this->notes, actor: new Actor(3, ['media.upload']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));

        $this->assertNotFound($this->notes, actor: new Actor(4, ['page.view']));
        $this->assertNotFound($this->notes);
    }

    public function testUnknownFilesAndCopiesAreNotFound(): void
    {
        $this->publish($this->photo);

        $this->assertNotFound($this->photo, ['key' => str_repeat('f', 32)]);
        $this->assertNotFound($this->photo, ['key' => null]);
        $this->assertNotFound($this->photo, ['width' => '500']);
        $this->assertNotFound($this->notes, ['width' => '320']);
        self::assertSame([], $this->log->getRecords());
    }

    public function testAWrongNameOrWidthRedirectsToTheRightAddress(): void
    {
        $this->publish($this->photo);

        $renamed = $this->get($this->photo, ['name' => 'old-name.jpg']);
        self::assertSame(301, $renamed->getStatusCode());
        self::assertSame('/media/' . $this->photo->key() . '/harbour-at-dawn.jpg', $renamed->getHeaderLine('Location'));
        self::assertSame('public, max-age=31536000, immutable', $renamed->getHeaderLine('Cache-Control'));

        $padded = $this->get($this->photo, ['width' => '0320']);
        self::assertSame(301, $padded->getStatusCode());
        self::assertSame('/media/' . $this->photo->key() . '/320w/harbour-at-dawn.jpg', $padded->getHeaderLine('Location'));

        $private = $this->get($this->notes, ['name' => 'x.txt'], new Actor(3, ['media.upload']));
        self::assertSame('private, no-store', $private->getHeaderLine('Cache-Control'));
    }

    public function testAFileMissingFromStorageIsLoggedAndNotFound(): void
    {
        $this->publish($this->photo);
        $this->fixture->storage->delete(MediaFiles::path($this->photo->key(), 'jpg', 640));
        $this->fixture->storage->delete(MediaFiles::path($this->photo->key(), 'jpg'));

        $this->assertNotFound($this->photo, ['width' => '640']);
        $this->assertNotFound($this->photo);

        self::assertSame(
            [['ERROR', ['key' => $this->photo->key(), 'width' => '640 px']], ['ERROR', ['key' => $this->photo->key(), 'width' => 'original']]],
            array_map(static fn ($record): array => [$record->level->getName(), $record->context], $this->log->getRecords()),
        );
        self::assertStringContainsString('missing from the site\'s storage', $this->log->getRecords()[0]->message);
    }

    public function testRevalidationsAreAnsweredWithNotModified(): void
    {
        $this->publish($this->photo);
        $etag = '"' . $this->photo->key() . '"';

        $cases = [
            [['If-None-Match' => $etag], 304],
            [['If-None-Match' => '"other", W/' . $etag], 304],
            [['If-None-Match' => '*'], 304],
            [['If-None-Match' => '"other"', 'If-Modified-Since' => self::LAST_MODIFIED], 200],
            [['If-Modified-Since' => self::LAST_MODIFIED], 304],
            [['If-Modified-Since' => 'Sun, 15 Nov 2026 08:00:00 GMT'], 304],
            [['If-Modified-Since' => 'Fri, 13 Nov 2026 12:00:00 GMT'], 200],
            [['If-Modified-Since' => 'yesterday'], 200],
        ];
        foreach ($cases as [$headers, $status]) {
            $response = $this->get($this->photo, headers: $headers);
            self::assertSame($status, $response->getStatusCode(), (string) json_encode($headers));
            self::assertSame($etag, $response->getHeaderLine('ETag'));
            if ($status === 304) {
                self::assertSame('', (string) $response->getBody());
                self::assertFalse($response->hasHeader('Content-Type'));
            }
        }
    }

    public function testDatesAreComparedInWholeSeconds(): void
    {
        $key = str_repeat('e', 32);
        $file = new Media($key, str_repeat('d', 64), new MediaFormat('text/plain', MediaKind::Document, 'txt', 'Text'), 'a.txt', 'a.txt', 2, null, null, [], null, new DateTimeImmutable('2026-11-14 13:00:00.750 +01:00'));
        $file->publish($this->fixture->clock->now());
        $this->fixture->repository()->save($file);
        $this->fixture->storage->write(MediaFiles::path($key, 'txt'), 'hi');

        $response = $this->get($file, headers: ['If-Modified-Since' => self::LAST_MODIFIED]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame(self::LAST_MODIFIED, $response->getHeaderLine('Last-Modified'));
    }

    public function testASingleRangeIsSentAsPartialContent(): void
    {
        $this->publish($this->notes);

        $response = $this->get($this->notes, headers: ['Range' => 'bytes=8-12']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 8-12/22', $response->getHeaderLine('Content-Range'));
        self::assertSame('5', $response->getHeaderLine('Content-Length'));
        self::assertSame('times', (string) $response->getBody());
    }

    public function testARangePastTheEndIsRefused(): void
    {
        $this->publish($this->notes);

        $response = $this->get($this->notes, headers: ['Range' => 'bytes=50-']);

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */22', $response->getHeaderLine('Content-Range'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testIfRangeSendsTheWholeFileWhenItNoLongerMatches(): void
    {
        $this->publish($this->notes);
        $etag = '"' . $this->notes->key() . '"';

        $cases = [
            [$etag, 206],
            [self::LAST_MODIFIED, 206],
            ['"something-else"', 200],
            ['Fri, 13 Nov 2026 12:00:00 GMT', 200],
        ];
        foreach ($cases as [$ifRange, $status]) {
            $response = $this->get($this->notes, headers: ['Range' => 'bytes=0-6', 'If-Range' => $ifRange]);
            self::assertSame($status, $response->getStatusCode(), $ifRange);
        }
    }

    public function testHeadRequestsGetTheHeadersOnly(): void
    {
        $this->publish($this->notes);

        $whole = $this->get($this->notes, method: 'HEAD');
        self::assertSame([200, '22', ''], [$whole->getStatusCode(), $whole->getHeaderLine('Content-Length'), (string) $whole->getBody()]);

        $part = $this->get($this->notes, method: 'HEAD', headers: ['Range' => 'bytes=-4']);
        self::assertSame([206, '4', 'bytes 18-21/22', ''], [$part->getStatusCode(), $part->getHeaderLine('Content-Length'), $part->getHeaderLine('Content-Range'), (string) $part->getBody()]);
    }

    private function publish(Media $media): void
    {
        $media->publish($this->fixture->clock->now());
        $this->fixture->repository()->save($media);
    }

    private function stored(Media $media, ?int $width = null): string
    {
        return $this->fixture->storage->read(MediaFiles::path($media->key(), $media->extension(), $width));
    }

    /**
     * @param array<string, string|null> $attributes key, width and name, taken from the file unless given
     * @param array<string, string>      $headers
     */
    private function get(Media $media, array $attributes = [], ?Actor $actor = null, array $headers = [], string $method = 'GET'): ResponseInterface
    {
        $request = new ServerRequest($method, 'https://example.org/media/x', $headers);
        $attributes += ['key' => $media->key(), 'name' => $media->fileName()];
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $request = $request->withAttribute($name, $value);
            }
        }
        if ($actor !== null) {
            $request = $request->withAttribute(Actor::class, $actor);
        }

        return $this->handler->handle($request);
    }

    /**
     * @param array<string, string|null> $attributes
     */
    private function assertNotFound(Media $media, array $attributes = [], ?Actor $actor = null): void
    {
        try {
            $this->get($media, $attributes, $actor);
            self::fail('Expected "not found".');
        } catch (HttpNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }
}
