<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Block\Contract\RichText;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * `rewrite`: the same text made simpler, shorter, friendlier or more formal. Text with HTML tags (a rich text
 * block's) comes back as HTML through the rich text sanitizer, so only the tags a rich text block allows remain;
 * other text comes back as plain text.
 */
final readonly class RewriteTask implements AssistantTask
{
    public const string ID = 'rewrite';

    public const int MAX_TEXT = 10000;

    /** Each style => what the text should become, for the prompt. */
    public const array STYLES = [
        'simpler' => 'simpler and easier to read: short sentences and everyday words',
        'shorter' => 'shorter: about half as long, keeping what matters most',
        'friendlier' => 'friendlier and warmer, speaking to the reader as "you"',
        'formal' => 'more formal and polite',
    ];

    public function __construct(
        private Prompts $prompts,
        private RichText $richText,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Rewrite text';
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
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TEXT],
                'style' => ['type' => 'string', 'enum' => array_keys(self::STYLES)],
            ],
            'required' => ['text', 'style'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $text = is_string($input['text'] ?? null) ? $input['text'] : '';
        $style = is_string($input['style'] ?? null) ? $input['style'] : 'simpler';

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), [
                'style' => self::STYLES[$style] ?? self::STYLES['simpler'],
                'format' => self::isHtml($text)
                    ? 'The text is HTML: answer with HTML too, using only <p>, <h2> to <h6>, <ul>, <ol>, <li>, <a href="…">, <strong> and <em>.'
                    : 'Write plain text: no Markdown, no HTML.',
            ])),
            AssistantMessage::user(PromptData::block('text', $text) . "\n\nRewrite the text above."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $text = is_string($input['text'] ?? null) ? $input['text'] : '';
        if (!self::isHtml($text)) {
            return new AssistantSuggestion(PlainText::of($response->text, self::MAX_TEXT));
        }
        $html = trim($this->richText->clean(CodeFence::strip($response->text)));
        PlainText::of($html, self::MAX_TEXT * 2);

        return new AssistantSuggestion($html, ['html' => $html]);
    }

    public static function isHtml(string $text): bool
    {
        return preg_match('#<(p|h[2-6]|ul|ol|li|a|strong|em)\b[^>]*>#i', $text) === 1;
    }
}
