<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * `explain_check`: what one of the page's publish checks means, why it matters and how to fix it ("Explain this"
 * next to each check), in plain text.
 */
final readonly class ExplainCheckTask implements AssistantTask
{
    public const string ID = 'explain_check';

    public const int MAX_CHECK = 1000;

    public const int MAX_ANSWER = 4000;

    public function __construct(private Prompts $prompts)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Explain this check';
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
                'check' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CHECK],
                'title' => ['type' => 'string', 'maxLength' => 200],
            ],
            'required' => ['check'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $check = is_string($input['check'] ?? null) ? $input['check'] : '';
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale())),
            AssistantMessage::user(($title === '' ? '' : PromptData::block('page_title', $title) . "\n\n") . PromptData::block('check', $check) . "\n\nExplain the check above."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return new AssistantSuggestion(PlainText::of($response->text, self::MAX_ANSWER));
    }
}
