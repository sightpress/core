<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A section heading, level 2 to 6 (the page title is the only level 1). Whether levels skip is checked across the
 * page when it is published.
 */
final class HeadingBlock implements BlockType
{
    public function name(): string
    {
        return 'heading';
    }

    public function label(): string
    {
        return 'Heading';
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
            'required' => ['text', 'level'],
            'properties' => [
                'text' => ['title' => 'Heading text', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'level' => ['title' => 'Level', 'description' => '2 starts a main part of the page, 3 a part of that, and so on. The page title is the only level 1.', 'type' => 'integer', 'minimum' => 2, 'maximum' => 6],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $level = self::level($data);

        return sprintf('<h%d class="block-heading">%s</h%1$d>', $level, Html::e($data['text'] ?? ''));
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return str_repeat('#', self::level($data)) . ' ' . Html::markdown(Html::text($data, 'text'));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function level(array $data): int
    {
        $level = $data['level'] ?? 2;

        return is_int($level) ? max(2, min(6, $level)) : 2;
    }
}
