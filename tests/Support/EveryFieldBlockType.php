<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A block type for page editor tests with one field of every kind the editor knows: a required flag, a whole
 * number, a decimal number, numbers and words to choose from, an email address and a list of items with a list of
 * items inside.
 */
final class EveryFieldBlockType implements BlockType
{
    public function name(): string
    {
        return 'acme.every_field';
    }

    public function label(): string
    {
        return 'Every field';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['agreed', 'size'],
            'properties' => [
                'agreed' => ['type' => 'boolean'],
                'count' => ['type' => 'integer'],
                'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                'ratio' => ['type' => 'number'],
                'colour' => ['enum' => ['red', 'blue', 7]],
                'contact' => ['type' => 'string', 'format' => 'email'],
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cells' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        return '';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return '';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }
}
