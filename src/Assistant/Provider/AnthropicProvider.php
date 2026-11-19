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
 * Anthropic's Claude, through the Messages API (`POST /v1/messages`, API version {@see VERSION}). The key goes in
 * the `x-api-key` header. Temperature isn't sent: the API version refuses it. A `refusal` stop is
 * {@see AssistantFailure::Refused}; `max_tokens` marks the answer as cut short.
 */
final readonly class AnthropicProvider extends ListedModelsProvider
{
    public const string ID = 'anthropic';

    public const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const string VERSION = '2026-10-21';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Anthropic (Claude)';
    }

    public function description(): string
    {
        return 'Claude is run by Anthropic, a company in the United States. What you send is processed in the United States and other countries. Anthropic doesn\'t train its models on what is sent with an API key, and keeps it for a limited time to look for misuse. Read Anthropic\'s privacy policy before you choose it.';
    }

    public function complete(AssistantRequest $request, AssistantConnection $connection): AssistantResponse
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => array_map(self::message(...), $request->conversation()),
        ];
        if ($request->instructions() !== '') {
            $body['system'] = $request->instructions();
        }
        $data = $this->http->postJson($this->label(), self::ENDPOINT, [
            'x-api-key' => $connection->apiKey,
            'anthropic-version' => self::VERSION,
        ], $body, self::failure(...));

        $stop = $data['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw new AssistantException(AssistantFailure::Refused, 'Anthropic declined the request.');
        }
        $text = '';
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'Anthropic answered without any text.');
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AssistantResponse(
            $text,
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $request->model,
            new TokenUsage(self::tokens($usage['input_tokens'] ?? 0), self::tokens($usage['output_tokens'] ?? 0)),
            $stop === 'max_tokens',
        );
    }

    /**
     * @param array<mixed> $error
     */
    public static function failure(int $status, array $error): AssistantFailure
    {
        $type = is_array($error['error'] ?? null) ? ($error['error']['type'] ?? null) : null;

        return match ($type) {
            'authentication_error', 'permission_error' => AssistantFailure::InvalidKey,
            'billing_error' => AssistantFailure::QuotaExceeded,
            'not_found_error' => AssistantFailure::ModelUnavailable,
            'rate_limit_error' => AssistantFailure::RateLimited,
            'overloaded_error', 'api_error' => AssistantFailure::Unavailable,
            default => AssistantHttp::failureOf($status),
        };
    }

    /**
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private static function message(AssistantMessage $message): array
    {
        $content = array_map(static fn (AssistantImage $image): array => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $image->mediaType, 'data' => base64_encode($image->data)],
        ], $message->images);
        $content[] = ['type' => 'text', 'text' => $message->text];

        return ['role' => $message->role->value, 'content' => $content];
    }
}
