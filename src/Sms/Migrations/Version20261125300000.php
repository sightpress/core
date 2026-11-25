<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The text message outbox (N-4, ADR-0071): a notification waits in `sms_message` with its number (`to_e164`) and text
 * until `schedule:run` sends it, then both are cleared; `kind` says which notification it is.
 */
final class Version20261125300000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the to_e164 and kind columns to sms_message.';
    }

    public function up(Schema $schema): void
    {
        $message = $schema->getTable('sms_message');
        $message->addColumn('to_e164', Types::STRING, ['length' => 16, 'notnull' => false]);
        $message->addColumn('kind', Types::STRING, ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $message = $schema->getTable('sms_message');
        $message->dropColumn('to_e164');
        $message->dropColumn('kind');
    }
}
