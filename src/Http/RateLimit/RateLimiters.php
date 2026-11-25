<?php

declare(strict_types=1);

namespace SightPress\Core\Http\RateLimit;

use InvalidArgumentException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Named rate limits. `write` caps state-changing requests per client; `sign_in` caps sign-in attempts;
 * `contact_form` caps contact form messages per client; `registration` caps sign-up forms per client; `search`
 * caps site searches per client; `assistant` caps AI assistant requests per person. The
 * verification limits cap six-digit codes: `verification_target` how many codes one address gets,
 * `verification_client` how many codes one client asks for, and `verification_check` how many codes one client
 * tries. Modules ask for a limiter by policy and key, for example
 * the client's address or an email.
 */
final class RateLimiters
{
    /** @var array<string, array{policy: string, limit: positive-int, interval: string}> */
    public const array POLICIES = [
        'write' => ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'],
        'upload_part' => ['policy' => 'sliding_window', 'limit' => 600, 'interval' => '1 minute'],
        'sign_in' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'],
        'verification_target' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'],
        'verification_client' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'],
        'verification_check' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'],
        'contact_form' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '10 minutes'],
        'registration' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
        'search' => ['policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 minute'],
        'assistant' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '10 minutes'],
    ];

    /**
     * The text message limits (N-2) from the tenant's `sms.limits`: codes or messages per number, client address,
     * account and site each hour, and number lookups per site each day.
     *
     * @param array{per_number: positive-int, per_client: positive-int, per_account: positive-int, per_site: positive-int, lookups_per_day: positive-int} $limits
     *
     * @return array<string, array{policy: string, limit: positive-int, interval: string}>
     */
    public static function smsPolicies(array $limits): array
    {
        return [
            'sms_number' => ['policy' => 'sliding_window', 'limit' => $limits['per_number'], 'interval' => '1 hour'],
            'sms_client' => ['policy' => 'sliding_window', 'limit' => $limits['per_client'], 'interval' => '1 hour'],
            'sms_account' => ['policy' => 'sliding_window', 'limit' => $limits['per_account'], 'interval' => '1 hour'],
            'sms_site' => ['policy' => 'sliding_window', 'limit' => $limits['per_site'], 'interval' => '1 hour'],
            'sms_lookup' => ['policy' => 'sliding_window', 'limit' => $limits['lookups_per_day'], 'interval' => '1 day'],
        ];
    }

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
