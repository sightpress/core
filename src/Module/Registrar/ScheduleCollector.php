<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\ScheduleRegistrar;
use SightPress\Module\Schedule\ScheduledJob;

/**
 * Collects a module's scheduled jobs into the {@see ModuleRegistry}, tied to the module.
 */
final readonly class ScheduleCollector implements ScheduleRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $job): void
    {
        if (!is_subclass_of($job, ScheduledJob::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers scheduled job %s, which is not a class that implements %s.',
                $this->module,
                $job,
                ScheduledJob::class,
            ));
        }

        $this->registry->addScheduledJob($this->module, $job);
    }
}
