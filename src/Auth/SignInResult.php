<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\User\Contract\Account;

/**
 * How a sign-in attempt went.
 */
final readonly class SignInResult
{
    /** @var positive-int|null seconds to wait when there were too many attempts */
    public ?int $retryAfter;

    /**
     * @param positive-int|null $retryAfter
     */
    private function __construct(
        public ?Account $account,
        ?int $retryAfter = null,
        public ?string $unconfirmedEmail = null,
    ) {
        $this->retryAfter = $retryAfter;
    }

    public static function signedIn(Account $account): self
    {
        return new self($account);
    }

    public static function wrongCredentials(): self
    {
        return new self(null);
    }

    /**
     * The password was right, but the person signed up and hasn't typed the code we emailed them yet: they can't sign
     * in until they do.
     */
    public static function unconfirmed(Account $account): self
    {
        return new self(null, unconfirmedEmail: $account->email());
    }

    /**
     * @param positive-int $retryAfter
     */
    public static function throttled(int $retryAfter): self
    {
        return new self(null, $retryAfter);
    }

    public function succeeded(): bool
    {
        return $this->account !== null;
    }
}
