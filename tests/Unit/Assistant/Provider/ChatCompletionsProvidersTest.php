<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use SightPress\Core\Assistant\Provider\ChatCompletionsProvider;
use SightPress\Core\Assistant\Provider\ListedModelsProvider;
use SightPress\Core\Assistant\Provider\MistralProvider;
use SightPress\Core\Assistant\Provider\OpenAiCompatibleProvider;
use SightPress\Core\Assistant\Provider\OpenAiProvider;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantImage;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantRequest;

#[CoversClass(ChatCompletionsProvider::class)]
#[CoversClass(ListedModelsProvider::class)]
#[CoversClass(OpenAiProvider::class)]
#[CoversClass(MistralProvider::class)]
#[CoversClass(OpenAiCompatibleProvider::class)]
#[UsesNamespace('SightPress\Core')]
final class ChatCompletionsProvidersTest extends ProviderTestCase
{
    public function testDescribeThemselves(): void
    {
        $http = $this->http([]);
        $openAi = new OpenAiProvider($http, self::models());
        $mistral = new MistralProvider($http, self::models());
        $compatible = new OpenAiCompatibleProvider($http, self::models());

        self::assertSame(['openai', 'OpenAI (GPT)', 'gpt-5', false], [$openAi->id(), $openAi->label(), $openAi->models()[0], $openAi->needsBaseUrl()]);
        self::assertSame(['mistral', 'Mistral AI', 'mistral-large-latest', false], [$mistral->id(), $mistral->label(), $mistral->models()[0], $mistral->needsBaseUrl()]);
        self::assertSame(['openai_compatible', [], true], [$compatible->id(), $compatible->models(), $compatible->needsBaseUrl()]);
        self::assertStringContainsString('United States', $openAi->description());
        self::assertStringContainsString('European Union', $mistral->description());
        self::assertStringContainsString('address you give', $compatible->description());
        self::assertFalse($mistral->capabilities('mistral-large-latest')->vision);
        self::assertTrue($mistral->capabilities('mistral-small-latest')->vision);
        self::assertFalse($compatible->capabilities('llava')->vision);
    }

    public function testOpenAiGetsMaxCompletionTokensAndNoTemperature(): void
    {
        $provider = new OpenAiProvider($this->http([self::json([
            'model' => 'gpt-5-2026-08-01',
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hello there.', 'refusal' => null]]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4],
        ])]), self::models());

        $response = $provider->complete(new AssistantRequest('gpt-5', [
            AssistantMessage::system('Be kind.'),
            AssistantMessage::user('Describe', [new AssistantImage('image/jpeg', 'JPEGDATA')]),
            AssistantMessage::assistant('Before'),
            AssistantMessage::user('Again'),
        ], 200, 0.5), self::connection());

        self::assertSame(['Hello there.', 'gpt-5-2026-08-01', 30, 4, false], [$response->text, $response->model, $response->usage->inputTokens, $response->usage->outputTokens, $response->truncated]);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->sent[0]['url']);
        self::assertContains('Authorization: Bearer ' . self::CANARY, $this->sent[0]['headers']);
        self::assertSame([
            'model' => 'gpt-5',
            'messages' => [
                ['role' => 'system', 'content' => 'Be kind.'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Describe'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('JPEGDATA')]]]],
                ['role' => 'assistant', 'content' => 'Before'],
                ['role' => 'user', 'content' => 'Again'],
            ],
            'max_completion_tokens' => 200,
        ], $this->sent[0]['body']);
    }

    public function testMistralAndCompatibleServicesGetMaxTokensAndTheTemperature(): void
    {
        $answer = self::json(['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'Cut']]]]);
        $mistral = new MistralProvider($this->http([$answer]), self::models());
        $response = $mistral->complete(new AssistantRequest('mistral-small-latest', [AssistantMessage::user('Hi')], 50, 0.3), self::connection());

        self::assertSame(['Cut', 'mistral-small-latest', 0, true], [$response->text, $response->model, $response->usage->total(), $response->truncated]);
        self::assertSame('https://api.mistral.ai/v1/chat/completions', $this->sent[0]['url']);
        self::assertSame(['model' => 'mistral-small-latest', 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 50, 'temperature' => 0.3], $this->sent[0]['body']);

        $compatible = new OpenAiCompatibleProvider($this->http([self::json(['choices' => [['message' => ['content' => 'Local']]]])]), self::models());
        $compatible->complete(new AssistantRequest('llama', [AssistantMessage::user('Hi')], 50), self::connection('https://ai.example.org/v1/'));
        self::assertSame('https://ai.example.org/v1/chat/completions', $this->sent[1]['url']);
        self::assertArrayNotHasKey('temperature', $this->sent[1]['body']);
    }

    public function testACompatibleServiceNeedsItsAddress(): void
    {
        $exception = self::failure(new OpenAiCompatibleProvider($this->http([]), self::models()), new AssistantRequest('llama', [AssistantMessage::user('SECRET-PROMPT')]));

        self::assertSame([AssistantFailure::Unavailable, []], [$exception->failure, $this->sent]);
    }

    public function testRefusalsAndEmptyAnswersAreFailures(): void
    {
        $request = new AssistantRequest('gpt-5', [AssistantMessage::user('SECRET-PROMPT')]);
        $cases = [
            [['choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => 'x']]]], AssistantFailure::Refused],
            [['choices' => [['finish_reason' => 'stop', 'message' => ['content' => null, 'refusal' => 'I can\'t help with that.']]]], AssistantFailure::Refused],
            [['choices' => [['finish_reason' => 'stop', 'message' => ['content' => ' ']]]], AssistantFailure::InvalidOutput],
            [['choices' => 'none'], AssistantFailure::InvalidOutput],
            [['choices' => ['x']], AssistantFailure::InvalidOutput],
        ];
        foreach ($cases as [$body, $failure]) {
            self::assertSame($failure, self::failure(new OpenAiProvider($this->http([self::json($body)]), self::models()), $request)->failure);
        }
    }

    /**
     * @return iterable<string, array{int, array<mixed>, AssistantFailure}>
     */
    public static function errors(): iterable
    {
        yield 'bad key' => [401, ['error' => ['code' => 'invalid_api_key']], AssistantFailure::InvalidKey];
        yield 'no credit' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'insufficient_quota']], AssistantFailure::QuotaExceeded];
        yield 'too many' => [429, ['error' => ['type' => 'requests']], AssistantFailure::RateLimited];
        yield 'model gone' => [400, ['error' => ['code' => 'model_not_found']], AssistantFailure::ModelUnavailable];
        yield 'model gone, by status' => [404, ['detail' => 'Not found'], AssistantFailure::ModelUnavailable];
        yield 'mistral bad key' => [401, ['message' => 'Unauthorized'], AssistantFailure::InvalidKey];
        yield 'server' => [502, [], AssistantFailure::Unavailable];
    }

    /**
     * @param array<mixed> $body
     */
    #[DataProvider('errors')]
    public function testMapsErrorsToPlainFailures(int $status, array $body, AssistantFailure $failure): void
    {
        $exception = self::failure(new OpenAiProvider($this->http(array_fill(0, 3, self::json($body, $status))), self::models()), new AssistantRequest('gpt-5', [AssistantMessage::user('SECRET-PROMPT')]));

        self::assertSame([$failure, sprintf('OpenAI (GPT) answered HTTP %d.', $status)], [$exception->failure, $exception->getMessage()]);
    }
}
