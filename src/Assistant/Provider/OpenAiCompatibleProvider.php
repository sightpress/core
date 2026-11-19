<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\ProviderCapabilities;
use SightPress\Module\Exception\AssistantException;

/**
 * Any other service or self-hosted server that speaks OpenAI's chat completions format, at the base URL the admin
 * gives (for example `https://ai.example.org/v1`). The admin types the model name; pictures aren't sent, since
 * nothing says the model can see them.
 */
final readonly class OpenAiCompatibleProvider extends ChatCompletionsProvider
{
    public const string ID = 'openai_compatible';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Another OpenAI-compatible service';
    }

    public function description(): string
    {
        return 'A service or your own server that works like OpenAI\'s API, at an address you give. Who runs it, where it processes what you send and how long it keeps it depend on that service: check before you choose it.';
    }

    public function models(): array
    {
        return [];
    }

    public function needsBaseUrl(): bool
    {
        return true;
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities();
    }

    protected function baseUrl(AssistantConnection $connection): string
    {
        return $connection->baseUrl ?? throw new AssistantException(AssistantFailure::Unavailable, 'An OpenAI-compatible service needs a base URL.');
    }
}
