<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

/**
 * What the Suggest buttons send about the saved page (ADR-0041): the field to suggest, the title, the summary and
 * the blocks as Markdown, as an input schema and as `<data>` blocks for a prompt.
 */
final class PageInput
{
    public const int MAX_TEXT = 20000;

    /**
     * @param list<string> $fields the fields the task suggests
     *
     * @return array<string, mixed>
     */
    public static function schema(array $fields): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => $fields],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'summary' => ['type' => 'string', 'maxLength' => 300],
                'text' => ['type' => 'string', 'maxLength' => self::MAX_TEXT],
            ],
            'required' => ['field', 'title'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function data(array $input): string
    {
        $text = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        $blocks = [PromptData::block('title', $text('title'))];
        if ($text('summary') !== '') {
            $blocks[] = PromptData::block('summary', $text('summary'));
        }
        $blocks[] = PromptData::block('page', $text('text') === '' ? '(The page has no text yet.)' : $text('text'));

        return implode("\n\n", $blocks);
    }
}
