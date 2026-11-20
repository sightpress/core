<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use Psr\Container\ContainerInterface;
use Throwable;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Theme\Command\ApplyThemeCommand;
use SightPress\Core\Theme\Contract\ThemeApplied;
use SightPress\Core\Theme\Contract\ThemeColoursChanged;
use SightPress\Core\Theme\Command\PublishThemeAssetsCommand;
use SightPress\Core\Theme\Http\AppearanceHandler;
use SightPress\Core\Theme\Http\ThemeColoursHandler;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Core\Theme\Command\ListThemesCommand;
use SightPress\Core\Theme\Command\PreviewThemeCommand;
use SightPress\Core\Theme\Command\ValidateThemeCommand;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Core\Theme\Contract\PageColours;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Themes: reads the active theme's `theme.json` (the default theme in this version), turns its tokens into CSS
 * custom properties and serves its files under `/assets/`; the {@see ThemeValidator} (public, for the admin
 * later) and `theme:validate` check a theme's contrast; `theme:preview` writes a theme's static preview page.
 * Always on.
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
        $services->factory(Theme::class, static function (ContainerInterface $c): Theme {
            $theme = Services::get($c, ThemeLoader::class)->load(Services::get($c, TemplatePaths::class)->themes[0] ?? TemplatePaths::defaultTheme());
            try {
                // The site's own colours (T-2); a tenant-level page (no site) has none.
                return ThemeColours::apply($theme, Services::get($c, ThemeColours::class)->overrides($theme->name));
            } catch (Throwable) {
                return $theme;
            }
        });
        $services->autowire(ThemeColours::class, public: true);
        $services->autowire(ThemeAssets::class);
        $services->autowire(ThemeAssetHandler::class);
        $services->autowire(ThemeValidator::class, public: true);
        $services->autowire(ThemeChecker::class, public: true);
        $services->autowire(ThemeApplier::class, public: true);
        $services->autowire(ThemePageColours::class, PageColours::class, public: true);

        $services->autowire(PublishedPreviews::class);
        $services->autowire(ThemeChanged::class);
        $registrar->subscribers()->listen(ThemeApplied::class, ThemeChanged::class);
        $registrar->subscribers()->listen(ThemeColoursChanged::class, ThemeChanged::class);
        $services->autowire(AppearanceHandler::class);
        $services->autowire(ThemeColoursHandler::class);
        $registrar->views()->add('theme', dirname(__DIR__, 2) . '/resources/views/theme');
        $routes = $registrar->routes();
        $routes->add(new Route('theme.appearance', AppearanceHandler::PATH, AppearanceHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('theme.colours', ThemeColoursHandler::PATH, ThemeColoursHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('theme.appearance', 'Appearance', 'theme.appearance', AppearanceHandler::PERMISSION, position: 55));

        $registrar->commands()->add(ValidateThemeCommand::class);
        $registrar->commands()->add(PublishThemeAssetsCommand::class);
        $registrar->commands()->add(PreviewThemeCommand::class);
        $registrar->commands()->add(ListThemesCommand::class);
        $registrar->commands()->add(ApplyThemeCommand::class);

        $registrar->routes()->add(new Route(
            'theme.asset',
            '/assets/{' . ThemeAssetHandler::PATH_ATTRIBUTE . ':.+}',
            ThemeAssetHandler::class,
            ['GET', 'HEAD'],
        ));
    }
}
