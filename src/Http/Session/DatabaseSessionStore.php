<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Psr\Clock\ClockInterface;

/**
 * Keeps sessions in the site's own database (the `session` table), so every web server of the site shares them. It
 * is the default store (`session.store` = `database`).
 *
 * Rows are found by a SHA-256 hash of the session id, so a raw id is never stored. A row ends {@see $idleTimeout}
 * seconds after its last request (`expires_at`); an expired row is refused at once and deleted by `schedule:run`
 * ({@see forgetExpired()}). `user_id` holds the signed-in account, so a person's sessions can be found
 * ({@see PersonSessions}). To spare the database a write on every request, a request that changed nothing only
 * moves the expiry on once `last_seen_at` is a minute old ({@see TOUCH_AFTER}).
 */
final readonly class DatabaseSessionStore implements ExpiringSessionStore, PersonSessions
{
    public const string TABLE = 'session';

    /** Seconds a session's last request may be out of date before {@see touch()} writes it again. */
    public const int TOUCH_AFTER = 60;

    /**
     * @param positive-int $idleTimeout seconds without a request before a session ends
     */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private int $idleTimeout,
    ) {
    }

    public function read(string $id): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('data')
            ->from(self::TABLE)
            ->where('id_hash = :id')
            ->andWhere('expires_at > :now')
            ->setParameter('id', self::hash($id))
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->fetchOne();
        if (!is_string($row)) {
            return null;
        }

        try {
            $data = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function write(string $id, array $data): void
    {
        $now = $this->clock->now();
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if ($this->update($id, $json, self::userId($data), $now) > 0) {
            return;
        }

        try {
            $this->connection->insert(self::TABLE, [
                'id_hash' => self::hash($id),
                'user_id' => self::userId($data),
                'data' => $json,
                'last_seen_at' => $now,
                'expires_at' => $this->expiry($now),
            ], [
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The row is there: MySQL counts a row whose values didn't change as not updated, or another request
            // of the visitor made it meanwhile.
            $this->update($id, $json, self::userId($data), $now);
        }
    }

    public function touch(string $id): void
    {
        $now = $this->clock->now();
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('last_seen_at', ':now')
            ->set('expires_at', ':expires')
            ->where('id_hash = :id')
            ->andWhere('expires_at > :now')
            ->andWhere('last_seen_at < :stale')
            ->setParameter('id', self::hash($id))
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('expires', $this->expiry($now), Types::DATETIME_IMMUTABLE)
            ->setParameter('stale', $now->modify('-' . self::TOUCH_AFTER . ' seconds'), Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }

    public function delete(string $id): void
    {
        $this->connection->delete(self::TABLE, ['id_hash' => self::hash($id)]);
    }

    public function forgetExpired(DateTimeImmutable $now): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('expires_at <= :now')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }

    public function forPerson(int $userId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('last_seen_at', 'expires_at')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->andWhere('expires_at > :now')
            ->orderBy('last_seen_at')
            ->setParameter('user', $userId)
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->fetchAllAssociative();

        return array_values(array_map(fn (array $row): array => [
            'last_seen_at' => $this->iso($row['last_seen_at'] ?? null),
            'expires_at' => $this->iso($row['expires_at'] ?? null),
        ], $rows));
    }

    public function forgetPerson(int $userId): int
    {
        return (int) $this->connection->delete(self::TABLE, ['user_id' => $userId]);
    }

    /**
     * How a session id is kept: its SHA-256 hash, so someone reading the table can't use the ids.
     */
    public static function hash(string $id): string
    {
        return hash('sha256', $id);
    }

    private function update(string $id, string $json, ?int $userId, DateTimeImmutable $now): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('data', ':data')
            ->set('user_id', ':user')
            ->set('last_seen_at', ':now')
            ->set('expires_at', ':expires')
            ->where('id_hash = :id')
            ->setParameter('id', self::hash($id))
            ->setParameter('data', $json)
            ->setParameter('user', $userId, $userId === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::INTEGER)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('expires', $this->expiry($now), Types::DATETIME_IMMUTABLE)
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function userId(array $data): ?int
    {
        $user = $data[Session::USER_ID] ?? null;

        return is_int($user) ? $user : null;
    }

    private function iso(mixed $value): ?string
    {
        $date = $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);

        return $date instanceof DateTimeImmutable ? $date->format(DATE_ATOM) : null;
    }

    private function expiry(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+' . $this->idleTimeout . ' seconds');
    }
}
