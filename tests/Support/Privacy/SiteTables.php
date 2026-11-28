<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * Every table of core's site migrations (optional modules too) in SQLite in memory, made from the migrations' schema
 * changes without the migrations runner, plus helpers to add the rows privacy tests need.
 */
final class SiteTables
{
    public static function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $src = dirname(__DIR__, 3) . '/src';
        $files = glob($src . '/*/Migrations/Version*.php');
        $files = $files === false ? [] : $files;
        usort($files, static fn (string $a, string $b): int => [basename($a), $a] <=> [basename($b), $b]);
        foreach ($files as $file) {
            $class = 'SightPress\\Core\\' . str_replace('/', '\\', substr($file, strlen($src) + 1, -4));
            $migration = new $class($connection, new NullLogger());
            if ($migration instanceof AbstractMigration) {
                $migration->up($schema);
            }
        }
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function user(Connection $connection, int $id, string $email, array $values = []): void
    {
        $connection->insert('users', $values + [
            'id' => $id,
            'email' => $email,
            'password_hash' => 'hash',
            'status' => 'active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'timezone' => 'Europe/London',
            'admin_reduced_motion' => 0,
            'admin_larger_text' => 0,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-02 10:00:00',
        ]);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-28 12:00:00 UTC');
    }
}
