<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use SightPress\Module\Assistant\AssistantConnection;

/**
 * OpenAI's models through chat completions. Its newer models take `max_completion_tokens` and only their default
 * temperature, so none is sent.
 */
final readonly class OpenAiProvider extends ChatCompletionsProvider
{
    public const string ID = 'openai';

    public const string BASE_URL = 'https://api.openai.com/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'OpenAI (GPT)';
    }

    public function description(): string
    {
        return 'GPT is run by OpenAI, a company in the United States. What you send is processed in the United States and other countries. OpenAI doesn\'t train its models on what is sent with an API key unless you agree to it, and keeps it for a limited time to look for misuse. Read OpenAI\'s privacy policy before you choose it.';
    }

    protected function baseUrl(AssistantConnection $connection): string
    {
        return self::BASE_URL;
    }

    protected function tokenField(): string
    {
        return 'max_completion_tokens';
    }

    protected function takesTemperature(): bool
    {
        return false;
    }
}
