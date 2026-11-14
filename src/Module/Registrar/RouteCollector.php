<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\RouteRegistrar;
use SightPress\Module\Routing\Route;

/**
 * Records a module's HTTP routes; the kernel adds them to the router.
 */
final readonly class RouteCollector implements RouteRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(Route $route): void
    {
        $this->registry->addRoute($this->module, $route);
    }
}
