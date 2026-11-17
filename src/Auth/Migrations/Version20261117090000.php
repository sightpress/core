<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `verification_code` table (C-3.7): one live six-digit code per purpose and address. Only HMACs of the address
 * and of the code are stored, never the code.
 */
final class Version20261117090000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the verification_code table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('verification_code');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('purpose', Types::STRING, ['length' => 20]);
        $table->addColumn('channel', Types::STRING, ['length' => 10]);
        $table->addColumn('account_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('target_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('code_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['purpose', 'target_hash'], 'verification_code_target');
        $table->addIndex(['expires_at'], 'verification_code_expires');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('verification_code');
    }
}
