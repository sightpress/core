<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Migration\PortableMigration;

/**
 * Analytics (AN-1, ADR-0012), with the Schema API only:
 *
 * - `analytics_hit`: what was counted on days that aren't rolled up yet (today), one row each, with the day in the
 *   site's time zone; never an IP address, a user agent or a query string;
 * - `analytics_daily`: the figures of finished days (day, metric, dimension, item, amount). One row per day and
 *   figure, kept so by the roll-up (delete and insert in one transaction), not by a unique key: on MySQL's default
 *   collation two different paths or queries (`/About`, `/about`) could compare equal;
 * - `analytics_salt`: the random salt of each day not rolled up yet, deleted with the day's raw rows.
 */
final class Version20261119200000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Create the analytics_hit, analytics_daily and analytics_salt tables.';
    }

    public function up(Schema $schema): void
    {
        $hits = $schema->createTable('analytics_hit');
        $hits->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $hits->addColumn('day_on', Types::STRING, ['length' => 10]);
        $hits->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $hits->addColumn('kind', Types::STRING, ['length' => 16]);
        $hits->addColumn('resource_kind', Types::STRING, ['length' => 32, 'notnull' => false]);
        $hits->addColumn('page_id', Types::INTEGER, ['notnull' => false]);
        $hits->addColumn('locale', Types::STRING, ['length' => 35, 'notnull' => false]);
        $hits->addColumn('path', Types::STRING, ['length' => 300, 'notnull' => false]);
        $hits->addColumn('visitor_key', Types::STRING, ['length' => 32, 'notnull' => false]);
        $hits->addColumn('source', Types::STRING, ['length' => 300, 'notnull' => false]);
        $hits->addColumn('device', Types::STRING, ['length' => 16, 'notnull' => false]);
        $hits->addColumn('bot', Types::STRING, ['length' => 64, 'notnull' => false]);
        $hits->addColumn('bot_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $hits->addColumn('search_query', Types::STRING, ['length' => 100, 'notnull' => false]);
        $hits->addColumn('result_count', Types::INTEGER, ['notnull' => false]);
        $hits->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $hits->addIndex(['day_on'], 'analytics_hit_day_idx');

        $daily = $schema->createTable('analytics_daily');
        $daily->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $daily->addColumn('day_on', Types::STRING, ['length' => 10]);
        $daily->addColumn('metric', Types::STRING, ['length' => 32]);
        $daily->addColumn('dimension', Types::STRING, ['length' => 32]);
        $daily->addColumn('item', Types::STRING, ['length' => 400]);
        $daily->addColumn('amount', Types::INTEGER);
        $daily->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $daily->addIndex(['metric', 'dimension', 'day_on'], 'analytics_daily_metric_idx');
        $daily->addIndex(['day_on'], 'analytics_daily_day_idx');

        $salts = $schema->createTable('analytics_salt');
        $salts->addColumn('day_on', Types::STRING, ['length' => 10]);
        $salts->addColumn('salt', Types::STRING, ['length' => 64]);
        $salts->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('day_on')->create());
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('analytics_hit');
        $schema->dropTable('analytics_daily');
        $schema->dropTable('analytics_salt');
    }
}
