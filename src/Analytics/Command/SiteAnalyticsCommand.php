<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * What the `site:analytics:…` commands share: they work on one site that exists and has analytics on.
 */
abstract readonly class SiteAnalyticsCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    abstract protected function work(ContainerInterface $services, CommandInput $input, CommandOutput $output): int;

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $services = $this->containers->forSite($site);
        if (!Services::get($services, EnabledModules::class)->has(AnalyticsModule::ID)) {
            $output->error(sprintf('Analytics is off for the site "%s". Turn it on with: site:module:enable %s %s', $slug, $slug, AnalyticsModule::ID));

            return self::FAILURE;
        }

        return $this->work($services, $input, $output);
    }
}
