<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeAsset;
use SightPress\Core\Theme\ThemeAssets;
use SightPress\Core\Theme\ThemeTokens;
use SightPress\Core\Theme\TokenStylesheet;

#[CoversClass(ThemeAssets::class)]
#[UsesClass(Color::class)]
#[UsesClass(Theme::class)]
#[UsesClass(ThemeAsset::class)]
#[UsesClass(ThemeTokens::class)]
#[UsesClass(TokenStylesheet::class)]
#[UsesClass(TemplatePaths::class)]
final class ThemeAssetsTest extends TestCase
{
    private TemporaryDirectory $directory;

    private ThemeAssets $assets;

    protected function setUp(): void
    {
        $d = $this->directory = new TemporaryDirectory();
        $d->write('site/assets/css/site.css', 'site css');
        $d->write('default/assets/css/site.css', 'default css');
        $d->write('default/assets/js/site.js', 'default js');
        $d->write('default/assets/icons/logo.SVG', '<svg/>');
        $d->write('default/assets/notes.txt', 'not served');
        $d->write('default/theme.json', '{}');
        $d->write('secret.css', 'outside');
        symlink($d->path . '/secret.css', $d->path . '/default/assets/link.css');

        $tokens = new ThemeTokens(['text' => Color::fromHex('#222')], ['text' => Color::fromHex('#eee')]);
        $this->assets = new ThemeAssets(
            new Theme('site', 'Site', '1.0.0', $d->path . '/site', $tokens),
            new TemplatePaths([$d->path . '/site', $d->path . '/missing', $d->path . '/default'], TemplatePaths::core()),
            new TokenStylesheet(),
        );
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTheSiteThemeWinsAndTheDefaultThemeFillsIn(): void
    {
        $css = $this->assets->find('css/site.css');
        $js = $this->assets->find('js/site.js');
        $svg = $this->assets->find('icons/logo.SVG');
        self::assertNotNull($css);
        self::assertNotNull($js);
        self::assertNotNull($svg);

        self::assertSame('site css', $css->contents);
        self::assertSame('text/css; charset=utf-8', $css->contentType);
        self::assertSame('default js', $js->contents);
        self::assertSame('text/javascript; charset=utf-8', $js->contentType);
        self::assertSame('image/svg+xml', $svg->contentType);
    }

    public function testCoresOwnFilesComeLast(): void
    {
        $css = $this->assets->find('css/admin.css');
        self::assertNotNull($css);

        self::assertSame((string) file_get_contents(TemplatePaths::coreAssets() . '/css/admin.css'), $css->contents);
    }

    public function testTokensCssIsBuiltFromTheActiveTheme(): void
    {
        $asset = $this->assets->find(ThemeAssets::TOKENS);

        self::assertNotNull($asset);
        self::assertSame('text/css; charset=utf-8', $asset->contentType);
        self::assertStringContainsString('--color-text: #222222;', $asset->contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notServed(): iterable
    {
        yield 'missing' => ['css/missing.css'];
        yield 'unknown type' => ['notes.txt'];
        yield 'no extension' => ['css'];
        yield 'theme.json' => ['../theme.json'];
        yield 'traversal' => ['../../secret.css'];
        yield 'hidden file' => ['.htaccess.css'];
        yield 'absolute' => ['/etc/passwd.css'];
        yield 'backslash' => ['css\\site.css'];
        yield 'empty segment' => ['css//site.css'];
        yield 'symlink out of the folder' => ['link.css'];
        yield 'a folder' => ['css.css/'];
    }

    #[DataProvider('notServed')]
    public function testOnlyKnownFilesInsideAssetsAreServed(string $path): void
    {
        $this->directory->write('default/assets/css.css/.keep', '');

        self::assertNull($this->assets->find($path));
    }
}
