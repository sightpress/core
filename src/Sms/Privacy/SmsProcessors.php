<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Privacy;

use SightPress\Core\Sms\Provider\TwilioProvider;
use SightPress\Core\Sms\Provider\VonageProvider;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * The text message provider, while the site has one set up (N-2, ADR-0075). Providers that other modules add are
 * declared by those modules.
 */
final readonly class SmsProcessors implements ProcessorSource
{
    public function __construct(private SmsSetup $setup)
    {
    }

    public function processors(): iterable
    {
        $configuration = $this->setup->configuration();
        if (!$configuration->isReady() || $configuration->provider === null) {
            return [];
        }
        $sent = new Message('Mobile numbers and the text of the messages the site sends (sign-in codes and the notifications people chose), to deliver them and to check that a number is a real mobile number.');

        return match ($configuration->provider->id()) {
            TwilioProvider::ID => [new ProcessorDeclaration(
                'sms.twilio',
                'Twilio Inc.',
                $sent,
                ['US'],
                [self::host(TwilioProvider::API), self::host(TwilioProvider::LOOKUP)],
                'https://www.twilio.com/en-us/legal/privacy',
            )],
            VonageProvider::ID => [new ProcessorDeclaration(
                'sms.vonage',
                'Vonage',
                $sent,
                ['GB', 'US'],
                [self::host(VonageProvider::SMS_API), self::host(VonageProvider::INSIGHT)],
                'https://www.vonage.com/legal/privacy-policy/',
            )],
            default => [],
        };
    }

    private static function host(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }
}
