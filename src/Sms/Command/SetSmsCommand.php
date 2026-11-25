<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Cli\KeyInput;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Sms\SmsConnection;

/**
 * `site:sms:set <slug> --provider= [--account=] [--sender=] [--region=] [--budget=] [--key-stdin]`: sets up the site's
 * own text message provider. With `--key-stdin` it reads the key from standard input (stored encrypted; without it the
 * current key is kept), so it never shows in the shell history or the process list.
 */
final readonly class SetSmsCommand extends SiteSmsCommand
{
    public function __construct(
        SiteRepository $sites,
        SiteContainerFactory $containers,
        private KeyInput $keys,
    ) {
        parent::__construct($sites, $containers);
    }

    public function name(): string
    {
        return 'site:sms:set';
    }

    public function description(): string
    {
        return 'Sets up the text message provider of a site, and reads its key from standard input with --key-stdin.';
    }

    public function parameters(): array
    {
        return [
            ...parent::parameters(),
            new CommandParameter('provider', 'Text message provider: twilio or vonage (or a module\'s)', CommandParameterType::Option, required: true, label: 'Provider'),
            new CommandParameter('account', 'Account or key id: the Twilio account SID, or the Vonage API key', CommandParameterType::Option),
            new CommandParameter('sender', 'Who messages come from: a number such as +447700900123, or a name of 3 to 11 letters and digits', CommandParameterType::Option),
            new CommandParameter('region', 'The provider\'s region, for providers that have regions', CommandParameterType::Option),
            new CommandParameter('budget', 'Message parts the site may send each month (0 = no limit); empty for your organisation\'s', CommandParameterType::Option),
            new CommandParameter('key-stdin', 'Read the key from standard input (the first line), for example: printf %s "$KEY" | bin/sightpress site:sms:set …', CommandParameterType::Flag),
        ];
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        $providers = Services::get($services, SmsProviders::class);
        $providerId = trim((string) $input->option('provider'));
        $provider = $providers->get($providerId);
        if ($provider === null) {
            $output->error(sprintf('There is no text message provider "%s". Choose one of: %s.', $providerId, implode(', ', array_keys($providers->all()))));

            return self::INVALID;
        }
        $text = static fn (string $name): ?string => ($value = trim((string) $input->option($name))) === '' ? null : $value;
        $budget = $text('budget');
        if ($budget !== null && preg_match('/^\d{1,9}$/', $budget) !== 1) {
            $output->error('Give --budget as a whole number of message parts, such as 500 (0 = no limit).');

            return self::INVALID;
        }
        $key = null;
        if ($input->flag('key-stdin')) {
            $key = $this->keys->read();
            if ($key === '') {
                $output->error('No key was read from standard input. Pipe it in, for example: printf %s "$KEY" | bin/sightpress site:sms:set …');

                return self::INVALID;
            }
        }
        try {
            new SmsConnection('check', $text('account'), $text('region'), $text('sender'));
        } catch (InvalidValueException $invalid) {
            $output->error($invalid->getMessage());

            return self::INVALID;
        }

        $settings = Services::get($services, SmsSettings::class);
        $changed = [
            ...$settings->connect($provider->id(), $text('account'), $text('sender'), $text('region'), $key, null),
            ...$settings->limit($budget === null ? null : max(0, (int) $budget), null),
        ];
        $output->line($changed === []
            ? sprintf('Nothing changed for "%s".', $site->slug)
            : sprintf('The site "%s" now sends text messages with %s.', $site->slug, $provider->label()));
        $masked = $settings->maskedKey();
        $output->line($masked === null ? 'The site has no key yet.' : sprintf('Key: %s', $masked));
        $problem = Services::get($services, SmsSetup::class)->configuration()->problem;
        $output->line($problem === null
            ? sprintf('Turn text messages on with: site:sms:enable %s', $site->slug)
            : 'Not ready yet: ' . $problem);

        return self::SUCCESS;
    }
}
