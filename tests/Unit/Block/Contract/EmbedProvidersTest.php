<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\EmbedProviders;

#[CoversClass(EmbedProviders::class)]
final class EmbedProvidersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function links(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube embed' => ['https://youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', 'https://player.vimeo.com/video/76979871?dnt=1'];
        yield 'vimeo unlisted' => ['https://vimeo.com/76979871/8272103f6e/', 'vimeo', 'https://player.vimeo.com/video/76979871?dnt=1&h=8272103f6e'];
        yield 'map' => ['https://www.openstreetmap.org/#map=16/51.5072/-0.1276', 'openstreetmap', 'https://www.openstreetmap.org/export/embed.html?bbox=-0.133093%2C51.504453%2C-0.122107%2C51.509947&layer=mapnik'];
        yield 'map with a marker' => ['https://www.openstreetmap.org/?mlat=38.7223&mlon=-9.1393', 'openstreetmap', 'https://www.openstreetmap.org/export/embed.html?bbox=-9.150286%2C38.716807%2C-9.128314%2C38.727793&layer=mapnik&marker=38.7223%2C-9.1393'];
    }

    #[DataProvider('links')]
    public function testTurnsALinkIntoTheFrameOfItsProvider(string $url, string $provider, string $src): void
    {
        self::assertSame(['provider' => $provider, 'src' => $src], EmbedProviders::resolve($url));
        self::assertMatchesRegularExpression('#' . EmbedProviders::URL_PATTERN . '#', $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'not https' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'no host' => ['https:///watch'];
        yield 'not a link' => ['::'];
        yield 'another site' => ['https://example.org/watch?v=dQw4w9WgXcQ'];
        yield 'youtube without an id' => ['https://www.youtube.com/watch?list=abc'];
        yield 'youtube with a bad id' => ['https://youtu.be/short'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks'];
        yield 'map without a place' => ['https://www.openstreetmap.org/about'];
        yield 'map zoomed out too far' => ['https://www.openstreetmap.org/#map=0/51.5/-0.1'];
        yield 'map off the world' => ['https://www.openstreetmap.org/?mlat=89&mlon=0'];
    }

    #[DataProvider('refused')]
    public function testRefusesLinksNoProviderCanShow(string $url): void
    {
        self::assertNull(EmbedProviders::resolve($url));
    }

    public function testListsTheOriginsOfTheFrames(): void
    {
        self::assertSame(['https://www.youtube-nocookie.com', 'https://player.vimeo.com', 'https://www.openstreetmap.org'], EmbedProviders::frameOrigins());
    }
}
