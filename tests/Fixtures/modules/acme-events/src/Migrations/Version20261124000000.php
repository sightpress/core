<?php

declare(strict_types=1);

namespace Acme\Events\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('acme_events_event');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('slug', 'string', ['length' => 100]);
        $table->addColumn('title', 'string', ['length' => 200]);
        $table->addColumn('starts_at', 'string', ['length' => 25]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['slug'], 'acme_events_event_slug');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('acme_events_event');
    }

    public function isTransactional(): bool
    {
        // MySQL commits schema changes on its own.
        return !$this->platform instanceof AbstractMySQLPlatform;
    }
}
