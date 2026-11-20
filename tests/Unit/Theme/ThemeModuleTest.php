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
use SightPress\Core\Theme\Command\ValidateThemeCommand;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeAssets;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeModule;
use SightPress\Core\Theme\ThemeValidator;
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
        self::assertSame(['theme.appearance', 'theme.colours', 'theme.asset'], array_map(static fn ($r) => $r->item->name, $routes));
        self::assertSame('/assets/{path:.+}', $routes[2]->item->path);
        self::assertSame(['GET', 'HEAD'], $routes[2]->item->methods);
        self::assertSame(ThemeAssetHandler::class, $routes[2]->item->handler);

        foreach ([ThemeLoader::class, TokenStylesheet::class, ThemeAssets::class, ThemeAssetHandler::class, ThemeValidator::class] as $id) {
            self::assertSame($id, $registry->services()[$id]->item->class);
        }
        self::assertTrue($registry->services()[ThemeValidator::class]->item->public);
        self::assertSame(\SightPress\Core\Theme\ThemePageColours::class, $registry->services()[\SightPress\Core\Theme\Contract\PageColours::class]->item->class);
        $commands = array_values(array_filter($registry->commands(), static fn ($c) => $c->module === 'theme'));
        self::assertSame([ValidateThemeCommand::class, \SightPress\Core\Theme\Command\PublishThemeAssetsCommand::class, \SightPress\Core\Theme\Command\PreviewThemeCommand::class, \SightPress\Core\Theme\Command\ListThemesCommand::class, \SightPress\Core\Theme\Command\ApplyThemeCommand::class], array_map(static fn ($c) => $c->item, $commands));
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
