<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * A `site:privacy:*` or `site:user:*` command (GD-2 … GD-5): finds the site named by the `slug` argument, then works in
 * its container. Output never shows personal data apart from what the person asked for (an export file's path).
 */
abstract readonly class SitePrivacyCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    abstract protected function work(Site $site, ContainerInterface $services, CommandInput $input, CommandOutput $output): int;

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }

        return $this->work($site, $this->containers->forSite($site), $input, $output);
    }
}
