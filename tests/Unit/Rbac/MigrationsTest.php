<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Migrations\Version20261116120000 as MediaTable;
use SightPress\Core\Menu\Migrations\Version20261116210000 as MenuTable;
use SightPress\Core\Page\Migrations\Version20261116130000 as PageTables;
use SightPress\Core\Page\Migrations\Version20261116220000 as PageShareImage;
use SightPress\Core\Page\Migrations\Version20261116230000 as PageSearchFields;
use SightPress\Core\People\Migrations\Version20261116180000 as InvitationsTable;
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
#[CoversClass(PageTables::class)]
#[CoversClass(InvitationsTable::class)]
#[CoversClass(MenuTable::class)]
#[CoversClass(PageShareImage::class)]
#[CoversClass(PageSearchFields::class)]
#[UsesNamespace('SightPress\Core')]
final class MigrationsTest extends TestCase
{
    public function testCreateAndDropTheTables(): void
    {
        $database = new CoreSiteDatabase();

        self::assertSame(
            ['auth_remember_tokens', 'invitations', 'media', 'menu_items', 'migration_versions', 'page_revisions', 'page_translations', 'pages', 'permissions', 'role_permission', 'roles', 'site_settings', 'user_role', 'users'],
            $database->tables(),
        );
        $descriptions = array_map(
            static fn ($migration) => $migration->getMigration()->getDescription(),
            $database->dependencies()->getMigrationRepository()->getMigrations()->getItems(),
        );
        self::assertContains('Create the users table.', $descriptions);
        self::assertContains('Create the media table.', $descriptions);
        self::assertContains('Create the pages, page_translations and page_revisions tables.', $descriptions);
        self::assertContains('Create the site_settings table.', $descriptions);
        self::assertContains('Create the invitations table.', $descriptions);
        self::assertContains('Create the menu_items table.', $descriptions);
        self::assertContains('Add the social_image_id column to page_translations.', $descriptions);
        self::assertContains('Add the seo_title, noindex and canonical_url columns to page_translations.', $descriptions);
        self::assertContains('Create the permissions, roles, role_permission and user_role tables.', $descriptions);

        $database->migrate('first');

        self::assertSame(['migration_versions'], $database->tables());
    }
}
