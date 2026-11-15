<?php

declare(strict_types=1);

namespace SightPress\Core\User\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `users` table (C-3.1).
 */
final class Version20261114120000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the users table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('users');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('email', Types::STRING, ['length' => 254]);
        $table->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('status', Types::STRING, ['length' => 20]);
        $table->addColumn('first_name', Types::STRING, ['length' => 100]);
        $table->addColumn('last_name', Types::STRING, ['length' => 100]);
        $table->addColumn('display_name', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('avatar_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('avatar_kind', Types::STRING, ['length' => 20, 'notnull' => false]);
        $table->addColumn('avatar_color', Types::STRING, ['length' => 7, 'notnull' => false]);
        $table->addColumn('timezone', Types::STRING, ['length' => 64]);
        $table->addColumn('locale', Types::STRING, ['length' => 35, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['email'], 'users_email_unique');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}
