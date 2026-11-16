<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeAssets;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeModule;
use SightPress\Core\Theme\TokenStylesheet;

#[CoversClass(ThemeModule::class)]
#[UsesNamespace('SightPress\Core')]
final class ThemeModuleTest extends TestCase
{
    public function testIsRequiredAndServesTheActiveThemesFiles(): void
    {
        $module = new ThemeModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('theme', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame([], $module->manifest()->requires);

        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'theme'));
        self::assertSame('theme.asset', $routes[0]->item->name);
        self::assertSame('/assets/{path:.+}', $routes[0]->item->path);
        self::assertSame(['GET', 'HEAD'], $routes[0]->item->methods);
        self::assertSame(ThemeAssetHandler::class, $routes[0]->item->handler);

        foreach ([ThemeLoader::class, TokenStylesheet::class, ThemeAssets::class, ThemeAssetHandler::class] as $id) {
            self::assertSame($id, $registry->services()[$id]->item->class);
        }
    }

    public function testTheActiveThemeIsTheFirstThemeFolderOrTheDefaultTheme(): void
    {
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );
        $factory = $registry->services()[Theme::class]->item->factory;
        self::assertNotNull($factory);
        $theme = $factory(self::container([TemplatePaths::defaultTheme()]));
        self::assertInstanceOf(Theme::class, $theme);
        self::assertSame('default', $theme->name);
        $fallback = $factory(self::container([]));
        self::assertInstanceOf(Theme::class, $fallback);
        self::assertSame(TemplatePaths::defaultTheme(), $fallback->directory);
    }

    /**
     * @param list<string> $themes
     */
    private static function container(array $themes): Container
    {
        return new Container([
            ThemeLoader::class => new ThemeLoader(),
            TemplatePaths::class => new TemplatePaths($themes, TemplatePaths::core()),
        ]);
    }
}
