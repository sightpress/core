<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * The site's sign-up role (setting `rbac.registration_role`, default `member`): the role people get when they make
 * their own account. It must always be sign-up-safe (ADR-0014).
 */
interface SignUpRoles
{
    /**
     * The name of the stored sign-up role, or `member` when none is stored.
     */
    public function name(): string;

    /**
     * The role a new account gets: the stored sign-up role while it exists and is sign-up-safe, otherwise `member`
     * (someone may have changed the stored value straight in the database).
     */
    public function forNewAccount(): string;

    /**
     * The roles the sign-up role may be changed to (the sign-up-safe ones), as name => label.
     *
     * @return array<string, string>
     */
    public function choices(): array;

    /**
     * @throws \DomainException when the role doesn't exist or isn't sign-up-safe
     */
    public function change(string $name): void;
}
