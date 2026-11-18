<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Translations of their own (I-1): `page_translations.draft` says a translation isn't published yet, even when its
 * page is. Translations that exist already stay as they are (not drafts).
 */
final class Version20261118100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the draft column to page_translations.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('page_translations')->addColumn('draft', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('page_translations')->dropColumn('draft');
    }
}
