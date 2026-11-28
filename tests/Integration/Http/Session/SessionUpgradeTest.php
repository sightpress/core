<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\DatabaseSessionStore;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Site\Migrations\Version20261128090000;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * A site whose `session` table was made before it had the account: going back to it and migrating again keeps the
 * sessions, and new ones can be written and ended by person. (A fresh database wouldn't show an edited migration.)
 */
#[CoversNothing]
final class SessionUpgradeTest extends TestCase
{
    public function testASiteMigratedBeforeTheAccountColumnGetsIt(): void
    {
        $site = new KernelSite('session_upgrade');
        try {
            $connection = Services::get($site->container(), Connection::class);
            $dependencies = DependencyFactory::fromConnection(
                new ExistingConfiguration(new MigrationConfigurationFactory()->create($site->kernel->modules())),
                new ExistingConnection($connection),
            );
            $version = new \Doctrine\Migrations\Version\Version(Version20261128090000::class);
            $run = static fn (string $direction): array => $dependencies->getMigrator()->migrate($dependencies->getMigrationPlanCalculator()->getPlanForVersions([$version], $direction), new MigratorConfiguration());
            $run('down');
            $connection->insert('session', ['id_hash' => str_repeat('a', 64), 'data' => '{}', 'last_seen_at' => '2026-11-28 12:00:00', 'expires_at' => '2030-01-01 00:00:00']);

            $run('up');

            $store = new DatabaseSessionStore($connection, new FixedClock(new DateTimeImmutable('2026-11-28 12:00:00 UTC')), 7200);
            $store->write('new', [Session::USER_ID => 7]);
            self::assertEquals(7, $connection->fetchOne('SELECT user_id FROM session WHERE id_hash = ?', [DatabaseSessionStore::hash('new')]));
            self::assertSame(1, $store->forgetPerson(7));
            self::assertSame([str_repeat('a', 64)], $connection->fetchFirstColumn('SELECT id_hash FROM session'), 'the old session is kept');
        } finally {
            $site->remove();
        }
    }
}
