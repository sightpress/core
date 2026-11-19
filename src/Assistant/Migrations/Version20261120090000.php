<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * The AI assistant (A-2, ADR-0040), with the Schema API only: `assistant_usage`, one row per use (when, the month in
 * UTC as `YYYY-MM`, who, which task, provider and model, the tokens and the outcome), for the monthly budget and
 * `site:assistant:usage`. Never the prompt, the answer, an IP address or a user agent.
 */
final class Version20261120090000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the assistant_usage table.';
    }

    public function up(Schema $schema): void
    {
        $usage = $schema->createTable('assistant_usage');
        $usage->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $usage->addColumn('used_at', Types::DATETIME_IMMUTABLE);
        $usage->addColumn('month', Types::STRING, ['length' => 7]);
        $usage->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $usage->addColumn('task', Types::STRING, ['length' => 100]);
        $usage->addColumn('provider', Types::STRING, ['length' => 100]);
        $usage->addColumn('model', Types::STRING, ['length' => 200]);
        $usage->addColumn('input_tokens', Types::INTEGER);
        $usage->addColumn('output_tokens', Types::INTEGER);
        $usage->addColumn('outcome', Types::STRING, ['length' => 32]);
        $usage->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $usage->addIndex(['month'], 'assistant_usage_month_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('assistant_usage');
    }
}
