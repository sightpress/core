<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Migrations\Version20261116120000 as MediaTable;
use SightPress\Core\Menu\Migrations\Version20261116210000 as MenuTable;
use SightPress\Core\Menu\Migrations\Version20261118400000 as MenuLabels;
use SightPress\Core\Page\Migrations\Version20261116130000 as PageTables;
use SightPress\Core\Page\Migrations\Version20261116220000 as PageShareImage;
use SightPress\Core\Page\Migrations\Version20261116230000 as PageSearchFields;
use SightPress\Core\Page\Migrations\Version20261118100000 as PageTranslationDrafts;
use SightPress\Core\Page\Migrations\Version20261118200000 as PageRedirectsTable;
use SightPress\Core\Page\Migrations\Version20261118300000 as PageSchedule;
use SightPress\Core\Media\Migrations\Version20261118100000 as MediaTranscripts;
use SightPress\Core\Media\Migrations\Version20261120100000 as MediaAltSuggested;
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
#[CoversClass(MenuLabels::class)]
#[CoversClass(\SightPress\Core\Audit\Migrations\Version20261117120000::class)]
#[CoversClass(PageShareImage::class)]
#[CoversClass(PageSearchFields::class)]
#[CoversClass(PageTranslationDrafts::class)]
#[CoversClass(MediaTranscripts::class)]
#[CoversClass(MediaAltSuggested::class)]
#[CoversClass(PageRedirectsTable::class)]
#[CoversClass(PageSchedule::class)]
#[CoversClass(\SightPress\Core\User\Migrations\Version20261118500000::class)]
#[CoversClass(\SightPress\Core\Feature\Migrations\Version20261118600000::class)]
#[CoversClass(\SightPress\Core\Page\Migrations\Version20261119100000::class)]
#[CoversClass(\SightPress\Core\Search\Migrations\Version20261119110000::class)]
#[CoversClass(\SightPress\Core\Analytics\Migrations\Version20261119200000::class)]
#[UsesNamespace('SightPress\Core')]
final class MigrationsTest extends TestCase
{
    public function testCreateAndDropTheTables(): void
    {
        $database = new CoreSiteDatabase();

        self::assertSame(
            ['analytics_daily', 'analytics_hit', 'analytics_salt', 'audit_log', 'auth_remember_tokens', 'invitations', 'media', 'menu_items', 'migration_versions', 'page_redirects', 'page_revisions', 'page_translations', 'pages', 'permissions', 'role_permission', 'roles', 'search_document', 'search_posting', 'search_word', 'site_module', 'site_settings', 'user_role', 'users', 'verification_code'],
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
        self::assertContains('Create the audit_log table.', $descriptions);
        self::assertContains('Add the social_image_id column to page_translations.', $descriptions);
        self::assertContains('Add the seo_title, noindex and canonical_url columns to page_translations.', $descriptions);
        self::assertContains('Add the draft column to page_translations.', $descriptions);
        self::assertContains('Add the transcripts column to media.', $descriptions);
        self::assertContains('Create the page_redirects table.', $descriptions);
        self::assertContains('Add the publish_at, unpublish_at and scheduled_by columns to pages.', $descriptions);
        self::assertContains('Add the admin_reduced_motion and admin_larger_text columns to users.', $descriptions);
        self::assertContains('Create the permissions, roles, role_permission and user_role tables.', $descriptions);
        self::assertContains('Add the exclude_from_site_search column to page_translations.', $descriptions);
        self::assertContains('Create the search_document, search_posting and search_word tables.', $descriptions);

        $database->migrate('first');

        self::assertSame(['migration_versions'], $database->tables());
    }
}
