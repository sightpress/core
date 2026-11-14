<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `site_settings` table: one row per stored setting of the site.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the site_settings table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('site_settings');
        $table->addColumn('setting_key', Types::STRING, ['length' => 150]);
        $table->addColumn('value', Types::TEXT);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('setting_key')->create());
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('site_settings');
    }
}
