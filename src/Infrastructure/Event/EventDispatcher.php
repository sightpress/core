<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Event;

use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A plain PSR-14 dispatcher: calls each listener in turn and stops once a stoppable event says so.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProviderInterface $listeners)
    {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            if (!is_callable($listener)) {
                throw new LogicException(sprintf('The listener provider returned %s, not a callable.', get_debug_type($listener)));
            }
            $listener($event);
        }

        return $event;
    }
}
