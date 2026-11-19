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
 * `seo_meta`: the title in search results and the description for search engines of the saved page, as JSON; the
 * suggestion is the field that was asked for (`seo_title` or `description`), and both are in its fields. Plain text
 * within the fields' limits.
 */
final readonly class SeoMetaTask implements AssistantTask
{
    public const string ID = 'seo_meta';

    public function __construct(private Prompts $prompts)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Suggest what search engines show';
    }

    public function permission(): string
    {
        return AssistantModule::USE;
    }

    public function inputSchema(): array
    {
        return PageInput::schema(['seo_title', 'description']);
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), ['max' => PageLimits::DESCRIPTION_SHOWN])),
            AssistantMessage::user(PageInput::data($input) . "\n\nWrite the title and the description of the page above."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $data = JsonAnswer::object($response->text);
        $title = Unquoted::of(PlainText::of(JsonAnswer::text($data, 'title'), PageLimits::SEO_TITLE));
        $description = Unquoted::of(PlainText::of(JsonAnswer::text($data, 'description'), PageLimits::DESCRIPTION));

        return new AssistantSuggestion(($input['field'] ?? null) === 'seo_title' ? $title : $description, ['seo_title' => $title, 'description' => $description]);
    }
}
