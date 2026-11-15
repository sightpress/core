<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(DatabaseProvisioner::class)]
#[UsesClass(DatabaseDsn::class)]
#[UsesClass(DatabaseDsnException::class)]
final class DatabaseProvisionerTest extends TestCase
{
    private const string SERVER = 'postgresql://site:s3cret@db.internal:5432/demo';

    private TemporaryDirectory $directory;

    /** @var list<array<mixed>> */
    private array $connected = [];

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testCreatesASqliteFileAndItsFolder(): void
    {
        $file = $this->directory->path . '/sites/demo/demo.sqlite';
        $provisioner = new DatabaseProvisioner();

        $provisioner->prepare(DatabaseDsn::parse('sqlite:///' . $file));
        self::assertFileExists($file);

        $provisioner->prepare(DatabaseDsn::parse('sqlite:///' . $file), true);
        $provisioner->prepare(DatabaseDsn::parse('sqlite:///:memory:'));
        $provisioner->drop(DatabaseDsn::parse('sqlite:///:memory:'));
        touch($file . '-wal');
        $provisioner->drop(DatabaseDsn::parse('sqlite:///' . $file));
        self::assertFileDoesNotExist($file);
        self::assertFileDoesNotExist($file . '-wal');
    }

    public function testASqliteFileThatCantBeWrittenIsExplained(): void
    {
        $this->expectException(DatabaseDsnException::class);
        $this->expectExceptionMessage('The folder of the SQLite file /proc/sightpress/demo.sqlite can\'t be created.');

        new DatabaseProvisioner()->prepare(DatabaseDsn::parse('sqlite:////proc/sightpress/demo.sqlite'));
    }

    public function testASqliteFileThatCantBeOpenedIsExplained(): void
    {
        mkdir($this->directory->path . '/folder.sqlite');

        $this->expectExceptionMessage('can\'t be opened: check that the server may write to it.');

        new DatabaseProvisioner()->prepare(DatabaseDsn::parse('sqlite:///' . $this->directory->path . '/folder.sqlite'));
    }

    public function testAnExistingServerDatabaseIsOnlyChecked(): void
    {
        $provisioner = new DatabaseProvisioner(fn (array $params): Connection => $this->connection($params));

        $provisioner->prepare(DatabaseDsn::parse(self::SERVER));

        self::assertCount(1, $this->connected);
        self::assertSame('demo', $this->connected[0]['dbname']);
    }

    public function testAMissingDatabaseIsCreatedOnlyWhenAllowed(): void
    {
        $created = [];
        $attempts = 0;
        $connect = function (array $params) use (&$created, &$attempts): Connection {
            if (($params['dbname'] ?? null) === 'demo' && $attempts++ === 0) {
                throw self::refused();
            }

            return $this->connection($params, [], $created);
        };

        try {
            new DatabaseProvisioner($connect)->prepare(DatabaseDsn::parse(self::SERVER));
            self::fail('A missing database must not be created without permission.');
        } catch (DatabaseDsnException $exception) {
            self::assertSame(
                'The PostgreSQL database "demo" on db.internal:5432 does not exist. Create it, or run the command again with --create-db.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
        }

        $attempts = 0;
        new DatabaseProvisioner($connect)->prepare(DatabaseDsn::parse(self::SERVER), true);

        self::assertSame(['demo'], $created);
        self::assertSame('postgres', $this->connected[1]['dbname'], 'PostgreSQL is asked through its own database');
    }

    public function testMySqlServersAreAskedWithoutADatabase(): void
    {
        $connect = function (array $params): Connection {
            if (isset($params['dbname'])) {
                throw self::refused();
            }

            return $this->connection($params, ['demo']);
        };

        $this->expectExceptionMessage('The MySQL/MariaDB database "demo" on db.internal exists, but this user can\'t open it');

        new DatabaseProvisioner($connect)->prepare(DatabaseDsn::parse('mysql://site:s3cret@db.internal/demo'));
    }

    public function testAServerThatDoesNotAnswerIsExplained(): void
    {
        $provisioner = new DatabaseProvisioner(static fn (array $params): Connection => throw self::refused());

        $this->expectExceptionMessage(
            'Can\'t connect to the database server of the PostgreSQL database "demo" on db.internal:5432: check the host, the port, the user name and the password.',
        );

        $provisioner->prepare(DatabaseDsn::parse(self::SERVER));
    }

    public function testAUserThatMayNotCreateDatabasesIsExplained(): void
    {
        $connect = function (array $params): Connection {
            if (($params['dbname'] ?? null) === 'demo') {
                throw self::refused();
            }

            return $this->connection($params, [], failCreate: true);
        };

        $this->expectExceptionMessage('could not be checked or created: check that the user may create databases.');

        new DatabaseProvisioner($connect)->prepare(DatabaseDsn::parse(self::SERVER), true);
    }

    public function testACreatedDatabaseTheUserCantOpenIsExplained(): void
    {
        $connect = function (array $params): Connection {
            if (($params['dbname'] ?? null) === 'demo') {
                throw self::refused();
            }

            return $this->connection($params);
        };

        $this->expectExceptionMessage('was created, but this user can\'t open it.');

        new DatabaseProvisioner($connect)->prepare(DatabaseDsn::parse(self::SERVER), true);
    }

    public function testDropsAServerDatabase(): void
    {
        $dropped = [];
        $connect = function (array $params) use (&$dropped): Connection {
            return $this->connection($params, [], dropped: $dropped);
        };
        new DatabaseProvisioner($connect)->drop(DatabaseDsn::parse(self::SERVER));

        self::assertSame(['demo'], $dropped);
    }

    public function testADatabaseThatCantBeDroppedIsExplained(): void
    {
        $provisioner = new DatabaseProvisioner(fn (array $params): Connection => $this->connection($params, failDrop: true));

        $this->expectExceptionMessage('could not be deleted: check that it exists and that the user may delete it.');

        $provisioner->drop(DatabaseDsn::parse(self::SERVER));
    }

    public function testASqliteFileThatCantBeDeletedIsExplained(): void
    {
        $folder = $this->directory->path . '/locked';
        mkdir($folder);
        touch($folder . '/demo.sqlite');
        chmod($folder, 0o500);

        try {
            $this->expectExceptionMessage('could not be deleted.');
            @new DatabaseProvisioner()->drop(DatabaseDsn::parse('sqlite:///' . $folder . '/demo.sqlite'));
        } finally {
            chmod($folder, 0o700);
        }
    }

    private static function refused(): ConnectionException
    {
        return new ConnectionException(PdoDriverException::new(new PDOException('refused')), null);
    }

    /**
     * A connection that answers, with a schema manager that records what it is asked to do.
     *
     * @param array<mixed> $params
     * @param list<string>         $databases
     * @param list<string>         $created
     * @param list<string>         $dropped
     */
    private function connection(
        array $params,
        array $databases = [],
        array &$created = [],
        bool $failCreate = false,
        array &$dropped = [],
        bool $failDrop = false,
    ): Connection {
        $this->connected[] = $params;
        $schema = self::createStub(AbstractSchemaManager::class);
        $schema->method('listDatabases')->willReturn($databases);
        $schema->method('createDatabase')->willReturnCallback(static function (string $name) use (&$created, $failCreate): void {
            if ($failCreate) {
                throw self::refused();
            }
            $created[] = $name;
        });
        $schema->method('dropDatabase')->willReturnCallback(static function (string $name) use (&$dropped, $failDrop): void {
            if ($failDrop) {
                throw self::refused();
            }
            $dropped[] = $name;
        });
        $connection = self::createStub(Connection::class);
        $connection->method('getServerVersion')->willReturn('17.0');
        $connection->method('createSchemaManager')->willReturn($schema);

        return $connection;
    }
}
