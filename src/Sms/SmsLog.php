<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;

/**
 * The `sms_message` table (N-2, ADR-0069), through the DBAL QueryBuilder only: one row per message the site sent or
 * tried to send, with the number masked (and a keyed hash of it for the limits and STOP replies, never the number),
 * the status, the parts the provider charged and the provider's id. A notification waiting in the outbox keeps its
 * text until it is delivered (N-4); a security code's text is never stored.
 */
final readonly class SmsLog
{
    public const string TABLE = 'sms_message';

    public const string QUEUED = 'queued';
    public const string SENDING = 'sending';
    public const string SENT = 'sent';
    public const string DELIVERED = 'delivered';
    /** The provider refused it, or gave up delivering it. */
    public const string FAILED = 'failed';

    /** Days the log keeps a message (the Privacy Policy says so). */
    public const int KEEP_DAYS = 90;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Writes a row for a message about to be sent (or, with `$queued`, waiting in the outbox with its number and text
     * until it is sent), and returns its id.
     */
    public function start(SmsMessage $message, string $numberHash, string $provider, ?int $userId, DateTimeImmutable $at, bool $queued = false, ?string $kind = null): int
    {
        $this->connection->insert(self::TABLE, [
            'created_at' => $at,
            'month' => $at->format('Y-m'),
            'purpose' => $message->purpose->value,
            'to_masked' => $message->to->masked(),
            'to_hash' => $numberHash,
            'user_id' => $userId,
            'provider' => $provider,
            'parts' => $message->parts(),
            'status' => $queued ? self::QUEUED : self::SENDING,
            'body' => $queued ? $message->text : null,
            'to_e164' => $queued ? $message->to->e164 : null,
            'kind' => $kind,
            'updated_at' => $at,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection->lastInsertId();
    }

    public function sent(int $id, string $messageId, int $parts, DateTimeImmutable $at): void
    {
        $this->connection->update(self::TABLE, [
            'status' => self::SENT,
            'provider_message_id' => mb_substr($messageId, 0, 191),
            'parts' => $parts,
            'sent_at' => $at,
            'body' => null,
            'to_e164' => null,
        ], ['id' => $id], ['sent_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function failed(int $id, string $failure, DateTimeImmutable $at): void
    {
        $this->connection->update(self::TABLE, ['status' => self::FAILED, 'failure' => mb_substr($failure, 0, 32), 'updated_at' => $at, 'body' => null, 'to_e164' => null], ['id' => $id], ['updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Forgets messages made before this time that are no longer waiting or being sent.
     *
     * @return int how many
     */
    public function forgetBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('created_at < :before')
            ->andWhere('status NOT IN (:waiting)')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->setParameter('waiting', [self::QUEUED, self::SENDING], \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * The notifications waiting in the outbox, oldest first.
     *
     * @return list<array{id: int, to: string, text: string, purpose: string, user_id: int|null}>
     */
    public function queued(int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'to_e164', 'body', 'purpose', 'user_id')
            ->from(self::TABLE)
            ->where('status = :queued')
            ->setParameter('queued', self::QUEUED)
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->fetchAllAssociative();
        $queued = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id']) && is_string($row['to_e164']) && is_string($row['body']) && is_string($row['purpose'])) {
                $queued[] = ['id' => (int) $row['id'], 'to' => $row['to_e164'], 'text' => $row['body'], 'purpose' => $row['purpose'], 'user_id' => is_numeric($row['user_id']) ? (int) $row['user_id'] : null];
            }
        }

        return $queued;
    }

    /**
     * Takes a queued message for sending: true for exactly one caller, so a message is never sent twice, even when
     * two runs overlap. A message that was taken and then never finished (the run crashed) stays `sending`, and is
     * marked failed by {@see failInterrupted()}, never sent again.
     */
    public function claim(int $id, DateTimeImmutable $at): bool
    {
        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':sending')
            ->set('updated_at', ':at')
            ->where('id = :id')
            ->andWhere('status = :queued')
            ->setParameter('sending', self::SENDING)
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->setParameter('queued', self::QUEUED)
            ->executeStatement() === 1;
    }

    /**
     * Marks messages left `sending` since before this time as failed (`interrupted`), and forgets their number and text.
     *
     * @return int how many
     */
    public function failInterrupted(DateTimeImmutable $before, DateTimeImmutable $at): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':failed')
            ->set('failure', ':interrupted')
            ->set('body', ':none')
            ->set('to_e164', ':none')
            ->set('updated_at', ':at')
            ->where('status = :sending')
            ->andWhere('updated_at < :before')
            ->setParameter('failed', self::FAILED)
            ->setParameter('interrupted', 'interrupted')
            ->setParameter('none', null)
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('sending', self::SENDING)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }

    /**
     * A delivery report from the provider: delivered, or failed for good. The text, if any was kept, goes.
     *
     * @return bool false when no message has that provider id
     */
    public function receipt(string $provider, string $messageId, SmsDeliveryStatus $status, ?string $reason, DateTimeImmutable $at): bool
    {
        return $this->connection->update(self::TABLE, [
            'status' => $status === SmsDeliveryStatus::Delivered ? self::DELIVERED : self::FAILED,
            'failure' => $status === SmsDeliveryStatus::Delivered ? null : mb_substr($reason ?? 'undelivered', 0, 32),
            'updated_at' => $at,
            'body' => null,
        ], ['provider' => $provider, 'provider_message_id' => $messageId], ['updated_at' => Types::DATETIME_IMMUTABLE]) > 0;
    }

    /**
     * Message parts the provider accepted in a month (`YYYY-MM`, UTC), which is what it charges for.
     *
     * @return int<0, max>
     */
    public function partsIn(string $month): int
    {
        $total = $this->connection->createQueryBuilder()
            ->select('SUM(parts)')
            ->from(self::TABLE)
            ->where('month = :month')
            ->andWhere('provider_message_id IS NOT NULL')
            ->setParameter('month', $month)
            ->fetchOne();

        return max(0, is_numeric($total) ? (int) $total : 0);
    }

    /**
     * The latest messages, newest first, without their text.
     *
     * @return list<array{id: int, created_at: string, purpose: string, to: string, user_id: int|null, status: string, failure: string|null, parts: int}>
     */
    public function recent(int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'created_at', 'purpose', 'to_masked', 'user_id', 'status', 'failure', 'parts')
            ->from(self::TABLE)
            ->orderBy('id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->fetchAllAssociative();

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) (is_numeric($row['id']) ? $row['id'] : 0),
            'created_at' => is_string($row['created_at']) ? $row['created_at'] : '',
            'purpose' => is_string($row['purpose']) ? $row['purpose'] : SmsPurpose::Notification->value,
            'to' => is_string($row['to_masked']) ? $row['to_masked'] : '',
            'user_id' => is_numeric($row['user_id']) ? (int) $row['user_id'] : null,
            'status' => is_string($row['status']) ? $row['status'] : '',
            'failure' => is_string($row['failure']) ? $row['failure'] : null,
            'parts' => (int) (is_numeric($row['parts']) ? $row['parts'] : 1),
        ], $rows));
    }
}
