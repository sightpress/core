<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use Psr\Clock\ClockInterface;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountRegistration;
use SightPress\Core\User\Contract\PendingAccounts;
use SightPress\Core\User\Entity\User;

/**
 * Creates accounts: active ones with a password ({@see AccountRegistration}) and pending ones waiting for it
 * ({@see PendingAccounts}). Every new account gets its avatar.
 */
final readonly class AccountRegistrar implements AccountRegistration, PendingAccounts
{
    public function __construct(
        private UserRepository $users,
        private AvatarService $avatars,
        private ClockInterface $clock,
    ) {
    }

    public function register(
        string $email,
        string $firstName,
        string $lastName,
        ?string $displayName,
        string $timezone,
        #[\SensitiveParameter] string $passwordHash,
    ): Account {
        return $this->create($email, $firstName, $lastName, $timezone, $displayName, $passwordHash);
    }

    public function createPending(string $email, string $firstName, string $lastName, string $timezone): Account
    {
        return $this->create($email, $firstName, $lastName, $timezone, null, null);
    }

    private function create(
        string $email,
        string $firstName,
        string $lastName,
        string $timezone,
        ?string $displayName,
        #[\SensitiveParameter] ?string $passwordHash,
    ): User {
        if ($this->users->findByEmail($email) !== null) {
            throw new AccountException(sprintf('There is already an account for %s.', User::normalizeEmail($email)));
        }
        $now = $this->clock->now();
        try {
            $user = new User($email, $firstName, $lastName, $timezone, $now, $displayName);
            if ($passwordHash !== null) {
                $user->setPasswordHash($passwordHash, $now);
            }
        } catch (UserException $exception) {
            throw new AccountException($exception->getMessage(), previous: $exception);
        }
        $this->users->save($user);
        $this->avatars->generate($user);
        $this->users->save($user);

        return $user;
    }
}
