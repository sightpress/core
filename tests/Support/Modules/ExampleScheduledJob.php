<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use RuntimeException;
use SightPress\Module\Schedule\ScheduledJob;
use SightPress\Module\Schedule\ScheduleRun;

/**
 * A module's scheduled job, for tests: reports the time of the run, or fails when told to.
 */
final class ExampleScheduledJob implements ScheduledJob
{
    public static bool $fail = false;

    public function run(ScheduleRun $run): void
    {
        if (self::$fail) {
            throw new RuntimeException('The reminder service is down.');
        }
        $run->report('Checked reminders at ' . $run->now->format('H:i') . '.');
    }
}
