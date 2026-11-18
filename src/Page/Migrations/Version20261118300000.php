<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Scheduled publishing (P-3): `pages.publish_at` and `pages.unpublish_at` (UTC) say when `schedule:run` publishes an
 * approved page and takes a published one off the site; `scheduled_by` is the person whose permissions it uses.
 */
final class Version20261118300000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the publish_at, unpublish_at and scheduled_by columns to pages.';
    }

    public function up(Schema $schema): void
    {
        $pages = $schema->getTable('pages');
        $pages->addColumn('publish_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $pages->addColumn('unpublish_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $pages->addColumn('scheduled_by', Types::INTEGER, ['notnull' => false]);
        $pages->addIndex(['state', 'publish_at'], 'pages_state_publish_at_idx');
        $pages->addIndex(['state', 'unpublish_at'], 'pages_state_unpublish_at_idx');
    }

    public function down(Schema $schema): void
    {
        $pages = $schema->getTable('pages');
        $pages->dropIndex('pages_state_publish_at_idx');
        $pages->dropIndex('pages_state_unpublish_at_idx');
        $pages->dropColumn('publish_at');
        $pages->dropColumn('unpublish_at');
        $pages->dropColumn('scheduled_by');
    }
}
