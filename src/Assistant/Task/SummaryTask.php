<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Page\Contract\PageLimits;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * `summary`: one or two sentences about the saved page for its Summary field ("Suggest a summary"), in the page's
 * language, plain text within the field's limit.
 */
final readonly class SummaryTask implements AssistantTask
{
    public const string ID = 'summary';

    public function __construct(private Prompts $prompts)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Suggest a summary';
    }

    public function permission(): string
    {
        return AssistantModule::USE;
    }

    public function inputSchema(): array
    {
        return PageInput::schema(['summary']);
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), ['max' => PageLimits::SUMMARY])),
            AssistantMessage::user(PageInput::data($input) . "\n\nWrite the summary of the page above."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return new AssistantSuggestion(Unquoted::of(PlainText::of($response->text, PageLimits::SUMMARY)));
    }
}
