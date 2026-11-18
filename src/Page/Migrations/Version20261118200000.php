<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Redirects (P-1): the `page_redirects` table keeps the old addresses of pages that moved (301) and of pages deleted
 * for good (410), per locale.
 */
final class Version20261118200000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the page_redirects table.';
    }

    public function up(Schema $schema): void
    {
        $redirects = $schema->createTable('page_redirects');
        $redirects->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $redirects->addColumn('locale', Types::STRING, ['length' => 35]);
        $redirects->addColumn('source_path', Types::STRING, ['length' => 620]);
        $redirects->addColumn('target_path', Types::STRING, ['length' => 620, 'notnull' => false]);
        $redirects->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $redirects->addPrimaryKeyConstraint(self::primaryKey('id'));
        $redirects->addUniqueIndex(['locale', 'source_path'], 'page_redirects_locale_source_unique');
        $redirects->addIndex(['locale', 'target_path'], 'page_redirects_locale_target_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('page_redirects');
    }

    /**
     * @param non-empty-string $column
     */
    private static function primaryKey(string $column): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setUnquotedColumnNames($column)->create();
    }
}
