<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Migration;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base class for migrations that run on every engine SightPress supports. Extend it instead of
 * {@see AbstractMigration}.
 *
 * MySQL and MariaDB commit on every schema change (CREATE, ALTER, DROP), so a migration can't run in a transaction
 * there: the transaction the migrator opened would already be gone, and the connection would wrongly think it is
 * still inside one (later writes then fail with "SAVEPOINT … does not exist"). On those engines a migration runs
 * without a transaction; on SQLite and PostgreSQL, which can undo schema changes, it still runs in one.
 */
abstract class PortableMigration extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }
}
