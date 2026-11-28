<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The account a session belongs to, if any, for finding and ending a person's sessions (H-9, GD-1). Sessions started
 * before have none until they are next written.
 */
final class Version20261128090000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the account to the session table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('session');
        $table->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $table->addIndex(['user_id'], 'session_user');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('session');
        $table->dropIndex('session_user');
        $table->dropColumn('user_id');
    }
}
