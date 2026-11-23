<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Signs media addresses so a file can be fetched without a session until the signature expires. The HMAC covers the
 * site, the file's key, the width and the expiry, so a signature can't be moved to another site, file, size or time.
 * Its key is derived from `APP_SECRET`; without one nothing is signed and nothing verifies.
 *
 * Expiry times are rounded up to a quarter of an hour, so a page keeps the same addresses (and ETag) for a while.
 */
final readonly class MediaUrlSigner
{
    public const string EXPIRES = 'expires';
    public const string SIGNATURE = 'signature';

    /** For members reading pages. */
    public const int MEMBER_TTL = 3600;

    /** For someone previewing a private file in the admin. */
    public const int PREVIEW_TTL = 900;

    private const int ROUND_TO = 900;

    /** The longest any signature may live, so a forged far-future expiry is refused even with a leaked old key. */
    private const int MAX_TTL = self::MEMBER_TTL + self::ROUND_TO;

    public function __construct(
        #[SensitiveParameter] private ?string $secret,
        private string $siteId,
        private ClockInterface $clock,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->secret !== null;
    }

    /**
     * The query string that makes an address valid for at least `$ttl` seconds.
     */
    public function query(string $key, ?int $width, int $ttl): string
    {
        $now = $this->clock->now()->getTimestamp();
        $expires = (int) (ceil(($now + $ttl) / self::ROUND_TO) * self::ROUND_TO);

        return http_build_query([self::EXPIRES => $expires, self::SIGNATURE => $this->signature($key, $width, $expires)]);
    }

    /**
     * @param array<mixed> $query the request's query parameters
     */
    public function verifies(string $key, ?int $width, array $query): bool
    {
        $expires = $query[self::EXPIRES] ?? null;
        $signature = $query[self::SIGNATURE] ?? null;
        if ($this->secret === null || !is_string($expires) || !is_string($signature) || preg_match('/^[1-9]\d{0,11}$/', $expires) !== 1) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        if ((int) $expires <= $now || (int) $expires > $now + self::MAX_TTL) {
            return false;
        }

        return hash_equals($this->signature($key, $width, (int) $expires), $signature);
    }

    private function signature(string $key, ?int $width, int $expires): string
    {
        $secret = hash_hkdf('sha256', (string) $this->secret, 32, 'sightpress-media-url', $this->siteId);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', implode('|', [$this->siteId, $key, $width ?? 'original', $expires]), $secret, true)), '+/', '-_'), '=');
    }
}
