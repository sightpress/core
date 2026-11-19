<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Core\Assistant\Http\BackoffStrategy;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Exception\AssistantException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[CoversClass(AssistantHttp::class)]
#[CoversClass(BackoffStrategy::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantHttpTest extends TestCase
{
    public function testPostsJsonToTheCheckedAddressWithTimeouts(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $data = self::http($client)->postJson('Fake AI', 'https://ai.example.org/v1/x', ['Authorization' => 'Bearer k'], ['a' => 1], self::noError(...));

        self::assertSame(['ok' => true], $data);
        self::assertSame(['POST', 'https://ai.example.org/v1/x'], [$seen[0], $seen[1]]);
        self::assertIsArray($seen[2]);
        self::assertIsArray($seen[2]['headers']);
        self::assertSame('{"a":1}', $seen[2]['body']);
        self::assertSame(['ai.example.org' => '93.184.215.14'], $seen[2]['resolve']);
        self::assertEquals(AssistantHttp::TIMEOUT, $seen[2]['timeout']);
        self::assertEquals(AssistantHttp::MAX_DURATION, $seen[2]['max_duration']);
        self::assertSame(0, $seen[2]['max_redirects']);
        self::assertContains('Authorization: Bearer k', $seen[2]['headers']);
        self::assertContains('Accept: application/json', $seen[2]['headers']);
    }

    public function testTriesAgainOnlyWhenTheServiceIsBusy(): void
    {
        $answers = [
            new MockResponse('{}', ['http_code' => 529]),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            new MockResponse('{"done":1}', ['http_code' => 200]),
        ];
        $client = new MockHttpClient($answers);

        self::assertSame(['done' => 1], self::http($client)->postJson('Fake AI', 'https://ai.example.org/v1', [], [], self::noError(...)));
        self::assertSame(3, $client->getRequestsCount());

        $client = new MockHttpClient([new MockResponse('{}', ['http_code' => 503]), new MockResponse('{}', ['http_code' => 503]), new MockResponse('{"error":{}}', ['http_code' => 503])]);
        try {
            self::http($client)->postJson('Fake AI', 'https://ai.example.org/v1', [], [], static fn (int $status): AssistantFailure => AssistantHttp::failureOf($status));
            self::fail('Expected a failure.');
        } catch (AssistantException $exception) {
            self::assertSame([AssistantFailure::Unavailable, 'Fake AI answered HTTP 503.'], [$exception->failure, $exception->getMessage()]);
        }
        self::assertSame(1 + AssistantHttp::RETRIES, $client->getRequestsCount());

        $client = new MockHttpClient([new MockResponse('{"error":{"type":"x"}}', ['http_code' => 400])]);
        try {
            self::http($client)->postJson('Fake AI', 'https://ai.example.org/v1', [], [], static function (int $status, array $error): AssistantFailure {
                self::assertSame([400, ['error' => ['type' => 'x']]], [$status, $error]);

                return AssistantFailure::Refused;
            });
            self::fail('Expected a failure.');
        } catch (AssistantException $exception) {
            self::assertSame(AssistantFailure::Refused, $exception->failure);
        }
        self::assertSame(1, $client->getRequestsCount(), 'a bad request is not tried again');
    }

    public function testTimeoutsNetworkErrorsAndNonsenseBecomeFailures(): void
    {
        $cases = [
            [new MockHttpClient(static fn (): MockResponse => throw new TimeoutException('Idle timeout reached for "https://ai.example.org/v1".')), AssistantFailure::Timeout, 'Fake AI didn\'t answer in time.'],
            [new MockHttpClient(static fn (): MockResponse => throw new TransportException('Could not resolve host')), AssistantFailure::Unavailable, 'Fake AI couldn\'t be reached.'],
            [new MockHttpClient([new MockResponse('<html>Hello</html>', ['http_code' => 200])]), AssistantFailure::InvalidOutput, 'Fake AI answered HTTP 200 with something that isn\'t JSON.'],
            [new MockHttpClient([new MockResponse('"just text"', ['http_code' => 200])]), AssistantFailure::InvalidOutput, 'Fake AI answered HTTP 200 with something that isn\'t JSON.'],
            [new MockHttpClient([new MockResponse('not json', ['http_code' => 401])]), AssistantFailure::InvalidKey, 'Fake AI answered HTTP 401.'],
        ];
        foreach ($cases as [$client, $failure, $message]) {
            try {
                self::http($client)->postJson('Fake AI', 'https://ai.example.org/v1', [], [], static fn (int $status): AssistantFailure => AssistantHttp::failureOf($status));
                self::fail('Expected a failure.');
            } catch (AssistantException $exception) {
                self::assertSame([$failure, $message], [$exception->failure, $exception->getMessage()]);
            }
        }
    }

    public function testRefusesAddressesTheGuardRefusesWithoutSendingAnything(): void
    {
        $client = new MockHttpClient([]);
        try {
            self::http($client)->postJson('Fake AI', 'https://intranet.example/v1', [], [], self::noError(...));
            self::fail('Expected a failure.');
        } catch (AssistantException $exception) {
            self::assertSame(AssistantFailure::Unavailable, $exception->failure);
            self::assertStringStartsWith('Fake AI: The assistant’s address can’t be found', $exception->getMessage());
        }
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testMapsCommonStatuses(): void
    {
        self::assertSame(
            [AssistantFailure::InvalidKey, AssistantFailure::InvalidKey, AssistantFailure::QuotaExceeded, AssistantFailure::ModelUnavailable, AssistantFailure::Timeout, AssistantFailure::Timeout, AssistantFailure::RateLimited, AssistantFailure::Unavailable, AssistantFailure::Unavailable],
            array_map(AssistantHttp::failureOf(...), [401, 403, 402, 404, 408, 504, 429, 500, 400]),
        );
    }

    public function testBacksOffByDoublingAndGivesUpWhenAskedToWaitLong(): void
    {
        $delays = new \ArrayObject();
        $recording = self::recording(new BackoffStrategy(7), $delays);

        $client = new MockHttpClient([new MockResponse('{}', ['http_code' => 503]), new MockResponse('{}', ['http_code' => 502]), new MockResponse('{}', ['http_code' => 200])]);
        self::assertSame(200, new RetryableHttpClient($client, $recording, 2)->request('POST', 'https://ai.example.org/v1')->getStatusCode());
        self::assertSame([7, 14], $delays->getArrayCopy());

        $client = new MockHttpClient([new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '60']]), new MockResponse('{}', ['http_code' => 200])]);
        self::assertSame(429, new RetryableHttpClient($client, $recording, 2)->request('POST', 'https://ai.example.org/v1')->getStatusCode());
        self::assertSame(1, $client->getRequestsCount());

        $client = new MockHttpClient([new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => 'Wed, 21 Oct 2099 07:28:00 GMT']]), new MockResponse('{}', ['http_code' => 200])]);
        self::assertSame(429, new RetryableHttpClient($client, $recording, 2)->request('POST', 'https://ai.example.org/v1')->getStatusCode());

        $client = new MockHttpClient([new MockResponse('', ['error' => 'reset']), new MockResponse('{}')]);
        try {
            new RetryableHttpClient($client, $recording, 2)->request('POST', 'https://ai.example.org/v1')->getStatusCode();
        } catch (TransportException) {
        }
        self::assertSame(1, $client->getRequestsCount(), 'network errors are not tried again');

        $delays = new \ArrayObject();
        $client = new MockHttpClient([new MockResponse('{}', ['http_code' => 503]), new MockResponse('{}', ['http_code' => 503]), new MockResponse('{}', ['http_code' => 200])]);
        new RetryableHttpClient($client, self::recording(new BackoffStrategy(4000), $delays), 2)->request('POST', 'https://ai.example.org/v1')->getStatusCode();
        self::assertSame([4000, BackoffStrategy::MAX_DELAY_MS], $delays->getArrayCopy());
    }

    /**
     * Keeps the delays the strategy asks for, and waits none of them.
     *
     * @param \ArrayObject<int, int> $delays
     */
    private static function recording(BackoffStrategy $strategy, \ArrayObject $delays): RetryStrategyInterface
    {
        return new readonly class ($strategy, $delays) implements RetryStrategyInterface {
            /**
             * @param \ArrayObject<int, int> $delays
             */
            public function __construct(private BackoffStrategy $inner, private \ArrayObject $delays)
            {
            }

            public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): bool
            {
                return $this->inner->shouldRetry($context, $responseContent, $exception);
            }

            public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
            {
                $this->delays[] = $this->inner->getDelay($context, $responseContent, $exception);

                return 0;
            }
        };
    }

    private static function http(MockHttpClient $client): AssistantHttp
    {
        return new AssistantHttp($client, new EndpointGuard(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new FixedResolver()), 0);
    }

    /**
     * @param array<mixed> $error
     */
    private static function noError(int $status, array $error): AssistantFailure
    {
        self::fail(sprintf('No error expected (HTTP %d).', $status));
    }
}
