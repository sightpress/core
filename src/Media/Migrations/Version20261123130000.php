<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * File sizes above 2 GB (M-7): a whole number of 32 bits can't hold them on MySQL, MariaDB or PostgreSQL.
 */
final class Version20261123130000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Allow media files larger than 2 GB.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('media')->getColumn('size')->setType(Type::getType(Types::BIGINT));
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('media')->getColumn('size')->setType(Type::getType(Types::INTEGER));
    }
}
