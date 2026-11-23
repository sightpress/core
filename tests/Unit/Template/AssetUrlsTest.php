<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\AssetUrls;

#[CoversClass(AssetUrls::class)]
final class AssetUrlsTest extends TestCase
{
    public function testBuildsVersionedUrls(): void
    {
        self::assertSame('/assets/css/site.css', new AssetUrls()->url('css/site.css'));
        self::assertSame('/assets/css/site.css?v=abc123', new AssetUrls('/assets', 'abc123')->url('/css/site.css'));
        self::assertSame('/static/img/my%20logo.svg', new AssetUrls('/static/', '')->url('img/my logo.svg'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'parent folder' => ['../config/sites.php'];
        yield 'parent folder inside' => ['css/../../secret'];
        yield 'other site' => ['https://example.org/x.js'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'backslash' => ['css\\site.css'];
    }

    #[DataProvider('invalidPaths')]
    public function testRefusesPathsOutsideTheAssets(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AssetUrls()->url($path);
    }

    public function testACdnServesStylesButNeverScriptsOrTheAdminPanel(): void
    {
        $urls = new AssetUrls('/assets/', 'v1', ['css/site.css' => 'https://cdn.example/themes/t/1/assets-abc/css/site.css', 'js/site.js' => 'https://cdn.example/themes/t/1/assets-abc/js/site.js'], 'https://cdn.example');

        self::assertSame('https://cdn.example/themes/t/1/assets-abc/css/site.css', $urls->url('css/site.css'));
        self::assertSame('https://cdn.example/assets/tokens.css?v=v1', $urls->url('tokens.css'));
        self::assertSame('/assets/js/site.js?v=v1', $urls->url('js/site.js'));
        self::assertSame('/assets/admin/tokens.css?v=v1', $urls->url('admin/tokens.css'));
    }
}
