<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * An event that can stop after the first listener.
 */
final class ExampleEvent implements StoppableEventInterface
{
    public bool $stop = false;

    public function __construct(public bool $stopAfterFirst = false)
    {
    }

    public function isPropagationStopped(): bool
    {
        return $this->stop;
    }
}
