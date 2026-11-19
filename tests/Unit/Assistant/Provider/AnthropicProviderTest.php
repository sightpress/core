<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Assistant\Provider\ListedModelsProvider;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantImage;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantRequest;

#[CoversClass(AnthropicProvider::class)]
#[CoversClass(ListedModelsProvider::class)]
#[UsesNamespace('SightPress\Core')]
final class AnthropicProviderTest extends ProviderTestCase
{
    public function testDescribesItselfAndItsModels(): void
    {
        $provider = new AnthropicProvider($this->http([]), self::models());

        self::assertSame(['anthropic', 'Anthropic (Claude)', false], [$provider->id(), $provider->label(), $provider->needsBaseUrl()]);
        self::assertStringContainsString('United States', $provider->description());
        self::assertSame('claude-opus-5-5', $provider->models()[0]);
        self::assertTrue($provider->capabilities('claude-haiku-5-5')->vision);
        self::assertFalse($provider->capabilities('claude-unknown')->vision);
        self::assertFalse($provider->capabilities('claude-haiku-5-5')->streaming);
    }

    public function testSendsAMessagesRequestAndReadsTheAnswer(): void
    {
        $provider = new AnthropicProvider($this->http([self::json([
            'model' => 'claude-haiku-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'A short '], ['type' => 'text', 'text' => 'summary.'], 'junk'],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 8],
        ])]), self::models());

        $response = $provider->complete(new AssistantRequest('claude-haiku-5-5', [
            AssistantMessage::system('Summarise.'),
            AssistantMessage::system('Be brief.'),
            AssistantMessage::user('First', [new AssistantImage('image/png', "\x89PNG")]),
            AssistantMessage::assistant('Earlier answer'),
            AssistantMessage::user('Page text'),
        ], 300, 0.2), self::connection('https://ignored.example/v1'));

        self::assertSame(['A short summary.', 'claude-haiku-5-5', 120, 8, false], [$response->text, $response->model, $response->usage->inputTokens, $response->usage->outputTokens, $response->truncated]);
        $sent = $this->sent[0];
        self::assertSame(['POST', AnthropicProvider::ENDPOINT], [$sent['method'], $sent['url']]);
        self::assertContains('x-api-key: ' . self::CANARY, $sent['headers']);
        self::assertContains('anthropic-version: 2026-10-21', $sent['headers']);
        self::assertContains('Content-Type: application/json', $sent['headers']);
        self::assertSame([
            'model' => 'claude-haiku-5-5',
            'max_tokens' => 300,
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode("\x89PNG")]], ['type' => 'text', 'text' => 'First']]],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Earlier answer']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Page text']]],
            ],
            'system' => "Summarise.\n\nBe brief.",
        ], $sent['body'], 'no temperature: the API version refuses it');
    }

    public function testMarksCutShortAnswersAndCopesWithMissingFields(): void
    {
        $provider = new AnthropicProvider($this->http([self::json(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => 'Cut']], 'usage' => ['input_tokens' => -3, 'output_tokens' => 'x']])]), self::models());

        $response = $provider->complete(new AssistantRequest('claude-opus-5-5', [AssistantMessage::user('Hello')]), self::connection());

        self::assertSame(['Cut', 'claude-opus-5-5', 0, 0, true], [$response->text, $response->model, $response->usage->inputTokens, $response->usage->outputTokens, $response->truncated]);
        self::assertArrayNotHasKey('system', $this->sent[0]['body']);
    }

    public function testRefusalsAndEmptyAnswersAreFailures(): void
    {
        $request = new AssistantRequest('claude-opus-5-5', [AssistantMessage::user('SECRET-PROMPT')]);

        self::assertSame(AssistantFailure::Refused, self::failure(new AnthropicProvider($this->http([self::json(['stop_reason' => 'refusal', 'content' => [['type' => 'text', 'text' => 'No']]])]), self::models()), $request)->failure);
        self::assertSame(AssistantFailure::InvalidOutput, self::failure(new AnthropicProvider($this->http([self::json(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '  ']]])]), self::models()), $request)->failure);
        self::assertSame(AssistantFailure::InvalidOutput, self::failure(new AnthropicProvider($this->http([self::json(['content' => 'nothing'])]), self::models()), $request)->failure);
    }

    /**
     * @return iterable<string, array{int, string|null, AssistantFailure}>
     */
    public static function errors(): iterable
    {
        yield 'bad key' => [401, 'authentication_error', AssistantFailure::InvalidKey];
        yield 'key not allowed' => [403, 'permission_error', AssistantFailure::InvalidKey];
        yield 'no credit' => [400, 'billing_error', AssistantFailure::QuotaExceeded];
        yield 'model gone' => [404, 'not_found_error', AssistantFailure::ModelUnavailable];
        yield 'too many' => [429, 'rate_limit_error', AssistantFailure::RateLimited];
        yield 'overloaded' => [529, 'overloaded_error', AssistantFailure::Unavailable];
        yield 'api error' => [500, 'api_error', AssistantFailure::Unavailable];
        yield 'too large, by status' => [413, 'request_too_large', AssistantFailure::Unavailable];
        yield 'payment, by status' => [402, null, AssistantFailure::QuotaExceeded];
    }

    #[DataProvider('errors')]
    public function testMapsErrorsToPlainFailures(int $status, ?string $type, AssistantFailure $failure): void
    {
        $body = $type === null ? ['oops' => true] : ['type' => 'error', 'error' => ['type' => $type, 'message' => 'Echo: ' . self::CANARY]];
        $responses = array_fill(0, 3, self::json($body, $status));
        $exception = self::failure(new AnthropicProvider($this->http($responses), self::models()), new AssistantRequest('claude-opus-5-5', [AssistantMessage::user('SECRET-PROMPT')]));

        self::assertSame($failure, $exception->failure);
        self::assertSame(sprintf('Anthropic (Claude) answered HTTP %d.', $status), $exception->getMessage());
    }
}
