<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Uploads that go straight to the site's bucket (M-8). No new personal data: `refusal` is a sentence about the file.
 */
final class Version20261123140000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the direct-to-bucket columns to media_upload.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('media_upload');
        $table->addColumn('mode', Types::STRING, ['length' => 10, 'default' => 'temp']);
        $table->addColumn('state', Types::STRING, ['length' => 12, 'default' => 'receiving']);
        $table->addColumn('remote_id', Types::STRING, ['length' => 1024, 'notnull' => false]);
        $table->addColumn('part_bytes', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('refusal', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('media_upload');
        foreach (['mode', 'state', 'remote_id', 'part_bytes', 'refusal'] as $column) {
            $table->dropColumn($column);
        }
    }
}
