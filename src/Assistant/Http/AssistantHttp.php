<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

use Closure;
use JsonException;
use SightPress\Core\Infrastructure\Http\OutboundRequestRefused;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Exception\AssistantException;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * How core's assistant providers talk to their services (ADR-0040): through the tenant's one outgoing HTTP client,
 * to addresses {@see EndpointGuard} allows, with a {@see TIMEOUT} between bytes and at most {@see MAX_DURATION} in
 * all, trying again at most {@see RETRIES} times when the service is busy ({@see BackoffStrategy}). Errors become an
 * {@see AssistantException} whose message names the service and the HTTP status only: never the key, the prompt or
 * the answer.
 */
final readonly class AssistantHttp
{
    public const int TIMEOUT = 30;

    public const int MAX_DURATION = 90;

    public const int RETRIES = 2;

    public function __construct(
        private HttpClientInterface $client,
        private EndpointGuard $guard,
        private int $retryDelayMs = 500,
    ) {
    }

    /**
     * Posts JSON and returns the decoded JSON answer.
     *
     * @param array<string, string>                          $headers
     * @param array<string, mixed>                           $body
     * @param Closure(int, array<mixed>): AssistantFailure   $failure what an error status (and the error's JSON) means
     *
     * @return array<mixed>
     *
     * @throws AssistantException
     */
    public function postJson(string $service, string $url, array $headers, array $body, Closure $failure): array
    {
        try {
            $resolve = $this->guard->check($url);
        } catch (EndpointRefused $refused) {
            throw new AssistantException(AssistantFailure::Unavailable, sprintf('%s: %s', $service, $refused->getMessage()));
        }
        $client = new RetryableHttpClient($this->client, new BackoffStrategy($this->retryDelayMs), self::RETRIES);
        try {
            $response = $client->request('POST', $url, [
                'headers' => $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => $body,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'max_redirects' => 0,
                'resolve' => $resolve,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (OutboundRequestRefused $refused) {
            throw new AssistantException(AssistantFailure::Unavailable, sprintf('%s: %s', $service, $refused->getMessage()));
        } catch (TimeoutExceptionInterface) {
            throw new AssistantException(AssistantFailure::Timeout, sprintf('%s didn\'t answer in time.', $service));
        } catch (TransportExceptionInterface) {
            throw new AssistantException(AssistantFailure::Unavailable, sprintf('%s couldn\'t be reached.', $service));
        }
        $data = self::decode($content);
        if ($status >= 400) {
            throw new AssistantException($failure($status, $data ?? []), sprintf('%s answered HTTP %d.', $service, $status));
        }
        if ($data === null) {
            throw new AssistantException(AssistantFailure::InvalidOutput, sprintf('%s answered HTTP %d with something that isn\'t JSON.', $service, $status));
        }

        return $data;
    }

    /**
     * What an error status usually means, for every service; providers handle their own exceptions first.
     */
    public static function failureOf(int $status): AssistantFailure
    {
        return match (true) {
            $status === 401, $status === 403 => AssistantFailure::InvalidKey,
            $status === 402 => AssistantFailure::QuotaExceeded,
            $status === 404 => AssistantFailure::ModelUnavailable,
            $status === 408, $status === 504 => AssistantFailure::Timeout,
            $status === 429 => AssistantFailure::RateLimited,
            default => AssistantFailure::Unavailable,
        };
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(string $content): ?array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
