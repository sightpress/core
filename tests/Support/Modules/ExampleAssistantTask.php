<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

final class ExampleAssistantTask implements AssistantTask
{
    public function id(): string
    {
        return 'acme.event_description';
    }

    public function label(): string
    {
        return 'Suggest an event description';
    }

    public function permission(): string
    {
        return 'events.manage';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        return [AssistantMessage::user('Describe the event.')];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return new AssistantSuggestion($response->text === '' ? 'Nothing' : $response->text);
    }
}
