<?php

declare(strict_types=1);

namespace SightPress\Core\Audit\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

final class Version20261117120000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the audit_log table.';
    }

    public function up(Schema $schema): void
    {
        $log = $schema->createTable('audit_log');
        $log->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $log->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $log->addColumn('actor_id', Types::INTEGER, ['notnull' => false]);
        $log->addColumn('event_action', Types::STRING, ['length' => 60]);
        $log->addColumn('entity_type', Types::STRING, ['length' => 30]);
        $log->addColumn('entity_id', Types::STRING, ['length' => 60, 'notnull' => false]);
        $log->addColumn('summary', Types::STRING, ['length' => 500]);
        $log->addColumn('details', Types::TEXT, ['notnull' => false]);
        $log->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $log->addIndex(['occurred_at'], 'audit_log_occurred_idx');
        $log->addIndex(['entity_type', 'entity_id'], 'audit_log_entity_idx');
        $log->addIndex(['actor_id'], 'audit_log_actor_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_log');
    }
}
