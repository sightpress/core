<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Cli\ThemePreview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Cli\ThemePreview\PreviewSite;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\ThemeException;

/**
 * Renders the preview of the default theme through the whole kernel, twice.
 */
#[CoversClass(PreviewSite::class)]
#[UsesNamespace('SightPress\Core')]
final class PreviewSiteTest extends TestCase
{
    /** @var array<string, string> */
    private static array $files = [];

    public static function setUpBeforeClass(): void
    {
        self::$files = self::render();
    }

    public function testRendersEveryBlockOfThePreviewPageWithTheThemeApplied(): void
    {
        $html = self::$files['/'];

        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $html);
        self::assertStringContainsString('<title>SightPress Default - Riverside Reading Room</title>', $html);
        self::assertStringContainsString('<h1>SightPress Default</h1>', $html);
        self::assertStringContainsString('<a class="button" href="https://github.com/sightpress/theme-default" aria-label="Download SightPress Default">Download</a>', $html);
        foreach (['text' => 'Text and buttons', 'media' => 'Pictures and files', 'layout' => 'Side by side', 'sound' => 'Sound and video', 'contact' => 'Contact'] as $id => $heading) {
            self::assertStringContainsString(sprintf('<a href="#%s">%s</a>', $id, $heading), $html);
            self::assertStringContainsString(sprintf('<h2 class="block-heading" id="%s">%s</h2>', $id, $heading), $html);
        }
        foreach (['block-call-to-action', 'block-image', 'block-file-download', 'block-columns', 'block-gallery', 'block-audio', 'block-video'] as $class) {
            self::assertStringContainsString('class="' . $class, $html);
        }
        self::assertStringContainsString('action="#" method="post"', $html);
        self::assertStringContainsString('This is a preview', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="assets/css/site.css">', $html);
        self::assertStringNotContainsString('src="/', $html);
        self::assertStringNotContainsString('href="/', $html);
        self::assertStringContainsString(':root', self::$files['/assets/tokens.css']);
        self::assertArrayHasKey('/assets/js/site.js', self::$files);
        $media = array_filter(array_keys(self::$files), static fn (string $path): bool => preg_match('#^/media/[0-9a-f]{32}/[^/]+$#', $path) === 1);
        self::assertCount(5, $media, 'The picture, the PDF, the recording, the video and its captions.');
    }

    public function testTwoRunsGiveTheSameBytes(): void
    {
        self::assertSame(self::$files, self::render());
    }

    public function testSaysWhenTheSiteCantBeMade(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $this->expectException(ThemeException::class);
            $this->expectExceptionMessage('The preview site could not be created');
            new PreviewSite($directory->path)->render(PreviewTheme::fromDirectory(TemplatePaths::defaultTheme()), 'not a locale');
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function render(): array
    {
        $directory = new TemporaryDirectory();
        try {
            return new PreviewSite($directory->path)->render(PreviewTheme::fromDirectory(TemplatePaths::defaultTheme()), 'en-GB');
        } finally {
            $directory->remove();
        }
    }
}
