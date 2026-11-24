<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsResult;

/**
 * A text message provider that sends nothing, for registry tests.
 */
final class ExampleSmsProvider implements SmsProvider
{
    public function id(): string
    {
        return 'example';
    }

    public function label(): string
    {
        return 'Example';
    }

    public function description(): string
    {
        return 'Sends nothing.';
    }

    public function capabilities(): SmsCapabilities
    {
        return new SmsCapabilities();
    }

    public function send(SmsMessage $message, SmsConnection $connection): SmsResult
    {
        return new SmsResult('example-1');
    }

    public function lookup(PhoneNumber $number, SmsConnection $connection): PhoneLookupResult
    {
        return new PhoneLookupResult(true);
    }
}
