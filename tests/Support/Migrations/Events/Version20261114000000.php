<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Migrations\Events;

use Doctrine\DBAL\Schema\Schema;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * First migration of a test "events" module.
 */
final class Version20261114000000 extends PortableMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('acme_event');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('title', 'string', ['length' => 200]);
        $table->addPrimaryKeyConstraint(
            \Doctrine\DBAL\Schema\PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('acme_event');
    }
}
