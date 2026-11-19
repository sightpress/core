<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Provider;

use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Provider\ProviderModels;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;
use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Exception\AssistantException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the provider tests share: a mocked HTTP client that keeps each request, a canary key that must never show up
 * in an exception, and the real model list.
 */
abstract class ProviderTestCase extends TestCase
{
    protected const string CANARY = 'sk-canary-5f2b9c1d7e4a';

    /** @var list<array{method: string, url: string, headers: list<string>, body: array<mixed>}> */
    protected array $sent = [];

    /**
     * @param list<MockResponse> $responses
     */
    protected function http(array $responses): AssistantHttp
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $headers = $options['headers'] ?? [];
            $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [], 'body' => is_array($body) ? $body : []];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new AssistantHttp($client, new EndpointGuard(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new FixedResolver()), 0);
    }

    protected static function models(): ProviderModels
    {
        return new ProviderModels();
    }

    protected static function connection(?string $baseUrl = null): AssistantConnection
    {
        return new AssistantConnection(self::CANARY, $baseUrl);
    }

    /**
     * @param array<mixed> $body
     */
    protected static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * The failure a call ends with; its message never holds the key, the prompt or the answer.
     */
    protected static function failure(AssistantProvider $provider, AssistantRequest $request, ?AssistantConnection $connection = null): AssistantException
    {
        try {
            $provider->complete($request, $connection ?? self::connection());
        } catch (AssistantException $exception) {
            self::assertStringNotContainsString(self::CANARY, $exception->getMessage());
            self::assertStringNotContainsString('SECRET-PROMPT', $exception->getMessage());
            self::assertStringNotContainsString(self::CANARY, (string) $exception);

            return $exception;
        }
        self::fail('Expected the call to fail.');
    }
}
