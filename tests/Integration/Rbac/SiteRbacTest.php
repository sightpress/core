<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Rbac;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RbacException;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Core\Rbac\RoleManager;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tests\Integration\IntegrationTestCase;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Users, roles and the sign-up guardrail on SQLite in memory, wired the way the kernel wires them: the module
 * services come from the site container and work on the site's own database.
 */
#[CoversNothing]
final class SiteRbacTest extends IntegrationTestCase
{
    public function testTheSiteContainerSeedsRolesAndGivesAUserARole(): void
    {
        $project = new TestProject([], ['demo' => SiteFixture::entry()]);
        try {
            $kernel = Kernel::boot($project->path(), ['APP_ENV' => 'dev', 'APP_SECRET' => SiteFixture::SECRET]);
            $site = Services::get($kernel->container(), SiteContainerFactory::class)->forSite(SiteFixture::site());

            // The site's database is SQLite in memory: run core's migrations on that same connection.
            $connection = Services::get($site, Connection::class);
            $dependencies = DependencyFactory::fromConnection(
                new ExistingConfiguration(
                    new MigrationConfigurationFactory()->create($kernel->modules()),
                ),
                new ExistingConnection($connection),
            );
            $dependencies->getMetadataStorage()->ensureInitialized();
            $dependencies->getMigrator()->migrate(
                $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
                    $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
                ),
                new MigratorConfiguration(),
            );

            $report = Services::get($site, RbacSeeder::class)->seed();
            self::assertSame(['admin', 'editor', 'contributor', 'member'], $report->addedRoles);

            $users = Services::get($site, UserRepository::class);
            $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', new DateTimeImmutable());
            $users->save($user);
            $roles = Services::get($site, RoleRepository::class);
            $editor = $roles->findRole('editor');
            self::assertNotNull($editor);
            $roles->assign((int) $user->id(), $editor);

            self::assertContains('page.publish', $roles->permissionNamesOf((int) $user->id()));
            self::assertSame('member', Services::get($site, RegistrationRole::class)->name());

            $this->expectException(RbacException::class);
            Services::get($site, RoleManager::class)->grant($roles->findRole('member') ?? $editor, 'admin.access');
        } finally {
            $project->remove();
        }
    }
}
