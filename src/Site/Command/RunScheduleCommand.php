<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Schedule\ModuleJobs;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Site\ScheduleLock;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use Throwable;

/**
 * `schedule:run`: does what is due on every enabled site ({@see ScheduleDue}), for example publishing the pages whose
 * time has come. Meant to run once a minute (the skeleton's `scheduler` service, or cron). Two runs never overlap: a
 * run that finds another one still going stops at once. A site that fails doesn't stop the others; the exit code is
 * then 1.
 */
final readonly class RunScheduleCommand implements Command
{
    public const string LOCK_FILE = 'var/schedule.lock';

    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private TenantConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Does what is due on every site, such as publishing scheduled pages. Run it once a minute.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $lock = new ScheduleLock(rtrim($this->config->projectDir, '/') . '/' . self::LOCK_FILE);
        try {
            if (!$lock->acquire()) {
                $output->line('Another run is still going; this one stops.');

                return self::SUCCESS;
            }
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $now = $this->clock->now();
            $sites = array_values(array_filter($this->sites->all(), static fn (Site $site): bool => $site->status === SiteStatus::Enabled));
            $failed = 0;
            foreach ($sites as $site) {
                try {
                    $container = $this->containers->forSite($site);
                    $due = new ScheduleDue($site, $container, $now);
                    $this->events->dispatch($due);
                    $jobs = new ModuleJobs();
                    foreach ([...$due->lines(), ...$jobs->run($container, $now)] as $line) {
                        $output->line(sprintf('%s: %s', $site->slug, $line));
                    }
                    foreach ($jobs->failures() as $failure) {
                        $output->error(sprintf('%s: %s', $site->slug, $failure));
                    }
                    if ($jobs->failures() !== []) {
                        ++$failed;
                    }
                } catch (Throwable $exception) {
                    ++$failed;
                    $output->error(sprintf('%s: the scheduled jobs failed: %s', $site->slug, $exception->getMessage()));
                } finally {
                    $this->containers->evict($site->slug);
                }
            }
            $output->line(sprintf('Ran the schedule for %d %s at %s UTC%s.', count($sites), count($sites) === 1 ? 'site' : 'sites', $now->format('Y-m-d H:i'), $failed === 0 ? '' : sprintf('; %d failed', $failed)));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
