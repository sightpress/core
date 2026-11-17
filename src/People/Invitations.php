<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;

/**
 * The site's invitations. Each comes with a six-digit invitation code ({@see VerificationCodes}, purpose
 * `invitation`) that the person types with their email address on the "Accept an invitation" page — never a link.
 * An invitation works once, for {@see TTL} seconds; inviting the same address again replaces the invitation that is
 * still open and its code, so the older code stops working.
 */
final readonly class Invitations
{
    /** How long an invitation works: 7 days. */
    public const int TTL = 604800;

    private const string TABLE = 'invitations';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private VerificationCodes $codes,
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{Invitation, IssuedCode} the invitation and its code, to send by email
     */
    public function create(string $email, string $role, ?string $firstName, ?string $lastName, ?int $invitedBy): array
    {
        $email = self::normalizeEmail($email);
        $now = $this->clock->now();
        $row = [
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

        $code = $this->codes->issue(VerificationPurpose::Invitation, VerificationChannel::Email, $email);

        return [new Invitation($id, $email, $role, $firstName, $lastName, $invitedBy, $row['created_at'], $row['expires_at']), $code];
    }

    /**
     * The open invitation for the address, when the code is its invitation code; null when the code is wrong, used
     * or dead, or there is no open invitation. A right code is used up.
     *
     * @throws TooManyCodes when this client tried too many codes
     */
    public function verify(string $email, string $code, string $client): ?Invitation
    {
        $email = self::normalizeEmail($email);
        $check = $this->codes->check(VerificationPurpose::Invitation, VerificationChannel::Email, $email, $code, $client);
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        if (!$check->isValid()) {
            return null;
        }
        $row = $this->openQuery()
            ->andWhere('email = :email')
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->fromRow($row);
    }

    /**
     * The invitation with this id, while it is open.
     */
    public function findOpen(int $id): ?Invitation
    {
        $row = $this->openQuery()
            ->andWhere('id = :id')
            ->setParameter('id', $id)
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
        $rows = $this->openQuery()
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->fromRow(...), $rows);
    }

    private function openQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('accepted_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE);
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
