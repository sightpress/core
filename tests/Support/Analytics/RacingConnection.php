<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Analytics;

use Doctrine\DBAL\Connection;

/**
 * A connection where another request stores a row just before this one inserts it: the next insert into
 * `analytics_salt` first writes {@see $competitor}, so the insert then breaks the unique key.
 */
final class RacingConnection extends Connection
{
    /** @var array<string, string>|null */
    public ?array $competitor = null;

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'analytics_salt' && $this->competitor !== null) {
            $competitor = $this->competitor;
            $this->competitor = null;
            parent::insert($table, $competitor);
        }

        return parent::insert($table, $data, $types);
    }
}
