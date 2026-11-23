<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Large files on their way to the media library, sent in parts (M-7). Personal data: `user_id` and `file_name`, kept
 * until the session is deleted: 24 hours after its last part, or 7 days after it finished.
 */
final class Version20261123120000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the media_upload table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('media_upload');
        $table->addColumn('id', Types::STRING, ['length' => 32]);
        $table->addColumn('user_id', Types::INTEGER);
        $table->addColumn('file_name', Types::STRING, ['length' => 255]);
        $table->addColumn('total_bytes', Types::BIGINT);
        $table->addColumn('received_bytes', Types::BIGINT);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('media_id', Types::INTEGER, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'completed_at'], 'idx_media_upload_user');
        $table->addIndex(['updated_at'], 'idx_media_upload_updated');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('media_upload');
    }
}
