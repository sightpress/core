<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Placeholder\Placeholder;
use SightPress\Module\Placeholder\PlaceholderResolver;
use SightPress\Module\Registrar\PlaceholderRegistrar;

/**
 * Collects a module's placeholders into the {@see ModuleRegistry}, tied to the module (ADR-0078). BL-6 renders them.
 */
final readonly class PlaceholderCollector implements PlaceholderRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(Placeholder $placeholder): void
    {
        if (!is_subclass_of($placeholder->resolver, PlaceholderResolver::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers placeholder "%s" with resolver %s, which is not a class that implements %s.',
                $this->module,
                $placeholder->name,
                $placeholder->resolver,
                PlaceholderResolver::class,
            ));
        }

        $this->registry->addPlaceholder($this->module, $placeholder);
    }
}
