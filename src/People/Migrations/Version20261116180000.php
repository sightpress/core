<?php

declare(strict_types=1);

namespace SightPress\Core\People\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `invitations` table (C-3.4). Only a hash of each invitation's link token is stored.
 */
final class Version20261116180000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the invitations table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('invitations');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('email', Types::STRING, ['length' => 254]);
        $table->addColumn('role', Types::STRING, ['length' => 50]);
        $table->addColumn('first_name', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('last_name', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('invited_by', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['token_hash'], 'invitations_token');
        $table->addIndex(['email'], 'invitations_email');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invitations');
    }
}
