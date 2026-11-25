<?php

declare(strict_types=1);

namespace SightPress\Core\User\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Phone numbers on accounts (N-3, ADR-0070): `phone_e164` (only once its code was typed, unique on the site; NULL for
 * everyone without one, which every engine allows many times in a unique index), when it was confirmed, what the
 * provider's lookup said (line type and country), and the text message choices: when the person agreed, when they
 * stopped (on the site or by replying STOP), and which notifications go by text.
 */
final class Version20261125200000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the phone number and text message choices to users.';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('phone_e164', Types::STRING, ['length' => 16, 'notnull' => false]);
        $users->addColumn('phone_verified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('phone_lookup', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('sms_consent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('sms_opt_out_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('sms_notifications', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addUniqueIndex(['phone_e164'], 'users_phone_e164_unique');
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropIndex('users_phone_e164_unique');
        foreach (['phone_e164', 'phone_verified_at', 'phone_lookup', 'sms_consent_at', 'sms_opt_out_at', 'sms_notifications'] as $column) {
            $users->dropColumn($column);
        }
    }
}
