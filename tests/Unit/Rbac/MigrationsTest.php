<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Migrations\Version20261116120000 as MediaTable;
use SightPress\Core\Rbac\Migrations\Version20261114120000 as RbacTables;
use SightPress\Core\Site\Migrations\Version20261114120000 as SiteSettingsTable;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\User\Migrations\Version20261114120000 as UsersTable;

/**
 * The core modules' migrations create their tables and drop them again.
 */
#[CoversClass(RbacTables::class)]
#[CoversClass(SiteSettingsTable::class)]
#[CoversClass(UsersTable::class)]
#[CoversClass(MediaTable::class)]
#[UsesNamespace('SightPress\Core')]
final class MigrationsTest extends TestCase
{
    public function testCreateAndDropTheTables(): void
    {
        $database = new CoreSiteDatabase();

        self::assertSame(
            ['auth_remember_tokens', 'media', 'migration_versions', 'permissions', 'role_permission', 'roles', 'site_settings', 'user_role', 'users'],
            $database->tables(),
        );
        $descriptions = array_map(
            static fn ($migration) => $migration->getMigration()->getDescription(),
            $database->dependencies()->getMigrationRepository()->getMigrations()->getItems(),
        );
        self::assertContains('Create the users table.', $descriptions);
        self::assertContains('Create the media table.', $descriptions);
        self::assertContains('Create the site_settings table.', $descriptions);
        self::assertContains('Create the permissions, roles, role_permission and user_role tables.', $descriptions);

        $database->migrate('first');

        self::assertSame(['migration_versions'], $database->tables());
    }
}
