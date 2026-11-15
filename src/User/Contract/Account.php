<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use DateTimeImmutable;

/**
 * What sign-in needs to know about a user, and the one change it makes: a new password.
 */
interface Account
{
    /**
     * The id, once the account has been saved.
     */
    public function id(): ?int;

    public function email(): string;

    /**
     * The name to show: the display name, or the first name when there is none.
     */
    public function effectiveName(): string;

    /**
     * Whether the person may sign in (they have set a password and nobody deactivated the account).
     */
    public function isActive(): bool;

    /**
     * Whether the person still has to set their first password (for example the first admin of a new site).
     */
    public function isPending(): bool;

    public function passwordHash(): ?string;

    /**
     * Stores a new password hash (argon2id, made by the caller) and activates a pending account.
     */
    public function setPasswordHash(string $hash, DateTimeImmutable $now): void;
}
