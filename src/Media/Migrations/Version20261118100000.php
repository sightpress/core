<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Transcripts per language (I-1): `media.transcripts` holds one per locale, like the alt text and captions. The old
 * `transcript` column keeps the transcript written before, which counts as the main language's until it is changed.
 */
final class Version20261118100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the transcripts column to media.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('media')->addColumn('transcripts', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('media')->dropColumn('transcripts');
    }
}
