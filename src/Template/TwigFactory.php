<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use SightPress\Core\Module\ModuleRegistry;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the template engine: HTML autoescaping always on, the theme-aware loader chain and core's extension.
 * Compiled templates are cached unless in debug mode, where templates reload and unknown variables are errors.
 */
final readonly class TwigFactory
{
    public function create(
        TemplatePaths $paths,
        ModuleRegistry $modules,
        TemplateExtension $extension,
        string $cacheDir,
        bool $debug,
    ): Environment {
        $loader = new FilesystemLoader();
        foreach ($paths->themes as $theme) {
            $this->addIfFolder($loader, $theme . '/templates');
        }
        $this->addIfFolder($loader, $paths->coreViews);

        foreach ($modules->views() as $registered) {
            $namespace = $registered->item->namespace;
            foreach ($paths->themes as $theme) {
                $this->addIfFolder($loader, $theme . '/templates/modules/' . $namespace, $namespace);
            }
            $loader->addPath($registered->item->directory, $namespace);
        }

        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => $debug,
            'auto_reload' => $debug,
            'cache' => $debug ? false : rtrim($cacheDir, '/') . '/twig',
            'charset' => 'UTF-8',
        ]);
        $twig->addExtension($extension);

        return $twig;
    }

    private function addIfFolder(FilesystemLoader $loader, string $directory, string $namespace = FilesystemLoader::MAIN_NAMESPACE): void
    {
        if (is_dir($directory)) {
            $loader->addPath($directory, $namespace);
        }
    }
}
