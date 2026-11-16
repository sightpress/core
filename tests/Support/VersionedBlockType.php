<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A third-party block type at version 2: version 1 stored `text`, version 2 stores `label`. Renders the label,
 * adds a schema.org `Thing`, and warns when the label is "TODO".
 */
final class VersionedBlockType implements BlockType, A11yRule
{
    public function name(): string
    {
        return 'acme.badge';
    }

    public function label(): string
    {
        return 'Badge';
    }

    public function version(): int
    {
        return 2;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['label'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                'icon' => ['type' => 'integer', 'format' => 'media'],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return ['label' => $data['text'] ?? ''];
    }

    public function render(array $data, BlockContext $context): string
    {
        return '<span class="badge">' . htmlspecialchars(is_string($data['label'] ?? null) ? $data['label'] : '') . '</span>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return '**' . (is_string($data['label'] ?? null) ? $data['label'] : '') . '**';
    }

    public function structuredData(array $data, BlockContext $context): array
    {
        return ['@type' => 'Thing', 'name' => $data['label'] ?? ''];
    }

    public function a11yRules(): array
    {
        return [$this];
    }

    public function check(array $data): array
    {
        return ($data['label'] ?? '') === 'TODO' ? [new A11yIssue('acme.badge_todo', 'Finish the badge.', A11ySeverity::Warning, '/label')] : [];
    }
}
