<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The `media_upload` table.
 */
final readonly class UploadSessions
{
    public const string TABLE = 'media_upload';

    private const array DATES = ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE, 'completed_at' => Types::DATETIME_IMMUTABLE];

    public function __construct(private Connection $connection)
    {
    }

    public function add(UploadSession $session): void
    {
        $this->connection->insert(self::TABLE, [
            'id' => $session->id,
            'user_id' => $session->userId,
            'file_name' => $session->fileName,
            'total_bytes' => $session->totalBytes,
            'received_bytes' => $session->receivedBytes,
            'created_at' => $session->createdAt,
            'updated_at' => $session->updatedAt,
            'completed_at' => $session->completedAt,
            'media_id' => $session->mediaId,
        ], self::DATES);
    }

    public function find(string $id): ?UploadSession
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return $row === false ? null : self::session($row);
    }

    public function received(string $id, int $bytes, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, ['received_bytes' => $bytes, 'updated_at' => $now], ['id' => $id], ['updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function complete(string $id, int $mediaId, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, ['completed_at' => $now, 'updated_at' => $now, 'media_id' => $mediaId], ['id' => $id], self::DATES);
    }

    public function delete(string $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * @return array{sessions: int, bytes: int} what the person's unfinished uploads have announced
     */
    public function activeFor(int $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS sessions, COALESCE(SUM(total_bytes), 0) AS bytes FROM ' . self::TABLE . ' WHERE user_id = ? AND completed_at IS NULL',
            [$userId],
        );

        return ['sessions' => is_array($row) && is_numeric($row['sessions']) ? (int) $row['sessions'] : 0, 'bytes' => is_array($row) && is_numeric($row['bytes']) ? (int) $row['bytes'] : 0];
    }

    /**
     * Bytes still to arrive for every unfinished upload of the site.
     */
    public function outstandingBytes(): int
    {
        $bytes = $this->connection->fetchOne('SELECT COALESCE(SUM(total_bytes - received_bytes), 0) FROM ' . self::TABLE . ' WHERE completed_at IS NULL');

        return is_numeric($bytes) ? (int) $bytes : 0;
    }

    /**
     * @return list<UploadSession> newest first
     */
    public function all(): array
    {
        return array_map(self::session(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' ORDER BY updated_at DESC, id'));
    }

    /**
     * @return list<UploadSession> unfinished and idle since `$idleBefore`, or finished before `$finishedBefore`
     */
    public function expired(DateTimeImmutable $idleBefore, DateTimeImmutable $finishedBefore): array
    {
        $format = $this->connection->getDatabasePlatform()->getDateTimeFormatString();

        return array_map(self::session(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE (completed_at IS NULL AND updated_at < ?) OR completed_at < ?',
            [$idleBefore->format($format), $finishedBefore->format($format)],
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function session(array $row): UploadSession
    {
        return new UploadSession(
            self::text($row['id']),
            self::number($row['user_id']),
            self::text($row['file_name']),
            self::number($row['total_bytes']),
            self::number($row['received_bytes']),
            self::date($row['created_at']),
            self::date($row['updated_at']),
            $row['completed_at'] === null ? null : self::date($row['completed_at']),
            $row['media_id'] === null ? null : self::number($row['media_id']),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable(self::text($value), new DateTimeZone('UTC'));
    }
}
