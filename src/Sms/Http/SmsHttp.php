<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Http;

use JsonException;
use SightPress\Core\Infrastructure\Http\OutboundRequestRefused;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\SmsFailure;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * How core's text message providers talk to their services (N-2): through the tenant's one outgoing HTTP client, with
 * a {@see TIMEOUT} and no redirects, trying again at most {@see RETRIES} times only when the service says it is busy
 * (HTTP 429, so nothing was sent twice). Errors become an {@see SmsException} whose message names the service and the
 * HTTP status only: never the key, the number or the text.
 */
final readonly class SmsHttp
{
    public const int TIMEOUT = 10;

    public const int RETRIES = 2;

    public function __construct(
        private HttpClientInterface $client,
        private int $retryDelayMs = 500,
    ) {
    }

    /**
     * Sends a request and returns the HTTP status and the decoded JSON answer (empty when it isn't JSON).
     *
     * @param array<string, mixed> $options Symfony HttpClient options (`body`, `query`, `auth_basic`, `headers` …)
     *
     * @return array{int, array<mixed>}
     *
     * @throws SmsException on a timeout or when the service can't be reached
     */
    public function request(string $service, string $method, string $url, array $options): array
    {
        $client = new RetryableHttpClient($this->client, new GenericRetryStrategy([429], $this->retryDelayMs, 2.0), self::RETRIES);
        try {
            $response = $client->request($method, $url, $options + [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT * 3,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (OutboundRequestRefused $refused) {
            throw new SmsException(SmsFailure::Unavailable, sprintf('%s: %s', $service, $refused->getMessage()));
        } catch (TimeoutExceptionInterface) {
            throw new SmsException(SmsFailure::Timeout, sprintf('%s didn\'t answer in time.', $service));
        } catch (TransportExceptionInterface) {
            throw new SmsException(SmsFailure::Unavailable, sprintf('%s couldn\'t be reached.', $service));
        }

        return [$status, self::decode($content)];
    }

    /**
     * What an error status usually means, for every service; providers read their own error codes first.
     */
    public static function failureOf(int $status): SmsFailure
    {
        return match (true) {
            $status === 401, $status === 403 => SmsFailure::InvalidKey,
            $status === 402 => SmsFailure::NoCredit,
            $status === 408, $status === 504 => SmsFailure::Timeout,
            $status === 429 => SmsFailure::RateLimited,
            default => SmsFailure::Unavailable,
        };
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $content): array
    {
        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
