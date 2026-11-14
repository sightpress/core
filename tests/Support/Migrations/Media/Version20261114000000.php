<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Migrations\Media;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * First migration of a test "media" module: same version number as the events module's, in its own namespace.
 */
final class Version20261114000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('acme_media');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('alt_text', 'string', ['length' => 500]);
        $table->addPrimaryKeyConstraint(
            \Doctrine\DBAL\Schema\PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('acme_media');
    }
}
