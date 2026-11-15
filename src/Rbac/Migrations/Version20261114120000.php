<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The RBAC tables (C-3.1): `permissions`, `roles`, `role_permission` and `user_role`.
 *
 * `user_role.user_id` has no foreign key to `users`: that table belongs to the user module, whose migrations may
 * run after these. Users are deactivated, not deleted, in M1.
 */
final class Version20261114120000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the permissions, roles, role_permission and user_role tables.';
    }

    public function up(Schema $schema): void
    {
        $permissions = $schema->createTable('permissions');
        $permissions->addColumn('name', Types::STRING, ['length' => 100]);
        $permissions->addColumn('label', Types::STRING, ['length' => 200]);
        $permissions->addColumn('description', Types::STRING, ['length' => 500]);
        $permissions->addColumn('module', Types::STRING, ['length' => 100]);
        $permissions->addColumn('allowed_on_sign_up', Types::BOOLEAN);
        $permissions->addPrimaryKeyConstraint(self::primaryKey('name'));

        $roles = $schema->createTable('roles');
        $roles->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $roles->addColumn('name', Types::STRING, ['length' => 50]);
        $roles->addColumn('label', Types::STRING, ['length' => 100]);
        $roles->addColumn('is_system', Types::BOOLEAN);
        $roles->addPrimaryKeyConstraint(self::primaryKey('id'));
        $roles->addUniqueIndex(['name'], 'roles_name_unique');

        $grants = $schema->createTable('role_permission');
        $grants->addColumn('role_id', Types::INTEGER);
        $grants->addColumn('permission_name', Types::STRING, ['length' => 100]);
        $grants->addPrimaryKeyConstraint(self::primaryKey('role_id', 'permission_name'));
        $grants->addIndex(['permission_name'], 'role_permission_permission');
        $grants->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE'], 'role_permission_role_fk');
        $grants->addForeignKeyConstraint(
            'permissions',
            ['permission_name'],
            ['name'],
            ['onDelete' => 'CASCADE'],
            'role_permission_permission_fk',
        );

        $assignments = $schema->createTable('user_role');
        $assignments->addColumn('user_id', Types::INTEGER);
        $assignments->addColumn('role_id', Types::INTEGER);
        $assignments->addPrimaryKeyConstraint(self::primaryKey('user_id', 'role_id'));
        $assignments->addIndex(['role_id'], 'user_role_role');
        $assignments->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE'], 'user_role_role_fk');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('user_role');
        $schema->dropTable('role_permission');
        $schema->dropTable('roles');
        $schema->dropTable('permissions');
    }

    /**
     * @param non-empty-string $first
     * @param non-empty-string ...$others
     */
    private static function primaryKey(string $first, string ...$others): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setUnquotedColumnNames($first, ...$others)->create();
    }
}
