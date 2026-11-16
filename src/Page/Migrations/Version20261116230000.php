<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * What search engines get of a page (C-6.7): `page_translations.seo_title`, `noindex` and `canonical_url`.
 */
final class Version20261116230000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the seo_title, noindex and canonical_url columns to page_translations.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('page_translations');
        $table->addColumn('seo_title', Types::STRING, ['length' => 200, 'notnull' => false]);
        $table->addColumn('noindex', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('canonical_url', Types::STRING, ['length' => 2000, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('page_translations');
        foreach (['seo_title', 'noindex', 'canonical_url'] as $column) {
            $table->dropColumn($column);
        }
    }
}
