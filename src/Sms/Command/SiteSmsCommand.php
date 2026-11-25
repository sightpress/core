<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * What the `site:sms:…` commands share: they work on one site that exists, and never print a key or a whole number.
 * Commands that send need text messages to be on ({@see needsTextMessagesOn()}); setting up the provider doesn't.
 */
abstract readonly class SiteSmsCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    abstract protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int;

    protected function needsTextMessagesOn(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $services = $this->containers->forSite($site);
        if ($this->needsTextMessagesOn() && !Services::get($services, EnabledModules::class)->has(SmsModule::ID)) {
            $output->error(sprintf('Text messages are off for the site "%s". Turn them on with: site:sms:enable %s', $slug, $slug));

            return self::FAILURE;
        }

        return $this->work($services, $site, $input, $output);
    }
}
