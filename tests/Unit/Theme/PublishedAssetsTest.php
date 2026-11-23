<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\Theme\PublishedAssets;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Theme\ThemeManifest;

#[CoversClass(PublishedAssets::class)]
#[UsesNamespace('SightPress\Core')]
final class PublishedAssetsTest extends TestCase
{
    private TestProject $project;

    private PublishedAssets $assets;

    private ThemeManifest $theme;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        $this->assets = new PublishedAssets($kernel->config());
        $this->theme = Services::get($kernel->container(), ThemeRegistry::class)->default()->manifest();
    }

    protected function tearDown(): void
    {
        @chmod($this->base(), 0o777);
        $this->project->remove();
    }

    private function base(): string
    {
        return $this->project->path() . '/public/themes/' . rawurlencode($this->theme->id) . '/' . rawurlencode($this->theme->version);
    }

    private function source(string $css = 'body{}'): string
    {
        $this->project->directory->write('src-assets/css/site.css', $css);
        $this->project->directory->write('src-assets/js/site.js', 'run()');

        return $this->project->path() . '/src-assets';
    }

    public function testPublishesUnderAChecksumFolderAndListsTheFiles(): void
    {
        self::assertSame([], $this->assets->urls($this->theme, 'https://cdn.example'));
        $this->assets->publish($this->theme, $this->source());
        $urls = $this->assets->urls($this->theme, 'https://cdn.example');

        self::assertSame(['css/site.css', 'js/site.js'], array_keys($urls));
        self::assertMatchesRegularExpression('#^https://cdn\.example/themes/.+/assets-[0-9a-f]{16}/css/site\.css$#', $urls['css/site.css']);
        $local = $this->project->path() . '/public' . substr($urls['css/site.css'], strlen('https://cdn.example'));
        self::assertSame('body{}', file_get_contents(rawurldecode($local)));
    }

    public function testChangedFilesGetANewAddress(): void
    {
        $this->assets->publish($this->theme, $this->source());
        $first = $this->assets->urls($this->theme, 'https://cdn.example')['css/site.css'];
        $this->assets->publish($this->theme, $this->source('body{color:red}'));

        self::assertNotSame($first, $this->assets->urls($this->theme, 'https://cdn.example')['css/site.css']);
    }

    public function testAThemeWithoutAssetsPublishesNothing(): void
    {
        $this->assets->publish($this->theme, $this->project->path() . '/none');
        self::assertSame([], $this->assets->urls($this->theme, 'https://cdn.example'));
    }

    public function testABrokenListIsIgnored(): void
    {
        $this->project->directory->write(substr($this->base(), strlen($this->project->path()) + 1) . '/assets.json', '{"folder":5}');
        self::assertSame([], $this->assets->urls($this->theme, 'https://cdn.example'));
        $this->project->directory->write(substr($this->base(), strlen($this->project->path()) + 1) . '/assets.json', '{"folder":"a","files":["x.css",5]}');
        self::assertSame(['x.css'], array_keys($this->assets->urls($this->theme, 'https://cdn.example')));
    }

    public function testAFolderThatCannotBeWrittenIsReported(): void
    {
        $source = $this->source();
        $this->project->directory->write('public/themes/' . rawurlencode($this->theme->id) . '/' . rawurlencode($this->theme->version), 'a file in the way');
        $this->expectException(ThemeException::class);
        $this->assets->publish($this->theme, $source);
    }

    public function testAListThatCannotBeWrittenIsReported(): void
    {
        $source = $this->source();
        mkdir($this->base() . '/assets.json', 0o777, true);
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('list of published theme files');
        $this->assets->publish($this->theme, $source);
    }
}
