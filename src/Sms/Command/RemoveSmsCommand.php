<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `site:sms:remove <slug>`: forgets the site's own text message provider and key. Your organisation's provider (if
 * any) applies again; without one, the site can't send until a provider is set up.
 */
final readonly class RemoveSmsCommand extends SiteSmsCommand
{
    public function name(): string
    {
        return 'site:sms:remove';
    }

    public function description(): string
    {
        return 'Removes the text message provider and key of a site.';
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        if (Services::get($services, SmsSettings::class)->remove(null) === []) {
            $output->line(sprintf('The site "%s" has no text message provider of its own.', $site->slug));

            return self::SUCCESS;
        }
        $output->line(sprintf('The text message provider of "%s" is removed.', $site->slug));
        $problem = Services::get($services, SmsSetup::class)->configuration()->problem;
        if ($problem === null) {
            $output->line('It uses your organisation\'s provider now.');
        } elseif (Services::get($services, EnabledModules::class)->has(SmsModule::ID)) {
            $output->line(sprintf('Text messages are still on but can\'t be sent: set a provider with site:sms:set, or turn them off with site:sms:disable %s', $site->slug));
        }

        return self::SUCCESS;
    }
}
