<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Keep me signed in on this device": a long-lived cookie `selector.validator`. The database keeps the selector and
 * a SHA-256 hash of the validator, so a stolen database can't sign anyone in. A token is replaced each time it is
 * used, and every token of a user is deleted when their password changes.
 */
final readonly class RememberTokens
{
    public const string TABLE = 'auth_remember_tokens';
    public const string COOKIE = 'sightpress_remember';
    public const string SECURE_COOKIE = '__Host-sightpress_remember';

    /** How long a token works, in seconds (30 days). */
    public const int LIFETIME = 2592000;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Stores a new token for the user and returns the `Set-Cookie` header value that gives it to the browser.
     */
    public function issue(int $userId, bool $secure): string
    {
        $selector = bin2hex(random_bytes(16));
        $validator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert(self::TABLE, [
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'user_id' => $userId,
            'expires_at' => $this->clock->now()->modify(sprintf('+%d seconds', self::LIFETIME)),
        ], ['expires_at' => Types::DATETIME_IMMUTABLE]);

        return sprintf(
            '%s=%s.%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            self::cookieName($secure),
            $selector,
            $validator,
            self::LIFETIME,
            $secure ? '; Secure' : '',
        );
    }

    /**
     * The user the request's cookie signs in, or null. The token used is deleted: issue a new one.
     */
    public function consume(ServerRequestInterface $request): ?int
    {
        $parts = self::parts($request);
        if ($parts === null) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('validator_hash', 'user_id', 'expires_at')
            ->from(self::TABLE)
            ->where('selector = :selector')
            ->setParameter('selector', $parts[0])
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }

        $this->connection->delete(self::TABLE, ['selector' => $parts[0]]);
        $expires = $this->connection->convertToPHPValue($row['expires_at'], Types::DATETIME_IMMUTABLE);
        $userId = filter_var($row['user_id'], FILTER_VALIDATE_INT);
        if (
            $userId === false
            || !is_string($row['validator_hash'])
            || !hash_equals($row['validator_hash'], hash('sha256', $parts[1]))
            || !$expires instanceof DateTimeImmutable
            || $expires < $this->clock->now()
        ) {
            return null;
        }

        return $userId;
    }

    /**
     * Deletes the request's token, if any, and returns the `Set-Cookie` value that removes the cookie.
     */
    public function forget(ServerRequestInterface $request): string
    {
        $parts = self::parts($request);
        if ($parts !== null) {
            $this->connection->delete(self::TABLE, ['selector' => $parts[0]]);
        }
        $secure = $request->getUri()->getScheme() === 'https';

        return sprintf(
            '%s=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax%s',
            self::cookieName($secure),
            $secure ? '; Secure' : '',
        );
    }

    /**
     * Deletes every token of a user, for example after their password changed.
     */
    public function forgetUser(int $userId): void
    {
        $this->connection->delete(self::TABLE, ['user_id' => $userId]);
    }

    public static function hasCookie(ServerRequestInterface $request): bool
    {
        return self::parts($request) !== null;
    }

    /**
     * @return array{string, string}|null selector and validator
     */
    private static function parts(ServerRequestInterface $request): ?array
    {
        $secure = $request->getUri()->getScheme() === 'https';
        $value = $request->getCookieParams()[self::cookieName($secure)] ?? null;
        if (!is_string($value) || preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/', $value, $parts) !== 1) {
            return null;
        }

        return [$parts[1], $parts[2]];
    }

    private static function cookieName(bool $secure): string
    {
        return $secure ? self::SECURE_COOKIE : self::COOKIE;
    }
}
