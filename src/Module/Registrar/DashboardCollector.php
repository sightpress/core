<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Dashboard\DashboardWidget;
use SightPress\Module\Registrar\DashboardRegistrar;

/**
 * Records a module's dashboard widgets.
 */
final readonly class DashboardCollector implements DashboardRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $widget): void
    {
        if (!is_subclass_of($widget, DashboardWidget::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers dashboard widget %s, which is not a class that implements %s.',
                $this->module,
                $widget,
                DashboardWidget::class,
            ));
        }

        $this->registry->addDashboardWidget($this->module, $widget);
    }
}
