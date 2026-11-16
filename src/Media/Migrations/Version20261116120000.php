<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `media` table (C-5.1).
 */
final class Version20261116120000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the media table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('media');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('storage_key', Types::STRING, ['length' => 32]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64]);
        $table->addColumn('kind', Types::STRING, ['length' => 20]);
        $table->addColumn('mime_type', Types::STRING, ['length' => 100]);
        $table->addColumn('extension', Types::STRING, ['length' => 10]);
        $table->addColumn('file_name', Types::STRING, ['length' => 100]);
        $table->addColumn('original_name', Types::STRING, ['length' => 255]);
        $table->addColumn('size', Types::INTEGER);
        $table->addColumn('width', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('height', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('variants', Types::JSON);
        $table->addColumn('alt', Types::JSON);
        $table->addColumn('caption', Types::JSON);
        $table->addColumn('transcript', Types::TEXT, ['notnull' => false]);
        $table->addColumn('published', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('uploaded_by', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['storage_key'], 'media_storage_key_unique');
        $table->addUniqueIndex(['checksum'], 'media_checksum_unique');
        $table->addIndex(['kind', 'created_at'], 'media_kind_created_idx');
        $table->addIndex(['created_at'], 'media_created_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('media');
    }
}
