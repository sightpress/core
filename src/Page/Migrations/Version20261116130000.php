<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `pages`, `page_translations` and `page_revisions` tables (C-4.1).
 */
final class Version20261116130000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the pages, page_translations and page_revisions tables.';
    }

    public function up(Schema $schema): void
    {
        $pages = $schema->createTable('pages');
        $pages->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $pages->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $pages->addColumn('sort_order', Types::INTEGER);
        $pages->addColumn('tree_path', Types::STRING, ['length' => 255]);
        $pages->addColumn('depth', Types::INTEGER);
        $pages->addColumn('author_id', Types::INTEGER, ['notnull' => false]);
        $pages->addColumn('state', Types::STRING, ['length' => 20]);
        $pages->addColumn('is_public', Types::BOOLEAN);
        $pages->addColumn('layout', Types::STRING, ['length' => 50]);
        $pages->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $pages->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $pages->addPrimaryKeyConstraint(self::primaryKey('id'));
        $pages->addIndex(['parent_id', 'sort_order'], 'pages_parent_order_idx');
        $pages->addIndex(['tree_path'], 'pages_tree_path_idx');
        $pages->addIndex(['state'], 'pages_state_idx');
        $pages->addForeignKeyConstraint('pages', ['parent_id'], ['id'], [], 'pages_parent_fk');

        $translations = $schema->createTable('page_translations');
        $translations->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $translations->addColumn('page_id', Types::INTEGER);
        $translations->addColumn('locale', Types::STRING, ['length' => 35]);
        $translations->addColumn('title', Types::STRING, ['length' => 200]);
        $translations->addColumn('slug', Types::STRING, ['length' => 60]);
        $translations->addColumn('url_path', Types::STRING, ['length' => 620]);
        $translations->addColumn('summary', Types::STRING, ['length' => 300, 'notnull' => false]);
        $translations->addColumn('description', Types::STRING, ['length' => 300, 'notnull' => false]);
        $translations->addColumn('blocks', Types::JSON);
        $translations->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $translations->addPrimaryKeyConstraint(self::primaryKey('id'));
        $translations->addUniqueIndex(['page_id', 'locale'], 'page_translations_page_locale_unique');
        $translations->addUniqueIndex(['locale', 'url_path'], 'page_translations_locale_path_unique');
        $translations->addForeignKeyConstraint('pages', ['page_id'], ['id'], ['onDelete' => 'CASCADE'], 'page_translations_page_fk');

        $revisions = $schema->createTable('page_revisions');
        $revisions->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $revisions->addColumn('page_id', Types::INTEGER);
        $revisions->addColumn('revision', Types::INTEGER);
        $revisions->addColumn('saved_by', Types::INTEGER, ['notnull' => false]);
        $revisions->addColumn('snapshot', Types::JSON);
        $revisions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $revisions->addPrimaryKeyConstraint(self::primaryKey('id'));
        $revisions->addUniqueIndex(['page_id', 'revision'], 'page_revisions_page_revision_unique');
        $revisions->addForeignKeyConstraint('pages', ['page_id'], ['id'], ['onDelete' => 'CASCADE'], 'page_revisions_page_fk');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('page_revisions');
        $schema->dropTable('page_translations');
        $schema->dropTable('pages');
    }

    /**
     * @param non-empty-string $column
     */
    private static function primaryKey(string $column): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setUnquotedColumnNames($column)->create();
    }
}
