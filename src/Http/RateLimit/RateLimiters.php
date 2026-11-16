<?php

declare(strict_types=1);

namespace SightPress\Core\Http\RateLimit;

use InvalidArgumentException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Named rate limits. `write` caps state-changing requests per client; `sign_in` caps sign-in attempts and
 * `password_reset` caps "forgot your password" emails, `contact_form` caps contact form messages per client. Modules ask for a limiter by policy and key, for example the
 * client's address or an email.
 */
final class RateLimiters
{
    /** @var array<string, array{policy: string, limit: positive-int, interval: string}> */
    public const array POLICIES = [
        'write' => ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'],
        'sign_in' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'],
        'password_reset' => ['policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
        'contact_form' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '10 minutes'],
    ];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    /**
     * @param array<string, array{policy: string, limit: positive-int, interval: string}> $policies
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly array $policies = self::POLICIES,
    ) {
    }

    public function limiter(string $policy, string $key): LimiterInterface
    {
        $config = $this->policies[$policy] ?? throw new InvalidArgumentException(sprintf(
            'There is no rate limit policy "%s".',
            $policy,
        ));

        $this->factories[$policy] ??= new RateLimiterFactory(['id' => $policy] + $config, $this->storage);

        return $this->factories[$policy]->create($key);
    }

    /**
     * Seconds from now until a limiter's retry time, at least 1. The limiters keep time with the system clock.
     *
     * @return positive-int
     */
    public static function secondsUntil(\DateTimeImmutable $retryAfter): int
    {
        return max(1, $retryAfter->getTimestamp() - time());
    }
}
