<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\ProviderCapabilities;

final class ExampleAssistantProvider implements AssistantProvider
{
    public function id(): string
    {
        return 'acme.echo';
    }

    public function label(): string
    {
        return 'Echo';
    }

    public function description(): string
    {
        return 'Runs on this server and sends nothing anywhere.';
    }

    public function models(): array
    {
        return ['echo-1'];
    }

    public function needsBaseUrl(): bool
    {
        return false;
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities();
    }

    public function complete(AssistantRequest $request, AssistantConnection $connection): AssistantResponse
    {
        return new AssistantResponse('Echo', $request->model);
    }
}
