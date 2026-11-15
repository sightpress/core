<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `auth_remember_tokens` table: "keep me signed in" tokens (C-3.3). Only a hash of each token's secret part is
 * stored.
 */
final class Version20261114130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the auth_remember_tokens table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('auth_remember_tokens');
        $table->addColumn('selector', Types::STRING, ['length' => 32]);
        $table->addColumn('validator_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('user_id', Types::INTEGER);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('selector')->create());
        $table->addIndex(['user_id'], 'auth_remember_tokens_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('auth_remember_tokens');
    }
}
