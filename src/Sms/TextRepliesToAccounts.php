<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Sms\Contract\TextMessageOptChanged;
use SightPress\Core\User\Contract\AccountPhones;

/**
 * A STOP or START reply also changes the choice of the account that has the number (N-3): after STOP it gets no
 * text notifications until START, or until the person ticks "Send me text messages" again.
 */
final readonly class TextRepliesToAccounts
{
    public function __construct(private AccountPhones $phones)
    {
    }

    public function __invoke(TextMessageOptChanged $event): void
    {
        $this->phones->textReply($event->number->e164, $event->stopped);
    }
}
