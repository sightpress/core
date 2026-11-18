<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\Template\Message;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountRegistration;
use SightPress\Core\User\Contract\PendingAccounts;
use SightPress\Core\User\Contract\UnverifiedAccounts;
use SightPress\Core\User\Entity\User;

/**
 * Creates accounts: active ones with a password ({@see AccountRegistration}), pending ones waiting for it
 * ({@see PendingAccounts}) and ones people make for themselves, waiting for their email address to be confirmed
 * ({@see UnverifiedAccounts}). Every account gets its avatar once it can be used.
 */
final readonly class AccountRegistrar implements AccountRegistration, PendingAccounts, UnverifiedAccounts
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

    public function startUnverified(
        string $email,
        string $firstName,
        string $lastName,
        ?string $displayName,
        string $timezone,
        ?string $locale,
        #[\SensitiveParameter] string $passwordHash,
    ): ?Account {
        $now = $this->clock->now();
        $user = $this->users->findByEmail($email);
        if ($user !== null && !$user->awaitsEmailVerification()) {
            return null;
        }
        try {
            if ($user === null) {
                $user = new User($email, $firstName, $lastName, $timezone, $now, $displayName, $locale);
            } else {
                $user->rename($firstName, $lastName, $displayName, $now);
                $user->changeTimezone($timezone, $now);
                $user->changeLocale($locale, $now);
            }
        } catch (UserException $exception) {
            throw new AccountException($exception->text(), previous: $exception);
        }
        $user->setPasswordHash($passwordHash, $now);
        $user->awaitEmailVerification($now);
        $this->users->save($user);

        return $user;
    }

    public function confirm(int $id): ?Account
    {
        $user = $this->users->find($id);
        if ($user === null || !$user->awaitsEmailVerification()) {
            return null;
        }
        $user->confirmEmail($this->clock->now());
        $this->users->save($user);
        $this->avatars->generate($user);
        $this->users->save($user);

        return $user;
    }

    public function forgetUnconfirmed(DateTimeImmutable $madeBefore): int
    {
        return $this->users->removeUnverified($madeBefore);
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
            throw new AccountException(new Message('There is already an account for {email}.', ['email' => User::normalizeEmail($email)]));
        }
        $now = $this->clock->now();
        try {
            $user = new User($email, $firstName, $lastName, $timezone, $now, $displayName);
            if ($passwordHash !== null) {
                $user->setPasswordHash($passwordHash, $now);
            }
        } catch (UserException $exception) {
            throw new AccountException($exception->text(), previous: $exception);
        }
        $this->users->save($user);
        $this->avatars->generate($user);
        $this->users->save($user);

        return $user;
    }
}
