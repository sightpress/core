<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SensitiveParameter;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Sms\PhoneNumber;

/**
 * What someone typed to sign in or reset their password (N-5, ADR-0072): with an `@` it is an email address; while
 * text messages are on, anything else is read as a mobile number of the site's country (or with its own `+` code) and
 * matches only a confirmed number. While they are off, a number never matches, so signing in by email always works.
 */
final readonly class SignInIdentifier
{
    public function __construct(
        private Accounts $accounts,
        private AccountPhones $phones,
        private TextMessages $texts,
    ) {
    }

    /**
     * Whether people may sign in with their mobile number on this site now.
     */
    public function phonesAccepted(): bool
    {
        return $this->texts->isOn();
    }

    /**
     * The number typed, when it is one and numbers are accepted; null for an email address or anything else.
     */
    public function phone(#[SensitiveParameter] string $identifier): ?PhoneNumber
    {
        return str_contains($identifier, '@') || !$this->phonesAccepted() ? null : $this->texts->parse($identifier);
    }

    public function account(#[SensitiveParameter] string $identifier): ?Account
    {
        if (str_contains($identifier, '@')) {
            return $this->accounts->findAccountByEmail($identifier);
        }
        $phone = $this->phone($identifier);
        $id = $phone === null ? null : $this->phones->accountWithPhone($phone->e164);

        return $id === null ? null : $this->accounts->findAccount($id);
    }

    /**
     * The same identifier written one way (lower-case email, E.164 number), for limits and code targets.
     */
    public function normalise(#[SensitiveParameter] string $identifier): string
    {
        return $this->phone($identifier)->e164 ?? mb_strtolower(trim($identifier));
    }
}
