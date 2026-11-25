<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModuleException;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `site:sms:enable <slug>` and `site:sms:disable <slug>` (with `--off`): turn text messages on or off for a site, as
 * `site:module:enable|disable <slug> sms` would, audited. Turning them on needs a provider that is set up.
 */
readonly class EnableSmsCommand extends SiteSmsCommand
{
    public function __construct(
        SiteRepository $sites,
        SiteContainerFactory $containers,
        private SiteModules $modules,
        private bool $on = true,
    ) {
        parent::__construct($sites, $containers);
    }

    public function name(): string
    {
        return $this->on ? 'site:sms:enable' : 'site:sms:disable';
    }

    public function description(): string
    {
        return $this->on
            ? 'Turns text messages on for a site: phone numbers, text notifications and phone sign-in. Needs a provider.'
            : 'Turns text messages off for a site. Phone numbers stay on the accounts, but nothing is sent.';
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        try {
            $changed = $this->on
                ? $this->modules->turnOn($site, SmsModule::ID, null)
                : $this->modules->turnOff($site, SmsModule::ID, null);
        } catch (SiteModuleException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        $output->line(sprintf(match (true) {
            $this->on && $changed => 'Text messages are on for "%s".',
            $this->on => 'Text messages were already on for "%s".',
            $changed => 'Text messages are off for "%s".',
            default => 'Text messages were already off for "%s".',
        }, $site->slug));

        return self::SUCCESS;
    }
}
