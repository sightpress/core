<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * `ask`: a question for the assistant ("Ask the assistant"), with the text the person chose to share, if any (the
 * page they are writing). The answer is plain text to read or copy.
 */
final readonly class AskTask implements AssistantTask
{
    public const string ID = 'ask';

    public const int MAX_QUESTION = 2000;

    public const int MAX_CONTEXT = 20000;

    public const int MAX_ANSWER = 8000;

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Ask the assistant';
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
                'question' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_QUESTION],
                'context' => ['type' => 'string', 'maxLength' => self::MAX_CONTEXT],
            ],
            'required' => ['question'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $system = 'You help people write and run a website. Answer the question in ' . LocaleCode::describe($context->locale())
            . ', in plain, friendly sentences, briefly. Write plain text without Markdown or HTML. If you aren\'t sure, say so. '
            . 'You can\'t change the website yourself: when something needs doing, say how the person can do it. ' . PromptData::RULE;
        $question = is_string($input['question'] ?? null) ? $input['question'] : '';
        $shared = is_string($input['context'] ?? null) ? trim($input['context']) : '';
        $user = $shared === '' ? PromptData::block('question', $question)
            : PromptData::block('page', $shared) . "\n\nThe person shared the page above. Their question:\n" . PromptData::block('question', $question);

        return [AssistantMessage::system($system), AssistantMessage::user($user)];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return new AssistantSuggestion(PlainText::of($response->text, self::MAX_ANSWER));
    }
}
