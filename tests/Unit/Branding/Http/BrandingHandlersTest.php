<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\Http\BrandingFileHandler;
use SightPress\Core\Branding\Http\ManifestHandler;
use SightPress\Core\Tests\Support\BrandingFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(BrandingFileHandler::class)]
#[CoversClass(ManifestHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingHandlersTest extends TestCase
{
    private BrandingFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BrandingFixture('The Riverside Public Library');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testServesTheFilesWithCachingAndAStrictPolicy(): void
    {
        $handler = new BrandingFileHandler($this->fixture->service(), new Psr17Factory());

        $icon = $handler->handle(new ServerRequest('GET', '/icon.svg'));
        $version = $this->fixture->service()->state()->version;
        self::assertNotSame('', $version, 'made on the first request');
        self::assertSame('image/svg+xml', $icon->getHeaderLine('Content-Type'));
        self::assertSame(BrandingFileHandler::SVG_POLICY, $icon->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $icon->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('"' . $version . '-icon.svg"', $icon->getHeaderLine('ETag'));
        self::assertSame((string) strlen((string) $icon->getBody()), $icon->getHeaderLine('Content-Length'));

        $share = $handler->handle(new ServerRequest('GET', '/branding/share.png?v=' . $version)->withQueryParams(['v' => $version]));
        self::assertSame('public, max-age=31536000, immutable', $share->getHeaderLine('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $share->getHeaderLine('Content-Security-Policy'));
        self::assertSame('image/png', $share->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=3600', $handler->handle(new ServerRequest('GET', '/favicon.ico')->withQueryParams(['v' => 'old']))->getHeaderLine('Cache-Control'));
        self::assertSame('image/x-icon', $handler->handle(new ServerRequest('GET', '/favicon.ico'))->getHeaderLine('Content-Type'));

        $again = $handler->handle(new ServerRequest('GET', '/icon.svg', ['If-None-Match' => 'W/"x", "' . $version . '-icon.svg"']));
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('', (string) $again->getBody());

        $this->fixture->service()->set(BrandAsset::ShareImage, MediaFixture::jpeg(1200, 630));
        self::assertSame('image/jpeg', $handler->handle(new ServerRequest('GET', '/branding/share.jpg'))->getHeaderLine('Content-Type'));

        foreach (['/branding/favicon.ico', '/share.png', '/branding/logo.svg', '/icon.svg/x'] as $path) {
            try {
                $handler->handle(new ServerRequest('GET', $path));
                self::fail('Served ' . $path);
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheManifestNamesTheSiteAndItsIcons(): void
    {
        $handler = new ManifestHandler($this->fixture->service(), $this->fixture->site, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/site.webmanifest'));

        self::assertSame('application/manifest+json', $response->getHeaderLine('Content-Type'));
        $manifest = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $v = '?v=' . $this->fixture->service()->state()->version;
        self::assertSame([
            'name' => 'The Riverside Public Library',
            'short_name' => 'The Riversi…',
            'lang' => 'en-GB',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            'theme_color' => '#1f4e79',
            'background_color' => '#1f4e79',
            'icons' => [
                ['src' => '/icon-192.png' . $v, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png' . $v, 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icon.svg' . $v, 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
        ], $manifest);

        $this->fixture->service()->set(BrandAsset::Icon, MediaFixture::png(512, 512));
        $uploaded = json_decode((string) $handler->handle(new ServerRequest('GET', '/site.webmanifest'))->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($uploaded);
        self::assertIsArray($uploaded['icons']);
        self::assertCount(2, $uploaded['icons']);

        $short = new BrandingFixture('Demo');
        $manifest = json_decode((string) new ManifestHandler($short->service(), $short->site, new Psr17Factory())->handle(new ServerRequest('GET', '/'))->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('Demo', $manifest['short_name']);
        $short->remove();
    }
}
