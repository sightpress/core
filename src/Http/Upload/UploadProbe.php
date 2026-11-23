<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Signs and checks the size-stepped requests of `system:check --probe-url`. A signature is good for one body size and
 * five minutes, and can only be made with the install's `APP_SECRET`.
 */
final readonly class UploadProbe
{
    public const string PATH = '/_sightpress/upload-probe';

    public const string ANSWER_HEADER = 'X-SightPress-Probe';

    private const int LIFETIME = 300;

    public function __construct(
        #[SensitiveParameter] private ?string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function canSign(): bool
    {
        return $this->secret !== null;
    }

    /**
     * The query string of a probe with a body of this many bytes.
     *
     * @param positive-int $bytes
     */
    public function query(int $bytes): string
    {
        $expires = $this->clock->now()->getTimestamp() + self::LIFETIME;

        return http_build_query(['bytes' => $bytes, 'expires' => $expires, 'signature' => $this->signature($bytes, $expires)]);
    }

    /**
     * @param array<mixed> $query
     */
    public function accepts(array $query, string $contentLength): bool
    {
        $bytes = $query['bytes'] ?? null;
        $expires = $query['expires'] ?? null;
        $signature = $query['signature'] ?? null;
        if ($this->secret === null || !is_string($bytes) || !is_string($expires) || !is_string($signature) || !ctype_digit($bytes) || !ctype_digit($expires) || $bytes !== $contentLength) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();

        return (int) $expires >= $now && (int) $expires <= $now + self::LIFETIME && hash_equals($this->signature((int) $bytes, (int) $expires), $signature);
    }

    private function signature(int $bytes, int $expires): string
    {
        return hash_hmac('sha256', sprintf("upload-probe\n%d\n%d", $bytes, $expires), (string) $this->secret);
    }
}
