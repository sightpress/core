<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Text messages (N-2, ADR-0069), with the Schema API only: `sms_message`, one row per message (when, the month in UTC
 * as `YYYY-MM`, why, the number masked and as a keyed hash, whose account, the provider and its message id, the parts
 * charged, the status and failure, and the text only while a notification waits in the outbox); `sms_opt_out`, the
 * keyed hashes of numbers that replied STOP; `sms_webhook_report`, digests of webhook requests already handled.
 */
final class Version20261125100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the sms_message, sms_opt_out and sms_webhook_report tables.';
    }

    public function up(Schema $schema): void
    {
        $message = $schema->createTable('sms_message');
        $message->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $message->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $message->addColumn('month', Types::STRING, ['length' => 7]);
        $message->addColumn('purpose', Types::STRING, ['length' => 32]);
        $message->addColumn('to_masked', Types::STRING, ['length' => 32]);
        $message->addColumn('to_hash', Types::STRING, ['length' => 64]);
        $message->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $message->addColumn('provider', Types::STRING, ['length' => 100]);
        $message->addColumn('provider_message_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $message->addColumn('parts', Types::INTEGER);
        $message->addColumn('status', Types::STRING, ['length' => 20]);
        $message->addColumn('failure', Types::STRING, ['length' => 32, 'notnull' => false]);
        $message->addColumn('body', Types::TEXT, ['notnull' => false]);
        $message->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $message->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $message->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $message->addIndex(['month'], 'sms_message_month_idx');
        $message->addIndex(['status'], 'sms_message_status_idx');
        $message->addIndex(['provider', 'provider_message_id'], 'sms_message_provider_idx');

        $optOut = $schema->createTable('sms_opt_out');
        $optOut->addColumn('number_hash', Types::STRING, ['length' => 64]);
        $optOut->addColumn('opted_out_at', Types::DATETIME_IMMUTABLE);
        $optOut->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('number_hash')->create());

        $report = $schema->createTable('sms_webhook_report');
        $report->addColumn('digest', Types::STRING, ['length' => 64]);
        $report->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $report->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('digest')->create());
        $report->addIndex(['received_at'], 'sms_webhook_report_received_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('sms_webhook_report');
        $schema->dropTable('sms_opt_out');
        $schema->dropTable('sms_message');
    }
}
