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
            'mode' => $session->mode,
            'state' => $session->state,
            'remote_id' => $session->remoteId,
            'part_bytes' => $session->partBytes,
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
        $this->connection->update(self::TABLE, ['completed_at' => $now, 'updated_at' => $now, 'media_id' => $mediaId, 'state' => UploadSession::RECEIVING], ['id' => $id], self::DATES);
    }

    /**
     * Moves a session from one state to another. False when it wasn't in `$from`, so only one process wins.
     */
    public function transition(string $id, string $from, string $to, DateTimeImmutable $now): bool
    {
        return (int) $this->connection->update(self::TABLE, ['state' => $to, 'updated_at' => $now], ['id' => $id, 'state' => $from], ['updated_at' => Types::DATETIME_IMMUTABLE]) === 1;
    }

    /**
     * @param array<mixed> $reason
     */
    public function refuse(string $id, array $reason, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, ['state' => UploadSession::REFUSED, 'refusal' => $reason, 'updated_at' => $now], ['id' => $id], ['updated_at' => Types::DATETIME_IMMUTABLE, 'refusal' => Types::JSON]);
    }

    /**
     * Direct uploads waiting to be checked, and those whose check stopped before `$staleBefore`.
     *
     * @return list<UploadSession>
     */
    public function awaitingValidation(DateTimeImmutable $staleBefore): array
    {
        $format = $this->connection->getDatabasePlatform()->getDateTimeFormatString();

        return array_map(self::session(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE mode = ? AND (state = ? OR (state = ? AND updated_at < ?)) ORDER BY updated_at',
            [UploadSession::DIRECT, UploadSession::VALIDATING, UploadSession::PROCESSING, $staleBefore->format($format)],
        ));
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
     * @return list<UploadSession> every upload session of one person, oldest first
     */
    public function ofUser(int $userId): array
    {
        return array_map(self::session(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY created_at, id', [$userId]));
    }

    /**
     * Bytes still to arrive for every unfinished upload of the site.
     */
    public function outstandingBytes(): int
    {
        $bytes = $this->connection->fetchOne('SELECT COALESCE(SUM(total_bytes - received_bytes), 0) FROM ' . self::TABLE . ' WHERE completed_at IS NULL AND mode = ?', [UploadSession::TEMP]);

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
    public function expired(DateTimeImmutable $idleBefore, DateTimeImmutable $finishedBefore, string $mode = UploadSession::TEMP): array
    {
        $format = $this->connection->getDatabasePlatform()->getDateTimeFormatString();

        return array_map(self::session(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE mode = ? AND ((completed_at IS NULL AND updated_at < ?) OR completed_at < ?)',
            [$mode, $idleBefore->format($format), $finishedBefore->format($format)],
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
            self::text($row['mode']),
            self::text($row['state']),
            $row['remote_id'] === null ? null : self::text($row['remote_id']),
            $row['part_bytes'] === null ? null : self::number($row['part_bytes']),
            self::refusal($row['refusal']),
        );
    }

    /**
     * @return array<mixed>|null
     */
    private static function refusal(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
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
