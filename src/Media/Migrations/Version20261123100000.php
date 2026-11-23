<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * How many requests the web server refused for their size, per day (M-5b). No personal data.
 */
final class Version20261123100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the upload_refusal table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('upload_refusal');
        $table->addColumn('refused_on', Types::STRING, ['length' => 10]);
        $table->addColumn('refused_count', Types::INTEGER);
        $table->setPrimaryKey(['refused_on']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('upload_refusal');
    }
}
