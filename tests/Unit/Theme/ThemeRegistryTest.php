<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\PreviewFakeTheme;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\ThemeDefault\DefaultTheme;

#[CoversClass(ThemeRegistry::class)]
#[UsesClass(ThemeException::class)]
final class ThemeRegistryTest extends TestCase
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

    public function testTheDefaultThemeIsAlwaysThereAndComesFirst(): void
    {
        $other = new PreviewFakeTheme();
        $registry = new ThemeRegistry([$other, new DefaultTheme(), $other]);

        self::assertInstanceOf(DefaultTheme::class, $registry->default());
        self::assertSame($other, $registry->find('acme-harbour'));
        self::assertNull($registry->find('missing'));
        self::assertSame(['default', 'acme-harbour'], array_map(static fn ($theme): string => $theme->manifest()->id, $registry->all()));
    }

    public function testFindsTheThemesInstalledWithComposer(): void
    {
        $this->installed(['packages' => [
            ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => PreviewFakeTheme::class]]],
            ['name' => 'acme/module', 'type' => 'library', 'extra' => ['sightpress' => ['theme' => 'Not\\Read']]],
            ['name' => 'acme/theme-plain', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => []]],
            ['name' => 'acme/theme-odd', 'type' => 'sightpress-theme', 'extra' => 'none'],
            'not a package',
        ]]);

        $registry = ThemeRegistry::forProject($this->directory->path . '/');

        self::assertSame(['default', 'acme-harbour'], array_map(static fn ($theme): string => $theme->manifest()->id, $registry->all()));
    }

    public function testAProjectWithoutInstalledPackagesHasTheDefaultTheme(): void
    {
        self::assertCount(1, ThemeRegistry::forProject($this->directory->path)->all());
        $this->installed('"nothing"');
        self::assertCount(1, ThemeRegistry::forProject($this->directory->path)->all());
    }

    public function testSaysWhenInstalledJsonIsBroken(): void
    {
        $this->installed('{');

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('Could not read the installed packages from ' . $this->directory->path . '/vendor/composer/installed.json');
        ThemeRegistry::forProject($this->directory->path);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badClasses(): iterable
    {
        yield 'missing class' => ['Acme\\Missing', 'Package acme/theme-bad names a theme class that does not exist: Acme\\Missing.'];
        yield 'not a string' => [['x'], 'Package acme/theme-bad names a theme class that does not exist: array.'];
        yield 'not a theme' => [\stdClass::class, 'Package acme/theme-bad: stdClass must be a class that implements SightPress\\Theme\\ThemeInterface and can be created without arguments.'];
    }

    #[DataProvider('badClasses')]
    public function testRefusesAPackageWhoseClassIsNotATheme(mixed $class, string $message): void
    {
        $this->installed(['packages' => [['name' => 'acme/theme-bad', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => $class]]]]]);

        $this->expectExceptionObject(new ThemeException($message));
        ThemeRegistry::forProject($this->directory->path);
    }

    public function testAnUnnamedPackageIsNamedSo(): void
    {
        $this->installed(['packages' => [['type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => 'Acme\\Missing']]]]]);

        $this->expectExceptionObject(new ThemeException('Package an unnamed package names a theme class that does not exist: Acme\\Missing.'));
        ThemeRegistry::forProject($this->directory->path);
    }

    private function installed(mixed $data): void
    {
        $this->directory->write('vendor/composer/installed.json', is_string($data) ? $data : (string) json_encode($data));
    }
}
