<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeAsset;
use SightPress\Core\Theme\ThemeAssets;
use SightPress\Core\Theme\ThemeTokens;
use SightPress\Core\Theme\TokenStylesheet;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(ThemeAssetHandler::class)]
#[UsesClass(ThemeAssets::class)]
#[UsesClass(ThemeAsset::class)]
#[UsesClass(Theme::class)]
#[UsesClass(ThemeTokens::class)]
#[UsesClass(TokenStylesheet::class)]
#[UsesClass(Color::class)]
#[UsesClass(TemplatePaths::class)]
final class ThemeAssetHandlerTest extends TestCase
{
    private TemporaryDirectory $directory;

    private ThemeAssetHandler $handler;

    protected function setUp(): void
    {
        $d = $this->directory = new TemporaryDirectory();
        $d->write('theme/assets/css/site.css', 'body {}');
        $d->write('theme/assets/icons/logo.svg', '<svg/>');
        $d->write('theme/assets/fonts/body.woff2', 'font');
        $factory = new Psr17Factory();
        $tokens = new ThemeTokens(['text' => Color::fromHex('#222')], ['text' => Color::fromHex('#eee')]);
        $this->handler = new ThemeAssetHandler(
            new ThemeAssets(
                new Theme('t', 'T', '1', $d->path . '/theme', $tokens),
                new TemplatePaths([$d->path . '/theme'], TemplatePaths::core()),
                new TokenStylesheet(),
            ),
            $factory,
            $factory,
        );
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testAVersionedFileIsCachedForAYear(): void
    {
        $response = $this->handler->handle(self::request('css/site.css', '?v=abc'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body {}', (string) $response->getBody());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('7', $response->getHeaderLine('Content-Length'));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(new ThemeAsset('body {}', '')->etag(), $response->getHeaderLine('ETag'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    public function testFontsMayBeFetchedFromACdnButOtherFilesGetNoCorsHeader(): void
    {
        self::assertSame('*', $this->handler->handle(self::request('fonts/body.woff2', '?v=abc'))->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($this->handler->handle(self::request('css/site.css', '?v=abc'))->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testAnUnversionedFileIsCachedBriefly(): void
    {
        $response = $this->handler->handle(self::request('css/site.css', '?v='));

        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testAMatchingEtagAnswersNotModified(): void
    {
        $etag = new ThemeAsset('body {}', '')->etag();
        $response = $this->handler->handle(self::request('css/site.css')->withHeader('If-None-Match', '"other", ' . $etag));

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame($etag, $response->getHeaderLine('ETag'));
        self::assertFalse($response->hasHeader('Content-Type'));
    }

    public function testHeadSendsTheHeadersWithoutTheBody(): void
    {
        $response = $this->handler->handle(self::request('css/site.css')->withMethod('HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('7', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testSvgFilesMayRunNothing(): void
    {
        $response = $this->handler->handle(self::request('icons/logo.svg'));

        self::assertSame("default-src 'none'; style-src 'unsafe-inline'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTheGeneratedTokensAreServed(): void
    {
        $response = $this->handler->handle(self::request('tokens.css'));

        self::assertStringContainsString('--color-text: #222222;', (string) $response->getBody());
    }

    public function testAnUnknownFileIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->handler->handle(self::request('css/missing.css'));
    }

    public function testARequestWithoutAPathIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->handler->handle(new ServerRequest('GET', '/assets/'));
    }

    private static function request(string $path, string $query = ''): ServerRequest
    {
        parse_str(ltrim($query, '?'), $params);

        return new ServerRequest('GET', '/assets/' . $path . $query)
            ->withQueryParams($params)
            ->withAttribute(ThemeAssetHandler::PATH_ATTRIBUTE, $path);
    }
}
