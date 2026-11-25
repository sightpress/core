<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Template\Message;
use SightPress\Module\Sms\SmsFailure;

/**
 * What a provider failure means for the person who sees it, in plain language (N-2): never the number, the text, the
 * key or the provider's own wording.
 */
final class SmsError
{
    public static function message(SmsFailure $failure): Message
    {
        return match ($failure) {
            SmsFailure::InvalidKey => new Message('The text message provider refused the site’s key. Ask whoever runs your sites to check it.'),
            SmsFailure::InvalidNumber => new Message('Text messages can’t be sent to this number. Check it, or use another mobile number.'),
            SmsFailure::UnsupportedCountry => new Message('Text messages can’t be sent to numbers in this country from this site.'),
            SmsFailure::OptedOut => new Message('This number replied STOP to the site’s messages. Reply START to the site’s last message, or ask an admin for help.'),
            SmsFailure::NoCredit => new Message('The text message account has no credit left. Ask whoever runs your sites to top it up.'),
            SmsFailure::RateLimited => new Message('The text message provider is busy. Try again in a few minutes.'),
            SmsFailure::Timeout => new Message('The text message provider didn’t answer in time. Try again.'),
            SmsFailure::Unsupported => new Message('The text message provider can’t do this.'),
            SmsFailure::InvalidSignature => new Message('The report didn’t come from the text message provider.'),
            SmsFailure::Unavailable => new Message('The text message provider couldn’t be reached. Try again later.'),
        };
    }
}
