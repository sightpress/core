<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * An event dispatcher that only keeps what it is given, for unit tests.
 */
final class RecordingEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
