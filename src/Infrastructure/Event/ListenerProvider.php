<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Event;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use SightPress\Core\Module\Listener;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\Registered;

/**
 * The listeners modules registered (PSR-14). For an event it returns every listener whose event class or interface
 * matches, highest priority first and in registration order within a priority. Listeners are built from the
 * container when first needed.
 */
final readonly class ListenerProvider implements ListenerProviderInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ModuleRegistry $registry,
    ) {
    }

    /**
     * @return iterable<callable(object): mixed>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $matching = array_values(array_filter(
            $this->registry->listeners(),
            static fn (Registered $registered): bool => $event instanceof $registered->item->event,
        ));
        // usort is stable, so equal priorities keep registration order.
        usort($matching, static fn (Registered $a, Registered $b): int => $b->item->priority <=> $a->item->priority);

        foreach ($matching as $registered) {
            yield $this->listener($registered);
        }
    }

    /**
     * @param Registered<Listener> $registered
     *
     * @return callable(object): mixed
     */
    private function listener(Registered $registered): callable
    {
        $listener = $this->container->get($registered->item->listener);
        if (!is_callable($listener)) {
            throw new LogicException(sprintf(
                'Listener %s of module "%s" is not invokable.',
                $registered->item->listener,
                $registered->module,
            ));
        }

        return $listener;
    }
}
