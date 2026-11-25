<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * Webhook requests the site has already handled (N-2), by a digest of the provider, signature and body, so a replayed
 * request changes nothing. Kept for {@see KEEP_DAYS}.
 */
final readonly class SmsReports
{
    public const string TABLE = 'sms_webhook_report';

    public const int KEEP_DAYS = 30;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Records a request; false when it was already recorded (a replay).
     */
    public function first(string $digest, DateTimeImmutable $at): bool
    {
        try {
            $this->connection->insert(self::TABLE, ['digest' => $digest, 'received_at' => $at], ['received_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function forgetBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('received_at < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }
}
