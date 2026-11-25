<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Provider;

use PHPUnit\Framework\TestCase;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Module\Exception\SmsException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Records what a provider sends through {@see SmsHttp} and answers with prepared responses.
 */
abstract class SmsProviderTestCase extends TestCase
{
    /** A secret that must never appear in an exception or a log. */
    protected const string CANARY = 'canary5f2b9c1d7e4a';

    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    protected array $sent = [];

    /**
     * @param list<MockResponse|\Closure(): never> $responses
     */
    protected function http(array $responses): SmsHttp
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $headers = $options['headers'] ?? [];
            $this->sent[] = [
                'method' => $method,
                'url' => $url,
                'headers' => is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [],
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
            ];
            $next = array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);

            return $next instanceof MockResponse ? $next : $next();
        });

        return new SmsHttp($client, 0);
    }

    /**
     * @param array<mixed> $body
     */
    protected static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * @param \Closure(): mixed $call
     */
    protected static function failure(\Closure $call): SmsException
    {
        try {
            $call();
        } catch (SmsException $exception) {
            self::assertStringNotContainsString(self::CANARY, (string) $exception);
            self::assertStringNotContainsString('7400123456', (string) $exception);

            return $exception;
        }
        self::fail('Expected the call to fail.');
    }

    /**
     * @return array<string, string>
     */
    protected function form(int $index = 0): array
    {
        parse_str($this->sent[$index]['body'], $form);

        $fields = [];
        foreach ($form as $name => $value) {
            if (is_string($value)) {
                $fields[(string) $name] = $value;
            }
        }

        return $fields;
    }
}
