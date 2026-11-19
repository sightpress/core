<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Exception\AssistantException;

/**
 * `draft_page`: blocks for a page from what the person asks for ("Draft this page"): headings and rich text only,
 * each checked against its block type's schema by the block validator (which also sanitises the HTML), at most
 * {@see MAX_BLOCKS}. The suggestion is the draft as Markdown, to read; `blocks` holds the checked blocks as JSON, which
 * Insert adds to the end of the page (unsaved).
 */
final readonly class DraftPageTask implements AssistantTask
{
    public const string ID = 'draft_page';

    public const int MAX_BRIEF = 2000;

    public const int MAX_BLOCKS = 30;

    /** The block types a draft may use, and the data each one keeps. */
    public const array TYPES = ['heading' => ['text', 'level'], 'rich_text' => ['html']];

    public function __construct(
        private Prompts $prompts,
        private BlockValidator $validator,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Draft this page';
    }

    public function permission(): string
    {
        return 'page.edit.own';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_BRIEF],
                'title' => ['type' => 'string', 'maxLength' => 200],
            ],
            'required' => ['brief'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $brief = is_string($input['brief'] ?? null) ? $input['brief'] : '';

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), ['max' => self::MAX_BLOCKS])),
            AssistantMessage::user(($title === '' ? '' : PromptData::block('title', $title) . "\n\n") . PromptData::block('brief', $brief) . "\n\nDraft the page that the brief above asks for."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $drafted = JsonAnswer::object($response->text)['blocks'] ?? null;
        if (!is_array($drafted) || !array_is_list($drafted) || $drafted === [] || count($drafted) > self::MAX_BLOCKS) {
            throw new AssistantException(AssistantFailure::InvalidOutput, sprintf('The draft must have 1 to %d blocks.', self::MAX_BLOCKS));
        }
        $blocks = [];
        foreach ($drafted as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (!is_array($block) || !is_string($type) || !isset(self::TYPES[$type])) {
                throw new AssistantException(AssistantFailure::InvalidOutput, 'The draft used a block type it may not use.');
            }
            $data = array_intersect_key($block, array_flip(self::TYPES[$type]));
            $blocks[] = ['type' => $type, 'version' => 1, 'data' => $data];
        }
        try {
            $validated = $this->validator->validate($blocks)->blocks;
        } catch (InvalidBlocks) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The draft\'s blocks didn\'t fit their types.');
        }
        $json = (string) json_encode($validated, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $preview = PlainText::of($this->renderer->markdown($validated, $this->contexts->create($context->locale(), 'https://localhost')), 200000);

        return new AssistantSuggestion($preview, ['blocks' => $json]);
    }
}
