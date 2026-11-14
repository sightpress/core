<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * An event listener a module added.
 */
final readonly class Listener
{
    /**
     * @param class-string $event
     * @param class-string $listener invokable class built from the container
     */
    public function __construct(
        public string $event,
        public string $listener,
        public int $priority,
    ) {
    }
}
