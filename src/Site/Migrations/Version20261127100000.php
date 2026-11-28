<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `session` table (H-9): the site's sessions when `session.store` is `database`, so every web server shares
 * them. Rows are keyed by a SHA-256 hash of the session id, never the id; `expires_at` is indexed for the expiry job.
 */
final class Version20261127100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the session table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('session');
        $table->addColumn('id_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('data', Types::TEXT);
        $table->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id_hash')->create());
        $table->addIndex(['expires_at'], 'session_expires');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('session');
    }
}
