<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\ThemeCheck;
use SightPress\Core\Theme\ThemeChecker;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;
use SightPress\ThemeDefault\DefaultTheme;

#[CoversClass(ThemeChecker::class)]
#[CoversClass(ThemeCheck::class)]
#[UsesNamespace('SightPress\Core\Theme')]
final class ThemeCheckerTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTheDefaultThemePasses(): void
    {
        $check = new ThemeChecker()->check(new DefaultTheme());

        self::assertTrue($check->passes(), implode("\n", $check->problems));
        self::assertSame('SightPress Default', $check->name);
        self::assertGreaterThan(100, $check->checks);
    }

    public function testAThemeFolderWithoutAThemeClassPasses(): void
    {
        $check = new ThemeChecker()->check($this->copy());

        self::assertTrue($check->passes(), implode("\n", $check->problems));
        self::assertSame('Harbour', $check->name);
    }

    public function testReadsTheThemeClassOfAFolder(): void
    {
        $folder = $this->copy();
        $this->directory->write('harbour/composer.json', (string) json_encode(['extra' => ['sightpress' => ['theme' => HarbourTheme::class]]]));
        $theme = (string) file_get_contents($folder . '/theme.json');
        file_put_contents($folder . '/theme.json', str_replace(['"name": "acme-harbour"', '"version": "1.2.0"'], ['"name": "acme-port"', '"version": "1.3.0"'], $theme));

        self::assertSame([
            'theme.json is for the theme "acme-port", but the theme\'s manifest says "acme-harbour": use the same id in both.',
            'theme.json says version 1.3.0, but the theme\'s manifest says 1.2.0: use the same version in both.',
        ], new ThemeChecker()->check($folder)->problems);
    }

    public function testAComposerJsonThatIsNotJsonIsIgnored(): void
    {
        $folder = $this->copy();
        $this->directory->write('harbour/composer.json', '{');

        self::assertTrue(new ThemeChecker()->check($folder)->passes());
    }

    public function testAFolderWithoutThemeJsonFails(): void
    {
        $check = new ThemeChecker()->check($this->directory->path . '/nothing/');

        self::assertSame('nothing', $check->name);
        self::assertStringContainsString('has no theme.json file', $check->problems[0]);
        self::assertSame('/', new ThemeChecker()->check('')->name);
    }

    public function testNamesContrastProblemsMissingTemplatesAndAShellWithoutTheHeadPartial(): void
    {
        $folder = $this->copy();
        $theme = (string) file_get_contents($folder . '/theme.json');
        file_put_contents($folder . '/theme.json', preg_replace('/"text-muted": "#[0-9a-f]{6}"/', '"text-muted": "#d0d0d0"', $theme, 1));
        unlink($folder . '/templates/partials/search-form.html.twig');
        unlink($folder . '/templates/layouts/sidebar.html.twig');
        $master = (string) file_get_contents($folder . '/templates/master.html.twig');
        file_put_contents($folder . '/templates/master.html.twig', str_replace("{% include '@seo/head.html.twig' %}", '', $master));
        $this->directory->write('harbour/composer.json', (string) json_encode(['extra' => ['sightpress' => ['theme' => HarbourTheme::class]]]));
        // The class's files are the fixture's, so check the copy as a folder with the same manifest.
        $problems = new ThemeChecker()->check($folder)->problems;

        self::assertNotEmpty(array_filter($problems, static fn (string $problem): bool => str_contains($problem, 'text-muted')));
        self::assertContains('The required template templates/partials/search-form.html.twig is missing.', $problems);
        self::assertContains('The required template templates/layouts/sidebar.html.twig is missing.', $problems);
        self::assertContains('templates/master.html.twig must include core\'s head partial inside <head>: {% include \'@seo/head.html.twig\' %}. It holds the description, canonical links and structured data.', $problems);
    }

    public function testRefusesScreenshotsAndPreviewOutputThatWouldShip(): void
    {
        $folder = $this->copy();
        $this->directory->write('harbour/assets/screenshot.png', 'png');
        $this->directory->write('harbour/screenshot-dark.webp', 'webp');
        $this->directory->write('harbour/theme-preview/default/index.html', 'html');
        $this->directory->write('harbour/build/theme-preview/index.html', 'html');
        $this->directory->write('harbour/vendor/acme/screenshot.png', 'png');
        $this->directory->write('harbour/node_modules/x/screenshot.png', 'png');
        $this->directory->write('harbour/assets/screenshots.css', 'fine');

        self::assertSame([
            'Remove assets/screenshot.png from the theme: a theme ships no screenshot or preview output; they are made from its preview page.',
            'Remove build/ from the theme: a theme ships no screenshot or preview output; they are made from its preview page.',
            'Remove screenshot-dark.webp from the theme: a theme ships no screenshot or preview output; they are made from its preview page.',
            'Remove theme-preview/ from the theme: a theme ships no screenshot or preview output; they are made from its preview page.',
        ], new ThemeChecker()->check($folder)->problems);

        $this->directory->write('harbour/.gitattributes', "/build export-ignore\ntheme-preview/ export-ignore\n# comment\n/assets/screenshot.png export-ignore\n/tests export-ignore\n");
        self::assertSame([
            'Remove screenshot-dark.webp from the theme: a theme ships no screenshot or preview output; they are made from its preview page.',
        ], new ThemeChecker()->check($folder)->problems);
    }

    private function copy(): string
    {
        $source = new HarbourTheme()->paths()->root;
        $target = $this->directory->path . '/harbour';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $this->directory->write('harbour/' . substr($file->getPathname(), strlen($source) + 1), (string) file_get_contents($file->getPathname()));
        }

        return $target;
    }
}
