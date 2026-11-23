<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Captions files per language for a video, and which transcripts the AI assistant tidied and nobody has checked yet
 * (M-4). No new personal data beyond what transcripts and captions already say.
 */
final class Version20261123150000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add caption_tracks and transcript_suggested to media.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('media');
        $table->addColumn('caption_tracks', Types::JSON, ['notnull' => false]);
        $table->addColumn('transcript_suggested', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('media');
        $table->dropColumn('caption_tracks');
        $table->dropColumn('transcript_suggested');
    }
}
