<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use DateTimeImmutable;

/**
 * What sign-in needs to know about a user, and the changes it makes: a new password, and a new email address once a
 * code proved it.
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

    /**
     * Whether the person signed up themselves and hasn't typed the code we emailed them yet (they can't sign in).
     */
    public function awaitsEmailVerification(): bool;

    public function passwordHash(): ?string;

    /**
     * Stores a new password hash (argon2id, made by the caller) and activates a pending account.
     */
    public function setPasswordHash(string $hash, DateTimeImmutable $now): void;

    /**
     * A new email address (normalised to lower case), after a code sent to it was typed back.
     */
    public function changeEmail(string $email, DateTimeImmutable $now): void;
}
