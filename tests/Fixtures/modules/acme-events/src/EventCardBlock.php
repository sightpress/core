<?php

declare(strict_types=1);

namespace Acme\Events;

use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\StyledBlockType;

final class EventCardBlock implements StyledBlockType
{
    public function name(): string
    {
        return 'acme_events.event_card';
    }

    public function label(): string
    {
        return 'Event card';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'slug'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'title' => 'Event name'],
                'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'title' => 'Event address'],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        return sprintf('<p class="event-card"><a href="%s">%s</a></p>', htmlspecialchars($context->absoluteUrl('/events/' . self::text($data, 'slug')), ENT_QUOTES), htmlspecialchars(self::text($data, 'title'), ENT_QUOTES));
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return sprintf("[%s](%s)\n", self::text($data, 'title'), $context->absoluteUrl('/events/' . self::text($data, 'slug')));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        if (self::text($data, 'title') === '') {
            return null;
        }

        return ['@type' => 'Event', 'name' => self::text($data, 'title'), 'url' => $context->absoluteUrl('/events/' . self::text($data, 'slug'))];
    }

    public function a11yRules(): array
    {
        return [];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([new BlockVariant('plain', 'Plain'), new BlockVariant('card', 'Card')], [BlockOption::Align]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
