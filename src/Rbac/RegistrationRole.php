<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Contract\SignUpRoles;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The site setting `registration_role`: the role people get when they register themselves (default `member`). It
 * must always be sign-up-safe (ADR-0014).
 */
final readonly class RegistrationRole implements SignUpRoles
{
    public const string SETTING = 'rbac.registration_role';

    public function __construct(
        private SiteSettings $settings,
        private RoleRepository $roles,
    ) {
    }

    /**
     * The name of the stored sign-up role, or `member` when none is stored.
     */
    public function name(): string
    {
        return $this->settings->get(self::SETTING) ?? RoleName::MEMBER;
    }

    /**
     * The role a new account gets: the stored sign-up role while it exists and is sign-up-safe, otherwise `member`
     * (someone may have changed the stored value straight in the database).
     */
    public function forNewAccount(): string
    {
        $role = $this->roles->findRole($this->name());

        return $role !== null && RoleSafety::isSignUpSafe($role) ? $role->name() : RoleName::MEMBER;
    }

    /**
     * The roles the sign-up role may be changed to (sign-up-safe ones), as name => label.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->roles->roles() as $role) {
            if (RoleSafety::isSignUpSafe($role)) {
                $choices[$role->name()] = $role->label();
            }
        }

        return $choices;
    }

    public function is(Role $role): bool
    {
        return $role->name() === $this->name();
    }

    /**
     * @throws RbacException when the role doesn't exist or isn't sign-up-safe
     */
    public function change(string $name): void
    {
        $role = $this->roles->findRole($name);
        if ($role === null) {
            throw new RbacException(sprintf('There is no role called "%s".', $name));
        }
        if (!RoleSafety::isSignUpSafe($role)) {
            throw new RbacException(sprintf(
                'People who sign up can\'t get the "%s" role: it can do more than is safe for strangers. '
                . 'Choose a role such as "member" or "contributor".',
                $name,
            ));
        }

        $this->settings->set(self::SETTING, $name);
    }
}
