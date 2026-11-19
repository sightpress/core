<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Site search (SR-1): `page_translations.exclude_from_site_search` leaves a translation out of the site's own
 * search, separately from `noindex` (ADR-0011).
 */
final class Version20261119100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the exclude_from_site_search column to page_translations.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('page_translations')->addColumn('exclude_from_site_search', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('page_translations')->dropColumn('exclude_from_site_search');
    }
}
