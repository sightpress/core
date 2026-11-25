<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Numbers that replied STOP (N-2), as keyed hashes in the `sms_opt_out` table, so the site sends them no more
 * notifications until they reply START. Security codes still go (gap 10): a person who asked for a code is waiting
 * for it.
 */
final readonly class SmsOptOuts
{
    public const string TABLE = 'sms_opt_out';

    public function __construct(private Connection $connection)
    {
    }

    public function stop(string $numberHash, DateTimeImmutable $at): void
    {
        if (!$this->stopped($numberHash)) {
            $this->connection->insert(self::TABLE, ['number_hash' => $numberHash, 'opted_out_at' => $at], ['opted_out_at' => Types::DATETIME_IMMUTABLE]);
        }
    }

    public function start(string $numberHash): void
    {
        $this->connection->delete(self::TABLE, ['number_hash' => $numberHash]);
    }

    public function stopped(string $numberHash): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('number_hash')
            ->from(self::TABLE)
            ->where('number_hash = :hash')
            ->setParameter('hash', $numberHash)
            ->fetchOne() !== false;
    }
}
