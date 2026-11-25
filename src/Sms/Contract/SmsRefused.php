<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Contract;

use RuntimeException;
use SightPress\Core\Template\Message;
use SightPress\Module\Sms\SmsFailure;

/**
 * A text message wasn't sent, or a number wasn't checked: text messages are off or not set up, a limit or the budget
 * is reached, the number can't get texts, or the provider failed ({@see $failure}). The message says in plain language
 * what to do, and never holds the number, the text or the key.
 */
final class SmsRefused extends RuntimeException
{
    public function __construct(private readonly Message $text, public readonly ?SmsFailure $failure = null)
    {
        parent::__construct((string) $text);
    }

    /**
     * The message, to be shown translated.
     */
    public function text(): Message
    {
        return $this->text;
    }
}
