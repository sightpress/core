<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Themes: reads the active theme's `theme.json` (the default theme in this version), turns its tokens into CSS
 * custom properties and serves its files under `/assets/`. Always on.
 */
final class ThemeModule implements ModuleInterface
{
    public const string ID = 'theme';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        $services->autowire(ThemeLoader::class);
        $services->autowire(TokenStylesheet::class);
        $services->factory(Theme::class, static fn (ContainerInterface $c): Theme => Services::get($c, ThemeLoader::class)->load(
            Services::get($c, TemplatePaths::class)->themes[0] ?? TemplatePaths::defaultTheme(),
        ));
        $services->autowire(ThemeAssets::class);
        $services->autowire(ThemeAssetHandler::class);

        $registrar->routes()->add(new Route(
            'theme.asset',
            '/assets/{' . ThemeAssetHandler::PATH_ATTRIBUTE . ':.+}',
            ThemeAssetHandler::class,
            ['GET', 'HEAD'],
        ));
    }
}
