<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * A page's own share image (C-6.6): `page_translations.social_image_id`.
 */
final class Version20261116220000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the social_image_id column to page_translations.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('page_translations')->addColumn('social_image_id', Types::INTEGER, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('page_translations')->dropColumn('social_image_id');
    }
}
