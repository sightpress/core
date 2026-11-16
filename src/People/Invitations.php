<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * The site's invitations. A link token is 32 random bytes; only its SHA-256 is stored. A link works once, for
 * {@see TTL} seconds; inviting the same address again replaces the invitation that is still open, so the older link
 * stops working.
 */
final readonly class Invitations
{
    /** How long an invitation works: 7 days. */
    public const int TTL = 604800;

    private const string TABLE = 'invitations';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{Invitation, string} the invitation and the token for its link
     */
    public function create(string $email, string $role, ?string $firstName, ?string $lastName, ?int $invitedBy): array
    {
        $email = self::normalizeEmail($email);
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $row = [
            'token_hash' => hash('sha256', $token),
            'email' => $email,
            'role' => $role,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'invited_by' => $invitedBy,
            'created_at' => $now,
            'expires_at' => $now->modify(sprintf('+%d seconds', self::TTL)),
        ];

        $id = $this->connection->transactional(function (Connection $connection) use ($email, $row): int {
            $connection->createQueryBuilder()
                ->delete(self::TABLE)
                ->where('email = :email')
                ->andWhere('accepted_at IS NULL')
                ->setParameter('email', $email)
                ->executeStatement();
            $connection->insert(self::TABLE, $row, ['created_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);

            return (int) $connection->lastInsertId();
        });

        return [new Invitation($id, $email, $role, $firstName, $lastName, $invitedBy, $row['created_at'], $row['expires_at']), $token];
    }

    /**
     * The open invitation the token is for, or null when it was never made, was used or has expired.
     */
    public function find(string $token): ?Invitation
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('token_hash = :hash')
            ->andWhere('accepted_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('hash', hash('sha256', $token))
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->fromRow($row);
    }

    public function accept(Invitation $invitation): void
    {
        $this->connection->update(
            self::TABLE,
            ['accepted_at' => $this->clock->now()],
            ['id' => $invitation->id],
            ['accepted_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Invitations that haven't been accepted yet and still work, newest first.
     *
     * @return list<Invitation>
     */
    public function open(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('accepted_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Invitation
    {
        $text = static fn (string $key): ?string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : null;
        $date = function (string $key) use ($row): ?DateTimeImmutable {
            $value = $this->connection->convertToPHPValue($row[$key] ?? null, Types::DATETIME_IMMUTABLE);

            return $value instanceof DateTimeImmutable ? $value : null;
        };

        return new Invitation(
            (int) $text('id'),
            (string) $text('email'),
            (string) $text('role'),
            $text('first_name'),
            $text('last_name'),
            $text('invited_by') === null ? null : (int) $text('invited_by'),
            $date('created_at') ?? $this->clock->now(),
            $date('expires_at') ?? $this->clock->now(),
            $date('accepted_at'),
        );
    }
}
