<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Template\Message;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleChanged;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Rbac\Entity\Role;

/**
 * D3: with `user.manage` (admins) any role can be given; with only `user.invite` (editors) only `contributor`.
 * Roles are listed with the seeded ones first, from most to least powerful, then custom roles by name.
 */
final readonly class RoleGrantPolicy implements RoleGrants
{
    public const string MANAGE = 'user.manage';
    public const string INVITE = 'user.invite';

    private const array DESCRIPTIONS = [
        RoleName::ADMIN => 'Can do everything, including inviting people, changing roles and site settings.',
        RoleName::EDITOR => 'Can write, review and publish any page, and invite contributors.',
        RoleName::CONTRIBUTOR => 'Can write pages and send them for review, but not publish them.',
        RoleName::MEMBER => 'Can sign in and read the site, but not use the admin panel.',
    ];

    public function __construct(
        private RoleRepository $roles,
        private EventDispatcherInterface $events,
    ) {
    }

    public function grantableBy(Actor $actor): array
    {
        return array_values(array_map(
            self::choice(...),
            array_filter($this->ordered(), fn (Role $role): bool => $this->may($actor, $role->name())),
        ));
    }

    public function canGrant(Actor $actor, string $role): bool
    {
        return $this->may($actor, $role) && $this->roles->findRole($role) !== null;
    }

    public function assign(int $userId, string $role): void
    {
        $this->roles->assign($userId, $this->roles->findRole($role) ?? throw new InvalidArgumentException(sprintf('There is no role called "%s".', $role)));
    }

    public function find(string $role): ?RoleChoice
    {
        $found = $this->roles->findRole($role);

        return $found === null ? null : self::choice($found);
    }

    public function roleOf(int $userId): ?RoleChoice
    {
        $roles = $this->roles->rolesOf($userId);

        return $roles === [] ? null : self::choice($roles[0]);
    }

    public function rolesOf(array $userIds): array
    {
        return array_map(static fn (array $roles): RoleChoice => self::choice($roles[0]), $this->roles->rolesOfUsers($userIds));
    }

    public function change(Actor $actor, int $userId, string $role): void
    {
        if ($actor->userId === $userId) {
            throw new InvalidArgumentException('Nobody can change their own role.');
        }
        if (!$this->canGrant($actor, $role)) {
            throw new InvalidArgumentException(sprintf('This person may not give the role "%s".', $role));
        }
        $from = $this->roleOf($userId)?->name;
        $this->assign($userId, $role);
        $this->events->dispatch(new RoleChanged($userId, $actor->userId, $from, $role));
    }

    private function may(Actor $actor, string $role): bool
    {
        return $actor->has(self::MANAGE) || ($actor->has(self::INVITE) && $role === RoleName::CONTRIBUTOR);
    }

    /**
     * @return list<Role>
     */
    private function ordered(): array
    {
        $order = array_flip(array_keys(RoleName::seeded()));
        $roles = $this->roles->roles();
        usort($roles, static fn (Role $a, Role $b): int => [$order[$a->name()] ?? PHP_INT_MAX, $a->name()] <=> [$order[$b->name()] ?? PHP_INT_MAX, $b->name()]);

        return $roles;
    }

    private static function choice(Role $role): RoleChoice
    {
        return new RoleChoice($role->name(), $role->label(), self::DESCRIPTIONS[$role->name()] ?? new Message('A custom role: {label}.', ['label' => Message::of($role->label())]));
    }
}
