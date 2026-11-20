<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\PreviewFakeTheme;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\ThemeException;

#[CoversClass(PreviewTheme::class)]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Template')]
final class PreviewThemeTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('theme.json', (string) file_get_contents(TemplatePaths::defaultTheme() . '/theme.json'));
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTheDefaultThemeIsReadFromItsPackage(): void
    {
        $theme = PreviewTheme::fromDirectory(TemplatePaths::defaultTheme());

        self::assertSame('default', $theme->id);
        self::assertSame('SightPress Default', $theme->name);
        self::assertStringStartsWith('Calm greys', $theme->description);
        self::assertSame('https://github.com/sightpress/theme-default', $theme->downloadUrl);
        self::assertSame(TemplatePaths::defaultTheme(), $theme->directory);
        self::assertNotNull($theme->previewDescription);
        self::assertSame('SightPress Default', $theme->variable('name'));
        self::assertSame($theme->description, $theme->variable('description'));
        self::assertSame($theme->version, $theme->variable('version'));
        self::assertSame($theme->downloadUrl, $theme->variable('download_url'));
    }

    public function testAFolderWithoutComposerJsonIsDescribedFromThemeJson(): void
    {
        $theme = PreviewTheme::fromDirectory($this->directory->path);

        self::assertSame('The SightPress Default theme for SightPress.', $theme->description);
        self::assertSame('https://packagist.org/packages/sightpress/theme-default', $theme->downloadUrl);
        self::assertNull($theme->previewDescription);
    }

    public function testReadsComposerJson(): void
    {
        $this->directory->write('composer.json', (string) json_encode([
            'name' => 'acme/theme-plain',
            'description' => ' A plain theme. ',
            'homepage' => 'https://acme.example/plain',
            'extra' => ['sightpress' => ['theme' => 'Acme\\Missing']],
        ]));
        $theme = PreviewTheme::fromDirectory($this->directory->path);

        self::assertSame('A plain theme.', $theme->description);
        self::assertSame('https://acme.example/plain', $theme->downloadUrl);
    }

    public function testATheBlankDescriptionAndNoHomepageFallBack(): void
    {
        $this->directory->write('composer.json', (string) json_encode(['name' => 'acme/theme-plain', 'description' => ' ', 'extra' => 'none']));
        $theme = PreviewTheme::fromDirectory($this->directory->path);

        self::assertSame('The SightPress Default theme for SightPress.', $theme->description);
        self::assertSame('https://packagist.org/packages/acme/theme-plain', $theme->downloadUrl);
    }

    public function testTheThemeClassHasTheLastWord(): void
    {
        $this->directory->write('composer.json', (string) json_encode(['extra' => ['sightpress' => ['theme' => PreviewFakeTheme::class]]]));
        $theme = PreviewTheme::fromDirectory($this->directory->path);

        self::assertSame('Harbour', $theme->name);
        self::assertSame('Calm blues with large type.', $theme->description);
        self::assertSame('1.2.0', $theme->version);
        self::assertSame('A dark blue top bar and a large white heading.', $theme->previewDescription);
    }

    public function testRefusesComposerJsonThatIsNotJson(): void
    {
        $this->directory->write('composer.json', '{');

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('composer.json is not valid JSON');
        PreviewTheme::fromDirectory($this->directory->path);
    }

    public function testComposerJsonThatIsNotAnObjectIsIgnored(): void
    {
        $this->directory->write('composer.json', '"text"');

        self::assertSame('https://packagist.org/packages/sightpress/theme-default', PreviewTheme::fromDirectory($this->directory->path)->downloadUrl);
    }

    public function testRefusesAnUnknownVariable(): void
    {
        $this->expectExceptionObject(new ThemeException('The preview page has no variable "theme.author".'));
        PreviewTheme::fromDirectory(TemplatePaths::defaultTheme())->variable('author');
    }
}
