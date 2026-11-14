<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

/**
 * Records the events it gets, with a label, in a shared log.
 */
final class RecordingListener
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private string $label = 'recording')
    {
    }

    public function __invoke(object $event): void
    {
        self::$log[] = $this->label . ':' . $event::class;
        if ($event instanceof ExampleEvent) {
            $event->stop = $event->stopAfterFirst;
        }
    }
}
