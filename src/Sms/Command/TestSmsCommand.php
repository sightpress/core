<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Sms\PhoneNumbers;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Sms\Contract\TextMessageTested;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;

/**
 * `site:sms:test <slug> --to=<number>`: sends a short test message through the site's provider, within its limits and
 * budget, and says what happened. The number is only shown masked; the test is audited.
 */
final readonly class TestSmsCommand extends SiteSmsCommand
{
    public function name(): string
    {
        return 'site:sms:test';
    }

    public function description(): string
    {
        return 'Sends a test text message from a site, to check its provider works.';
    }

    public function parameters(): array
    {
        return [
            ...parent::parameters(),
            new CommandParameter('to', 'Mobile number to send it to, for example +447700900123', CommandParameterType::Option, required: true, label: 'Mobile number'),
        ];
    }

    protected function needsTextMessagesOn(): bool
    {
        return true;
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        $numbers = Services::get($services, PhoneNumbers::class);
        $to = $numbers->parse((string) $input->option('to'), $numbers->regionOf($site->defaultLocale));
        if ($to === null) {
            $output->error('That isn\'t a phone number. Give it with the country code, for example +447700900123.');

            return self::INVALID;
        }
        $provider = Services::get($services, SmsSetup::class)->configuration()->provider?->id() ?? '';
        $events = Services::get($services, EventDispatcherInterface::class);
        try {
            $result = Services::get($services, SmsSender::class)->send(new SmsMessage($to, sprintf('Test message from %s: text messages work.', $site->name), SmsPurpose::Notification));
        } catch (SmsRefused $refused) {
            $events->dispatch(new TextMessageTested(null, $to->masked(), $provider, false, $refused->failure->value ?? 'refused'));
            $output->error(sprintf('The test message wasn\'t sent to %s: %s', $to->masked(), $refused->getMessage()));

            return self::FAILURE;
        }
        $events->dispatch(new TextMessageTested(null, $to->masked(), $provider, true));
        $output->line(sprintf('The test message is on its way to %s (%d part%s).', $to->masked(), $result->parts, $result->parts === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
