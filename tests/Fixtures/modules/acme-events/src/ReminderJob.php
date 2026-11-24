<?php

declare(strict_types=1);

namespace Acme\Events;

use SightPress\Module\Schedule\ScheduledJob;
use SightPress\Module\Schedule\ScheduleRun;

final readonly class ReminderJob implements ScheduledJob
{
    public function __construct(private EventStore $events)
    {
    }

    public function run(ScheduleRun $run): void
    {
        $count = count($this->events->all());
        if ($count > 0) {
            $run->report(sprintf('%d upcoming %s checked for reminders.', $count, $count === 1 ? 'event' : 'events'));
        }
    }
}
