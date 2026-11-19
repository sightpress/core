<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The `site_module` table: the site's own choice for each optional module it turned on or off, with when and by
 * whom (ADR-0034).
 */
final class Version20261118600000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the site_module table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('site_module');
        $table->addColumn('module_id', Types::STRING, ['length' => 64]);
        $table->addColumn('enabled', Types::BOOLEAN);
        $table->addColumn('changed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('changed_by', Types::INTEGER, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('module_id')->create());
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('site_module');
    }
}
