<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\BlockException;
use SightPress\Core\Block\Contract\HttpBlockContext;
use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Core\Block\SiteBlockContexts;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\CountingMediaLibrary;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(SiteBlockContext::class)]
#[CoversClass(SiteBlockContexts::class)]
#[CoversClass(BlockException::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteBlockContextTest extends TestCase
{
    public function testBuildsAddressesForTheLocale(): void
    {
        $fixture = new BlockFixture();
        $default = $fixture->context('en-GB');
        $other = $fixture->context('pt-BR');

        self::assertSame(['en-GB', 'pt-BR'], [$default->locale(), $other->locale()]);
        self::assertSame('https://demo.example/', $default->absoluteUrl('/'));
        self::assertSame('https://demo.example/events', $default->absoluteUrl('events'));
        self::assertSame('https://demo.example/pt-br', $other->absoluteUrl('/'));
        self::assertSame('https://demo.example/pt-br/eventos', $other->absoluteUrl('/eventos'));
        self::assertSame('https://demo.example/about', $other->linkUrl('/about'));
        self::assertSame('//evil.example/x', $other->linkUrl('//evil.example/x'));
        self::assertSame('mailto:hi@example.org', $other->linkUrl('mailto:hi@example.org'));
        self::assertSame('#top', $other->linkUrl('#top'));
    }

    public function testACaptionsFileIsAddressedOnThePagesOwnOrigin(): void
    {
        $fixture = new BlockFixture();
        $id = $fixture->addCaptions();
        $file = $fixture->media()->find($id) ?? self::fail('No file.');

        self::assertSame('/media/' . $file->key() . '/captions.vtt', $fixture->context()->captionsUrl($file));
    }

    public function testRefusesLocalesTheSiteIsNotWrittenIn(): void
    {
        $this->expectException(BlockException::class);
        $this->expectExceptionMessage('The site isn\'t written in "fr-FR".');

        new BlockFixture()->contexts(PageFixture::site(['en-GB']))->create('fr-FR', 'https://demo.example');
    }

    public function testGivesUrlsOfPublishedFilesOnly(): void
    {
        $fixture = new BlockFixture();
        $picture = $fixture->addMedia();
        $draft = $fixture->addMedia(published: false);
        $document = $fixture->addMedia(kind: MediaKind::Document, fileName: 'report.pdf');
        $key = $fixture->media()->find($picture)?->key();
        $context = $fixture->context();

        self::assertSame("https://demo.example/media/{$key}/lighthouse.jpg", $context->mediaUrl((string) $picture));
        self::assertSame("https://demo.example/media/{$key}/320w/lighthouse.jpg", $context->mediaUrl((string) $picture, '320w'));
        self::assertSame("https://demo.example/media/{$key}/lighthouse.jpg", $context->mediaUrl((string) $picture, 'thumbnail'));
        self::assertNull($context->mediaUrl((string) $draft));
        self::assertNull($context->mediaUrl('0'));
        self::assertNull($context->mediaUrl('abc'));
        self::assertNull($context->mediaUrl('999999'));
        self::assertStringEndsWith('/report.pdf', (string) $context->mediaUrl((string) $document));

        self::assertSame(
            ['src' => "/media/{$key}/lighthouse.jpg", 'srcset' => "/media/{$key}/320w/lighthouse.jpg 320w, /media/{$key}/640w/lighthouse.jpg 640w, /media/{$key}/lighthouse.jpg 1280w", 'width' => 1280, 'height' => 640],
            $context->image($picture),
        );
        self::assertNull($context->image($document));
        self::assertNull($context->image($draft));
    }

    public function testDescribesFilesInTheContentLocale(): void
    {
        $fixture = new BlockFixture();
        $context = $fixture->context();

        self::assertSame('PDF, 512 B', $context->fileDetails(self::file($fixture, 512)));
        self::assertSame('PDF, 2.3 MB', $context->fileDetails(self::file($fixture, 2_411_725)));
        self::assertSame('PDF, 2,3 MB', $fixture->context('pt-BR')->fileDetails(self::file($fixture, 2_411_725)));
        self::assertSame('PDF, 840 KB', $context->fileDetails(self::file($fixture, 860_160)));
        self::assertSame('PDF, 1.5 GB', $context->fileDetails(self::file($fixture, 1_610_612_736)));
        self::assertSame('PDF, 12 KB', $context->fileDetails(self::file($fixture, 12_300)));
        self::assertSame('KEY, 1 KB', $context->fileDetails(self::file($fixture, 1024, 'slides.key', 'application/x-keynote')));
    }

    public function testCarriesTheVisitorsRequest(): void
    {
        $fixture = new BlockFixture();
        $request = new \Nyholm\Psr7\ServerRequest('GET', 'https://demo.example/about');

        self::assertNull($fixture->context()->request());
        $context = $fixture->contexts()->create('en-GB', 'https://demo.example', $request);
        self::assertInstanceOf(HttpBlockContext::class, $context);
        self::assertSame($request, $context->request());
    }

    public function testLooksFilesUpOncePerPage(): void
    {
        $fixture = new BlockFixture();
        $one = $fixture->addMedia();
        $two = $fixture->addMedia();
        $library = new CountingMediaLibrary($fixture->media());
        $context = new SiteBlockContext('en-GB', 'https://demo.example', '', $library, new OriginMediaUrlGenerator(), $fixture->renderer(), new MediaTypes());

        $context->preload([$one, $two, $one]);
        $context->preload([]);
        $context->preload([$two]);
        self::assertNotNull($context->image($one));
        self::assertNotNull($context->mediaUrl((string) $two));
        self::assertSame([[$one, $two]], $library->calls);

        self::assertNull($context->mediaUrl('12345'));
        self::assertNull($context->mediaUrl('12345'));
        self::assertSame([[$one, $two], [12345]], $library->calls);

        self::assertStringContainsString('block-heading', $context->renderHtml([['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A', 'level' => 2]]]));
        self::assertSame('## A', $context->renderMarkdown([['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A', 'level' => 2]]]));
    }

    /**
     * @param non-empty-string $mimeType
     */
    private static function file(BlockFixture $fixture, int $size, string $name = 'a.pdf', string $mimeType = 'application/pdf'): MediaFile
    {
        $file = $fixture->media()->find($fixture->addMedia(kind: MediaKind::Document, fileName: $name, format: new MediaFormat($mimeType, MediaKind::Document, 'pdf', 'X'), size: $size));
        self::assertNotNull($file);

        return $file;
    }
}
