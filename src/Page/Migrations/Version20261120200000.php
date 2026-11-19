<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Translations drafted by the AI assistant (A-6): `page_translations.machine_drafted` is true until a person edits the
 * translation or says they checked it; until then it can't be published.
 */
final class Version20261120200000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the machine_drafted column to page_translations.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('page_translations')->addColumn('machine_drafted', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('page_translations')->dropColumn('machine_drafted');
    }
}
