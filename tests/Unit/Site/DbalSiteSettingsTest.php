<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Site\DbalSiteSettings;
use SightPress\Core\Site\SiteMigrations;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(DbalSiteSettings::class)]
#[CoversClass(SiteMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class DbalSiteSettingsTest extends TestCase
{
    public function testStoresAndChangesValues(): void
    {
        $database = new CoreSiteDatabase();
        $settings = new DbalSiteSettings($database->connection);

        self::assertNull($settings->get('rbac.registration_role'));
        $settings->set('rbac.registration_role', 'member');
        $settings->set('rbac.registration_role', 'contributor');

        self::assertSame('contributor', $settings->get('rbac.registration_role'));
        self::assertSame('contributor', new DbalSiteSettings($database->connection)->get('rbac.registration_role'));
        self::assertEquals(1, $database->connection->createQueryBuilder()->select('COUNT(*)')->from('site_settings')->fetchOne());
    }

    public function testTheMigrationsAreWithTheModule(): void
    {
        $migrations = new SiteMigrations();

        self::assertSame('SightPress\Core\Site\Migrations', $migrations->namespace());
        self::assertDirectoryExists($migrations->directory());
    }
}
