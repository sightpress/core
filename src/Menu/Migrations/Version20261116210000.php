<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `menu_items` table (C-6.3).
 */
final class Version20261116210000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the menu_items table.';
    }

    public function up(Schema $schema): void
    {
        $items = $schema->createTable('menu_items');
        $items->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $items->addColumn('menu', Types::STRING, ['length' => 20]);
        $items->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $items->addColumn('sort_order', Types::INTEGER);
        $items->addColumn('page_id', Types::INTEGER, ['notnull' => false]);
        $items->addColumn('label', Types::STRING, ['length' => 100, 'notnull' => false]);
        $items->addColumn('url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $items->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $items->addIndex(['menu', 'parent_id', 'sort_order'], 'menu_items_menu_order_idx');
        $items->addForeignKeyConstraint('menu_items', ['parent_id'], ['id'], ['onDelete' => 'CASCADE'], 'menu_items_parent_fk');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('menu_items');
    }
}
