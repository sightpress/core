<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Sms;

use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsResult;

/**
 * A module's provider without a lookup, that only delivers in the UK and Portugal.
 */
final readonly class NoLookupSmsProvider implements SmsProvider
{
    public function id(): string
    {
        return 'no_lookup';
    }

    public function label(): string
    {
        return 'Another Twilio';
    }

    public function description(): string
    {
        return 'Not really Twilio.';
    }

    public function capabilities(): SmsCapabilities
    {
        return new SmsCapabilities(countries: ['GB', 'PT']);
    }

    public function send(SmsMessage $message, SmsConnection $connection): SmsResult
    {
        return new SmsResult('nl-1');
    }

    public function lookup(PhoneNumber $number, SmsConnection $connection): PhoneLookupResult
    {
        throw new SmsException(SmsFailure::Unsupported);
    }
}
