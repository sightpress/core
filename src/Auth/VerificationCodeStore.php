<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use SightPress\Core\Auth\Contract\CodeCheck;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Privacy\ExportedDate;

/**
 * {@see VerificationCodes} in the site's `verification_code` table.
 *
 * The address is stored as an HMAC of its normalised form and the code as an HMAC-SHA256 over purpose, address and
 * code, both keyed from `APP_SECRET`: the database never holds a code, and a stolen copy of it can't be used to try
 * codes offline without the secret.
 */
final readonly class VerificationCodeStore implements VerificationCodes
{
    private const string TABLE = 'verification_code';

    /** Rows that stopped working are deleted once they are this old, in seconds. */
    private const int KEEP_DEAD_FOR = 86400;

    private string $key;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private RateLimiters $limiters,
        #[\SensitiveParameter] string $secret,
    ) {
        $this->key = hash_hkdf('sha256', $secret, 32, 'sightpress verification codes');
    }

    /**
     * The six digits of a code as typed, without spaces or dashes, or null when it isn't six digits.
     */
    public static function digits(string $typed): ?string
    {
        $digits = (string) preg_replace('/[\s\x{2010}-\x{2015}-]/u', '', $typed);

        return preg_match('/^\d{6}$/', $digits) === 1 ? $digits : null;
    }

    public function issue(VerificationPurpose $purpose, VerificationChannel $channel, string $target, ?int $accountId = null): IssuedCode
    {
        $target = VerificationTarget::normalise($channel, $target);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d seconds', $purpose->lifetime($channel)));
        $targetHash = $this->targetHash($target);

        $this->connection->transactional(function (Connection $connection) use ($purpose, $channel, $targetHash, $target, $code, $accountId, $now, $expires): void {
            $connection->createQueryBuilder()
                ->delete(self::TABLE)
                ->where('(purpose = :purpose AND target_hash = :target) OR expires_at < :dead')
                ->setParameter('purpose', $purpose->value)
                ->setParameter('target', $targetHash)
                ->setParameter('dead', $now->modify(sprintf('-%d seconds', self::KEEP_DEAD_FOR)), Types::DATETIME_IMMUTABLE)
                ->executeStatement();
            $connection->insert(self::TABLE, [
                'purpose' => $purpose->value,
                'channel' => $channel->value,
                'account_id' => $accountId,
                'target_hash' => $targetHash,
                'code_hash' => $this->codeHash($purpose, $target, $code),
                'attempts' => 0,
                'created_at' => $now,
                'expires_at' => $expires,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);
        });

        return new IssuedCode($code, $expires);
    }

    public function request(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $target,
        ?int $accountId,
        string $client,
    ): IssuedCode {
        $normalised = VerificationTarget::normalise($channel, $target);
        $row = $this->row($purpose, $normalised);
        // The minute between two codes only holds while the last one can still be used: after it was used, a new
        // one may be needed at once (another change to confirm). The rate limits below still apply.
        $created = $row === null || ($row['consumed_at'] ?? null) !== null ? null : $this->date($row['created_at'] ?? null);
        if ($created !== null) {
            $wait = $created->getTimestamp() + self::RESEND_AFTER - $this->clock->now()->getTimestamp();
            if ($wait > 0) {
                throw new TooManyCodes($wait, tooSoon: true);
            }
        }
        foreach (['verification_target' => $purpose->value . '|' . $this->targetHash($normalised), 'verification_client' => $client] as $policy => $key) {
            $limit = $this->limiters->limiter($policy, $key)->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyCodes(RateLimiters::secondsUntil($limit->getRetryAfter()));
            }
        }

        return $this->issue($purpose, $channel, $normalised, $accountId);
    }

    public function check(VerificationPurpose $purpose, VerificationChannel $channel, string $target, string $code, string $client): CodeCheck
    {
        $limit = $this->limiters->limiter('verification_check', $client)->consume();
        if (!$limit->isAccepted()) {
            return new CodeCheck(CodeStatus::Limited, retryAfter: RateLimiters::secondsUntil($limit->getRetryAfter()));
        }

        $target = VerificationTarget::normalise($channel, $target);
        $digits = self::digits($code) ?? '';
        $row = $this->row($purpose, $target);
        // Hash even when there is nothing to compare with, so both answers take the same time.
        $typed = $this->codeHash($purpose, $target, $digits);
        if ($row === null || ($row['consumed_at'] ?? null) !== null) {
            return new CodeCheck(CodeStatus::Missing);
        }
        $id = self::int($row['id'] ?? null);
        $attempts = self::int($row['attempts'] ?? null);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return new CodeCheck(CodeStatus::Locked);
        }
        $expires = $this->date($row['expires_at'] ?? null);
        if ($expires === null || $expires <= $this->clock->now()) {
            return new CodeCheck(CodeStatus::Expired);
        }

        $stored = $row['code_hash'] ?? null;
        if (!is_string($stored) || !hash_equals($stored, $typed)) {
            $this->connection->update(self::TABLE, ['attempts' => $attempts + 1], ['id' => $id]);

            return new CodeCheck($attempts + 1 >= self::MAX_ATTEMPTS ? CodeStatus::Locked : CodeStatus::Wrong);
        }

        $used = $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('consumed_at', ':now')
            ->where('id = :id')
            ->andWhere('consumed_at IS NULL')
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->executeStatement();
        $account = $row['account_id'] ?? null;

        // Not 1 row: someone else used the code in the meantime.
        return $used === 1
            ? new CodeCheck(CodeStatus::Valid, $account === null ? null : self::int($account))
            : new CodeCheck(CodeStatus::Missing);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(VerificationPurpose $purpose, string $target): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('purpose = :purpose')
            ->andWhere('target_hash = :target')
            ->setParameter('purpose', $purpose->value)
            ->setParameter('target', $this->targetHash($target))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * The codes sent to the person, by account or to their email address or number (*Download your data*): when,
     * why and how each was sent, never the code or where it went.
     *
     * @param list<array{VerificationChannel, string}> $targets the person's email address and number
     *
     * @return list<array{purpose: string, channel: string, attempts: int, created_at: string|null, expires_at: string|null, used_at: string|null}>
     */
    public function forPerson(int $accountId, array $targets): array
    {
        $rows = $this->personQuery($accountId, $targets)
            ->select('purpose', 'channel', 'attempts', 'created_at', 'expires_at', 'consumed_at')
            ->orderBy('created_at')
            ->fetchAllAssociative();

        return array_values(array_map(fn (array $row): array => [
            'purpose' => is_scalar($row['purpose']) ? (string) $row['purpose'] : '',
            'channel' => is_scalar($row['channel']) ? (string) $row['channel'] : '',
            'attempts' => self::int($row['attempts']),
            'created_at' => ExportedDate::of($this->date($row['created_at'])),
            'expires_at' => ExportedDate::of($this->date($row['expires_at'])),
            'used_at' => ExportedDate::of($this->date($row['consumed_at'])),
        ], $rows));
    }

    /**
     * Deletes every code sent to the person (*Delete your account*).
     *
     * @param list<array{VerificationChannel, string}> $targets the person's email address and number
     *
     * @return int how many were deleted
     */
    public function forgetPerson(int $accountId, array $targets): int
    {
        $ids = array_map(self::int(...), $this->personQuery($accountId, $targets)->select('id')->fetchFirstColumn());
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param list<array{VerificationChannel, string}> $targets
     */
    private function personQuery(int $accountId, array $targets): QueryBuilder
    {
        $hashes = array_map(fn (array $target): string => $this->targetHash(VerificationTarget::normalise($target[0], $target[1])), $targets);

        return $this->connection->createQueryBuilder()
            ->from(self::TABLE)
            ->where('account_id = :account OR target_hash IN (:hashes)')
            ->setParameter('account', $accountId)
            ->setParameter('hashes', $hashes === [] ? [''] : $hashes, ArrayParameterType::STRING);
    }

    private function targetHash(string $target): string
    {
        return hash_hmac('sha256', "target\n" . $target, $this->key);
    }

    private function codeHash(VerificationPurpose $purpose, string $target, string $code): string
    {
        return hash_hmac('sha256', implode("\n", ['code', $purpose->value, $target, $code]), $this->key);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $date = $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
