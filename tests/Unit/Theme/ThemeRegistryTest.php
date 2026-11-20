<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
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
        $other = new HarbourTheme();
        $registry = new ThemeRegistry([$other, new DefaultTheme(), $other]);

        self::assertInstanceOf(DefaultTheme::class, $registry->default());
        self::assertSame($other, $registry->find('acme-harbour'));
        self::assertNull($registry->find('missing'));
        self::assertSame(['default', 'acme-harbour'], array_map(static fn ($theme): string => $theme->manifest()->id, $registry->all()));
    }

    public function testFindsTheThemesInstalledWithComposer(): void
    {
        $this->installed(['packages' => [
            ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]],
            ['name' => 'acme/module', 'type' => 'library', 'extra' => ['sightpress' => ['theme' => 'Not\\Read']]],
            ['name' => 'acme/theme-plain', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => []]],
            ['name' => 'acme/theme-odd', 'type' => 'sightpress-theme', 'extra' => 'none'],
            'not a package',
        ]]);

        $registry = ThemeRegistry::forProject($this->directory->path . '/');

        self::assertSame(['default', 'acme-harbour'], array_map(static fn ($theme): string => $theme->manifest()->id, $registry->all()));
        self::assertSame('acme/theme-harbour', $registry->package('acme-harbour'));
        self::assertSame('sightpress/theme-default', $registry->package('default'));
        self::assertNull($registry->package('missing'));
    }

    public function testKeepsWhatItFoundInTheCacheFolderUntilInstalledJsonChanges(): void
    {
        $cache = $this->directory->path . '/cache';
        $this->installed(['packages' => [['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]]]]);

        self::assertCount(2, ThemeRegistry::forProject($this->directory->path, $cache)->all());
        self::assertFileExists($cache . '/themes.php');
        $cached = (string) file_get_contents($cache . '/themes.php');
        self::assertStringContainsString('acme/theme-harbour', $cached);
        // A cache written for the same installed.json is used as it is.
        file_put_contents($cache . '/themes.php', str_replace('acme/theme-harbour', 'acme/cached', $cached));
        self::assertSame('acme/cached', ThemeRegistry::forProject($this->directory->path, $cache)->package('acme-harbour'));
        // A new installed.json is read again.
        $this->installed(['packages' => []]);
        self::assertCount(1, ThemeRegistry::forProject($this->directory->path, $cache)->all());
    }

    public function testAThemeForAnotherVersionOfTheContractIsUnavailable(): void
    {
        $harbour = new HarbourTheme();

        self::assertNull(new ThemeRegistry([$harbour], [], '0.2.1.0')->unavailable($harbour));
        self::assertNull(new ThemeRegistry([$harbour], [], '0.2.9999999.9999999-dev')->unavailable($harbour));
        self::assertNull(new ThemeRegistry([$harbour])->unavailable($harbour));
        self::assertSame(
            'It needs sightpress/theme-interface ^0.2, and this SightPress has 0.3.0.0.',
            new ThemeRegistry([$harbour], [], '0.3.0.0')->unavailable($harbour),
        );
        self::assertSame(
            'It needs sightpress/theme-interface ^0.2, and this SightPress has 0.3.x-dev.',
            new ThemeRegistry([$harbour], [], '0.3.9999999.9999999-dev')->unavailable($harbour),
        );
        self::assertNotNull(new ThemeRegistry([$harbour], [], 'not a version')->unavailable($harbour));
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
        yield 'not a string' => [['x'], 'Package acme/theme-bad names a theme class that does not exist: not a class name.'];
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
