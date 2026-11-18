<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Menu entries get their text in each of the site's languages (`labels`, locale => text) and can be for signed-in
 * members only (`members_only`) (MN-1).
 */
final class Version20261118400000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the labels and members_only columns to menu_items.';
    }

    public function up(Schema $schema): void
    {
        $items = $schema->getTable('menu_items');
        $items->addColumn('labels', Types::JSON, ['notnull' => false]);
        $items->addColumn('members_only', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $items = $schema->getTable('menu_items');
        $items->dropColumn('labels');
        $items->dropColumn('members_only');
    }
}
