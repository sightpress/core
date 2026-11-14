<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\Listener;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\SubscriberRegistrar;

/**
 * Records a module's event listeners for the PSR-14 event dispatcher.
 */
final readonly class SubscriberCollector implements SubscriberRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function listen(string $event, string $listener, int $priority = 0): void
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new ModuleException(sprintf(
                'Module "%s" listens to event %s, which is not a class or interface.',
                $this->module,
                $event,
            ));
        }
        if (!class_exists($listener) || !method_exists($listener, '__invoke')) {
            throw new ModuleException(sprintf(
                'Module "%s" registers listener %s, which is not an invokable class.',
                $this->module,
                $listener,
            ));
        }

        $this->registry->addListener($this->module, new Listener($event, $listener, $priority));
    }
}
