<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * When the assistant tries a request again: only when the provider says it is busy (429, 500, 502, 503, 504, 529),
 * never after a timeout or a network error (that would make a person wait twice as long), and not when the provider
 * asks to wait longer than {@see MAX_DELAY_MS} (`Retry-After`, which the retrying client then honours): the person
 * is told to try again later instead. Without `Retry-After` the delay doubles each time.
 */
final readonly class BackoffStrategy implements RetryStrategyInterface
{
    public const array STATUSES = [429, 500, 502, 503, 504, 529];

    public const int MAX_DELAY_MS = 5000;

    public function __construct(private int $delayMs = 500)
    {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): bool
    {
        if ($exception !== null || !in_array($context->getStatusCode(), self::STATUSES, true)) {
            return false;
        }
        $values = $context->getHeaders()['retry-after'] ?? [];
        $retryAfter = is_array($values) ? ($values[0] ?? null) : null;

        return !is_string($retryAfter) || (is_numeric($retryAfter) && (float) $retryAfter * 1000 <= self::MAX_DELAY_MS);
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        $count = $context->getInfo('retry_count');

        return min(self::MAX_DELAY_MS, $this->delayMs * 2 ** (is_int($count) ? $count : 0));
    }
}
