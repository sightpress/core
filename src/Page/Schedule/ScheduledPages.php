<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Schedule;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * The page module's job in `schedule:run`: publishes and takes off the site the pages whose time has come
 * ({@see PageSchedule::run()}).
 */
final readonly class ScheduledPages
{
    public function __invoke(ScheduleDue $event): void
    {
        foreach (Services::get($event->services, PageSchedule::class)->run($event->now) as $line) {
            $event->report($line);
        }
    }
}
