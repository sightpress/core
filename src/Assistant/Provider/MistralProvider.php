<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use SightPress\Module\Assistant\AssistantConnection;

/**
 * Mistral AI's models through its chat completions API.
 */
final readonly class MistralProvider extends ChatCompletionsProvider
{
    public const string ID = 'mistral';

    public const string BASE_URL = 'https://api.mistral.ai/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Mistral AI';
    }

    public function description(): string
    {
        return 'Mistral AI is a company in France. What you send is processed in the European Union. Mistral keeps it for a limited time to look for misuse. Read Mistral AI\'s privacy policy before you choose it, including whether it may use what you send to improve its models.';
    }

    protected function baseUrl(AssistantConnection $connection): string
    {
        return self::BASE_URL;
    }
}
