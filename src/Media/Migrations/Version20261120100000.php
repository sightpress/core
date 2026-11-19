<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Alt text the AI assistant suggested (A-4): `media.alt_suggested` lists the locales whose alt text came from the
 * assistant and no person has checked yet; pages that use it can't be published until someone does.
 */
final class Version20261120100000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the alt_suggested column to media.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('media')->addColumn('alt_suggested', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('media')->dropColumn('alt_suggested');
    }
}
