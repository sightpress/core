<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20990101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('canary_rsvp');
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('user_id', Types::INTEGER);
        $table->addColumn('seats', Types::INTEGER);
    }
}
