<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PermissionSummary;
use SightPress\Core\Rbac\Contract\RoleEdited;
use SightPress\Core\Rbac\Contract\RoleRefused;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Rbac\Contract\RoleSummary;
use SightPress\Core\Rbac\Entity\Permission;
use SightPress\Core\Rbac\Entity\Role;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\EnabledModules;
use Transliterator;

/**
 * {@see Roles} on top of {@see RoleManager}, which still applies the rules every role change follows; this class
 * adds the ones of the roles screen and the commands, and says what is wrong in a sentence for the person.
 */
final readonly class RoleAdministration implements Roles
{
    public function __construct(
        private RoleRepository $roles,
        private RoleManager $manager,
        private RegistrationRole $registrationRole,
        private EnabledModules $modules,
        private EventDispatcherInterface $events,
    ) {
    }

    public function roles(): array
    {
        $order = array_flip(array_keys(RoleName::seeded()));
        $roles = $this->roles->roles();
        usort($roles, static fn (Role $a, Role $b): int => [$order[$a->name()] ?? PHP_INT_MAX, $a->name()] <=> [$order[$b->name()] ?? PHP_INT_MAX, $b->name()]);

        return array_map($this->summary(...), $roles);
    }

    public function find(string $name): ?RoleSummary
    {
        $role = $this->roles->findRole($name);

        return $role === null ? null : $this->summary($role);
    }

    public function permissions(): array
    {
        return array_map(
            static fn (Permission $permission): PermissionSummary => new PermissionSummary($permission->name(), $permission->label(), $permission->description(), $permission->allowedOnSignUp()),
            $this->givable(),
        );
    }

    public function nameFor(string $label): string
    {
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($label);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', is_string($ascii) ? $ascii : strtolower($label)), '_');
        $base = preg_match('/^[a-z]/', $base) === 1 ? $base : 'role_' . $base;
        $base = rtrim(substr(rtrim($base, '_'), 0, 40), '_');
        $name = $base;
        for ($number = 2; $this->roles->findRole($name) !== null; $number++) {
            $name = $base . '_' . $number;
        }

        return $name;
    }

    public function problems(?Actor $actor, ?string $role, string $name, string $label, array $permissions): array
    {
        return $this->check($actor, $role === null ? null : $this->role($role), $name, $label, $permissions);
    }

    public function create(?Actor $actor, string $name, string $label, array $permissions): RoleSummary
    {
        $this->refuse($this->check($actor, null, $name, $label, $permissions));
        $role = $this->manager->create($name, trim($label), $permissions);
        $this->events->dispatch(new RoleEdited(RoleEdited::CREATED, $role->name(), $role->label(), $actor?->userId, $role->permissionNames()));

        return $this->summary($role);
    }

    public function update(?Actor $actor, string $role, string $label, array $permissions): bool
    {
        $entity = $this->role($role);
        $this->refuse($this->check($actor, $entity, $entity->name(), $label, $permissions));
        $label = trim($label);
        $changed = false;
        if ($label !== $entity->label()) {
            $from = $entity->label();
            $this->manager->rename($entity, $entity->name(), $label);
            $this->events->dispatch(new RoleEdited(RoleEdited::RENAMED, $entity->name(), $label, $actor?->userId, from: $from));
            $changed = true;
        }

        $before = $entity->permissionNames();
        $kept = array_diff($before, $this->shown($entity));
        $this->manager->setPermissions($entity, array_values(array_unique([...$kept, ...$permissions])));
        $after = $entity->permissionNames();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added !== [] || $removed !== []) {
            $this->events->dispatch(new RoleEdited(RoleEdited::PERMISSIONS, $entity->name(), $entity->label(), $actor?->userId, $added, $removed));
            $changed = true;
        }

        return $changed;
    }

    public function grant(?Actor $actor, string $role, string $permission): bool
    {
        $entity = $this->role($role);

        return $this->update($actor, $role, $entity->label(), [...$this->shown($entity), $permission]);
    }

    /**
     * `admin.access` goes only with the role's last other permission.
     */
    public function revoke(?Actor $actor, string $role, string $permission): bool
    {
        $entity = $this->role($role);
        $shown = $this->shown($entity);
        if (!in_array($permission, $shown, true)) {
            $this->refuse($this->check($actor, $entity, $entity->name(), $entity->label(), [$permission]));

            return false;
        }
        $remaining = array_values(array_diff($shown, [$permission]));
        if ($permission === CorePermissions::ADMIN_ACCESS && $remaining !== []) {
            throw new RoleRefused([$permission => new Message('“{role}” keeps “Use the admin panel” while it has other permissions. Take those away first.', ['role' => Message::of($entity->label())])]);
        }

        return $this->update($actor, $role, $entity->label(), $remaining);
    }

    public function deleteProblem(string $role): ?Message
    {
        $entity = $this->role($role);
        $people = $this->roles->countUsersWith($entity);
        $label = Message::of($entity->label());

        return match (true) {
            $entity->isSystem() => new Message('“{role}” is built in, so it can’t be deleted.', ['role' => $label]),
            $this->registrationRole->is($entity) => new Message('“{role}” is the role of people who sign up. Choose another sign-up role in the sign-up settings first.', ['role' => $label]),
            $people > 0 => new Message('{count, plural, one {# person has} other {# people have}} the “{role}” role. Give them another role first.', ['count' => $people, 'role' => $label]),
            default => null,
        };
    }

    public function delete(?Actor $actor, string $role): void
    {
        $problem = $this->deleteProblem($role);
        if ($problem !== null) {
            throw new RoleRefused(['role' => $problem]);
        }
        $entity = $this->role($role);
        $label = $entity->label();
        $this->manager->delete($entity);
        $this->events->dispatch(new RoleEdited(RoleEdited::DELETED, $role, $label, $actor?->userId));
    }

    /**
     * @param list<string> $permissions
     *
     * @return array<string, Message>
     */
    private function check(?Actor $actor, ?Role $role, string $name, string $label, array $permissions): array
    {
        if ($role !== null && self::isFixed($role)) {
            return ['role' => $role->name() === RoleName::ADMIN
                ? new Message('The Admin role always has every permission, so it can’t be changed.')
                : new Message('The Member role never has any permission, so it can’t be changed.')];
        }

        $problems = [];
        $trimmed = trim($label);
        if ($trimmed === '' || mb_strlen($trimmed) > 100) {
            $problems['label'] = new Message('Enter a name for the role of 1 to 100 characters.');
        }
        $other = $this->roles->findRole($name);
        if ($role === null && (preg_match(Role::NAME_PATTERN, $name) !== 1 || strlen($name) > 50)) {
            $problems['name'] = new Message('A role name is 1 to 50 lower-case letters, digits and “_”, starting with a letter, for example shop_staff.');
        } elseif ($other !== null && $other !== $role) {
            $problems['name'] = new Message('There is already a role called “{name}”.', ['name' => $name]);
        }
        $taken = $this->labelTaken($role, $trimmed);
        if ($taken !== null) {
            $problems['label'] = new Message('There is already a role called “{label}”. Choose another name.', ['label' => Message::of($taken)]);
        }

        $givable = [];
        foreach ($this->givable() as $permission) {
            $givable[$permission->name()] = $permission;
        }
        $signUp = $role !== null && $this->registrationRole->is($role);
        foreach (array_unique($permissions) as $permission) {
            if (!isset($givable[$permission])) {
                $problems[$permission] = new Message('There is no permission called “{permission}” that this site can use.', ['permission' => $permission]);
            } elseif ($signUp && !$givable[$permission]->allowedOnSignUp()) {
                $problems[$permission] = new Message('“{role}” is the role of people who sign up, and they can’t get “{permission}”: it isn’t safe for people you don’t know. Choose another sign-up role first.', [
                    'role' => Message::of($role->label()),
                    'permission' => Message::of($givable[$permission]->label()),
                ]);
            }
        }

        if ($actor?->userId !== null && $role !== null && !$this->stillManages($actor->userId, $role, $permissions)) {
            $problems[self::PERMISSION] = new Message('You manage roles through the “{role}” role, so you can’t take “Manage roles” away from it. Ask another admin to do it.', ['role' => Message::of($role->label())]);
        }

        return $problems;
    }

    private static function isFixed(Role $role): bool
    {
        return in_array($role->name(), [RoleName::ADMIN, RoleName::MEMBER], true);
    }

    /**
     * @throws RoleRefused when there is no such role
     */
    private function role(string $name): Role
    {
        return $this->roles->findRole($name) ?? throw new RoleRefused(['role' => new Message('There is no role called “{name}”. See the roles with site:role:list.', ['name' => $name])]);
    }

    private function summary(Role $role): RoleSummary
    {
        return new RoleSummary($role->name(), $role->label(), $this->shown($role), $this->roles->countUsersWith($role), self::isFixed($role), $this->registrationRole->is($role));
    }

    /**
     * Permissions of modules that are on, by name.
     *
     * @return list<Permission>
     */
    private function givable(): array
    {
        return array_values(array_filter($this->roles->permissions(), fn (Permission $permission): bool => $this->modules->has($permission->module())));
    }

    /**
     * What the role holds of the permissions that can be given.
     *
     * @return list<string>
     */
    private function shown(Role $role): array
    {
        $givable = array_map(static fn (Permission $permission): string => $permission->name(), $this->givable());

        return array_values(array_intersect($role->permissionNames(), $givable));
    }

    /**
     * @param array<string, Message> $problems
     *
     * @throws RoleRefused
     */
    private function refuse(array $problems): void
    {
        if ($problems !== []) {
            throw new RoleRefused($problems);
        }
    }

    /**
     * The label of another role that is the same as this one, ignoring case.
     */
    private function labelTaken(?Role $role, string $label): ?string
    {
        foreach ($this->roles->roles() as $other) {
            if ($other !== $role && mb_strtolower($other->label()) === mb_strtolower($label)) {
                return $other->label();
            }
        }

        return null;
    }

    /**
     * Whether the person can still manage roles once the role has these permissions. Someone who couldn't before
     * (the command line has no person) isn't stopped.
     *
     * @param list<string> $permissions
     */
    private function stillManages(int $userId, Role $role, array $permissions): bool
    {
        $before = false;
        $after = false;
        foreach ($this->roles->rolesOf($userId) as $own) {
            $before = $before || $own->hasPermission(self::PERMISSION);
            $after = $after || ($own === $role ? in_array(self::PERMISSION, $permissions, true) : $own->hasPermission(self::PERMISSION));
        }

        return $after || !$before;
    }
}
