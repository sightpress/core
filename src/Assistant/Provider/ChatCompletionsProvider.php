<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantImage;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\TokenUsage;
use SightPress\Module\Exception\AssistantException;

/**
 * Services that speak the "chat completions" format (`POST <base>/chat/completions`, key as a bearer token): OpenAI,
 * Mistral and OpenAI-compatible servers. Pictures go as `data:` URLs. A `content_filter` finish or a `refusal` is
 * {@see AssistantFailure::Refused}; `length` marks the answer as cut short.
 */
abstract readonly class ChatCompletionsProvider extends ListedModelsProvider
{
    abstract protected function baseUrl(AssistantConnection $connection): string;

    /** The request field for the most output tokens. */
    protected function tokenField(): string
    {
        return 'max_tokens';
    }

    /** Whether the service takes a temperature. */
    protected function takesTemperature(): bool
    {
        return true;
    }

    public function complete(AssistantRequest $request, AssistantConnection $connection): AssistantResponse
    {
        $messages = [];
        if ($request->instructions() !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->instructions()];
        }
        foreach ($request->conversation() as $message) {
            $messages[] = self::message($message);
        }
        $body = ['model' => $request->model, 'messages' => $messages, $this->tokenField() => $request->maxOutputTokens];
        if ($request->temperature !== null && $this->takesTemperature()) {
            $body['temperature'] = $request->temperature;
        }
        $data = $this->http->postJson(
            $this->label(),
            rtrim($this->baseUrl($connection), '/') . '/chat/completions',
            ['Authorization' => 'Bearer ' . $connection->apiKey],
            $body,
            self::failure(...),
        );

        $choice = is_array($data['choices'] ?? null) && is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $finish = $choice['finish_reason'] ?? null;
        if ($finish === 'content_filter' || (is_string($message['refusal'] ?? null) && $message['refusal'] !== '')) {
            throw new AssistantException(AssistantFailure::Refused, sprintf('%s declined the request.', $this->label()));
        }
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        if (trim($text) === '') {
            throw new AssistantException(AssistantFailure::InvalidOutput, sprintf('%s answered without any text.', $this->label()));
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AssistantResponse(
            $text,
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $request->model,
            new TokenUsage(self::tokens($usage['prompt_tokens'] ?? 0), self::tokens($usage['completion_tokens'] ?? 0)),
            $finish === 'length',
        );
    }

    /**
     * @param array<mixed> $error
     */
    public static function failure(int $status, array $error): AssistantFailure
    {
        $details = is_array($error['error'] ?? null) ? $error['error'] : [];
        $code = $details['code'] ?? $details['type'] ?? null;

        return match ($code) {
            'insufficient_quota' => AssistantFailure::QuotaExceeded,
            'invalid_api_key' => AssistantFailure::InvalidKey,
            'model_not_found' => AssistantFailure::ModelUnavailable,
            default => AssistantHttp::failureOf($status),
        };
    }

    /**
     * @return array{role: string, content: string|list<array<string, mixed>>}
     */
    private static function message(AssistantMessage $message): array
    {
        if ($message->images === []) {
            return ['role' => $message->role->value, 'content' => $message->text];
        }
        $content = [['type' => 'text', 'text' => $message->text]];
        foreach ($message->images as $image) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => self::dataUrl($image)]];
        }

        return ['role' => $message->role->value, 'content' => $content];
    }

    private static function dataUrl(AssistantImage $image): string
    {
        return 'data:' . $image->mediaType . ';base64,' . base64_encode($image->data);
    }
}
