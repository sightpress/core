<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Theme;

use SightPress\Core\Theme\Contract\ThemeLayouts;
use Twig\Environment;

/**
 * Layouts as the template files a test's Twig environment can find, for tests without a site theme.
 */
final readonly class TwigLayouts implements ThemeLayouts
{
    public function __construct(private Environment $twig)
    {
    }

    public function provides(string $layout): bool
    {
        return $this->twig->getLoader()->exists('layouts/' . $layout . '.html.twig');
    }

    public function template(string $layout): string
    {
        return $this->provides($layout) ? 'layouts/' . $layout . '.html.twig' : 'layouts/default.html.twig';
    }
}
