<?php

declare(strict_types=1);

namespace SightPress\Core\People\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Invitations no longer have link tokens (C-3.7): the person types an invitation code instead, which lives in
 * `verification_code`. Drops `invitations.token_hash`. Invitations that were still open have no code, so they can't
 * be accepted: invite those people again.
 */
final class Version20261117100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Drop invitations.token_hash: invitations use codes, not links.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('invitations');
        $table->dropIndex('invitations_token');
        $table->dropColumn('token_hash');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('invitations');
        $table->addColumn('token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addUniqueIndex(['token_hash'], 'invitations_token');
    }
}
