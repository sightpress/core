<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Schedule;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Schedule\ScheduleRun;
use Throwable;

/**
 * Runs the scheduled jobs of the modules that are on for a site (module-interface `ScheduledJob`, ADR-0061), each
 * built from the site's container. A job that fails is reported and the others still run.
 */
final class ModuleJobs
{
    /** @var list<string> */
    private array $failures = [];

    /**
     * Runs every job and returns the lines they reported.
     *
     * @return list<string>
     */
    public function run(ContainerInterface $site, DateTimeImmutable $now): array
    {
        $this->failures = [];
        $run = new ScheduleRun($now);
        foreach (Services::get($site, ModuleRegistry::class)->scheduledJobs() as $registered) {
            try {
                Services::get($site, $registered->item)->run($run);
            } catch (Throwable $exception) {
                $this->failures[] = sprintf('the job %s of module "%s" failed: %s', $registered->item, $registered->module, $exception->getMessage());
            }
        }

        return $run->lines();
    }

    /**
     * What failed in the last {@see run()}, one line each.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
