<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\SiteModuleStore;

#[CoversClass(SiteModuleStore::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBoxException::class)]
final class SiteModuleStoreTest extends TestCase
{
    public function testWithoutItsTableEveryModuleFollowsItsDefault(): void
    {
        $store = new SiteModuleStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        self::assertSame([], $store->choices());

        $locked = new SiteModuleStore(new \ReflectionClass(\Doctrine\DBAL\Connection::class)->newLazyProxy(
            static fn () => throw new \SightPress\Core\Infrastructure\Crypto\SecretBoxException('APP_SECRET is not set.'),
        ));
        self::assertSame([], $locked->choices(), 'a database that can\'t be opened has no choices either');
    }

    public function testStoresAndReadsTheSitesChoices(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $table = $schema->createTable(SiteModuleStore::TABLE);
        $table->addColumn('module_id', Types::STRING, ['length' => 64]);
        $table->addColumn('enabled', Types::BOOLEAN);
        $table->addColumn('changed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('changed_by', Types::INTEGER, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('module_id')->create());
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        $store = new SiteModuleStore($connection);
        $store->save('acme.events', false, new DateTimeImmutable('2026-11-18 10:00:00'), null);
        self::assertSame(['acme.events' => false], $store->choices());
        $store->save('acme.events', true, new DateTimeImmutable('2026-11-18 11:00:00'), 3);
        $store->save('acme.forms', false, new DateTimeImmutable('2026-11-18 11:00:00'), 3);
        self::assertSame(['acme.events' => true, 'acme.forms' => false], $store->choices(), 'the choices read once are kept up to date');
        self::assertSame(['acme.events' => true, 'acme.forms' => false], new SiteModuleStore($connection)->choices());
        self::assertSame(
            ['changed_by' => 3],
            $connection->createQueryBuilder()->select('changed_by')->from(SiteModuleStore::TABLE)->where("module_id = 'acme.events'")->fetchAssociative(),
        );
    }
}
