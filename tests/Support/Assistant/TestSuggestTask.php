<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * A stand-in for a drafting task a Suggest button uses: takes the saved page as the Suggest buttons send it and
 * returns the answer as it is. Its prompt names it (`TEST TASK <id>`) for {@see TestAssistantProvider}.
 */
abstract class TestSuggestTask implements AssistantTask
{
    public function label(): string
    {
        return 'Suggest ' . $this->id();
    }

    public function permission(): string
    {
        return AssistantModule::USE;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'text' => ['type' => 'string'],
            ],
            'required' => ['field', 'title'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $title = is_string($input['title'] ?? null) ? $input['title'] : '';

        return [AssistantMessage::system('TEST TASK ' . $this->id()), AssistantMessage::user($title)];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return new AssistantSuggestion($response->text);
    }
}
