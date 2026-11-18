<?php

declare(strict_types=1);

namespace SightPress\Core\User\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Admin preferences on *My account* (U-3): less motion and larger text in the admin panel, both off by default.
 */
final class Version20261118500000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the admin_reduced_motion and admin_larger_text columns to users.';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('admin_reduced_motion', Types::BOOLEAN, ['default' => false]);
        $users->addColumn('admin_larger_text', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropColumn('admin_reduced_motion');
        $users->dropColumn('admin_larger_text');
    }
}
