<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Block\Contract\BlockSchema;

/**
 * Which control the page editor shows for one field of a block's JSON Schema.
 */
enum ControlKind: string
{
    case Text = 'text';
    case Email = 'email';
    case Textarea = 'textarea';
    case Html = 'html';
    case Number = 'number';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Media = 'media';

    /** A list of objects, each in its own fieldset (the pictures of a gallery, the columns). */
    case Items = 'items';

    /** A list of blocks inside a block (the blocks of a column). */
    case Blocks = 'blocks';

    /** Longer text than this gets a text area. */
    public const int TEXTAREA_FROM = 300;

    /** Whole numbers with at most this many choices get a select. */
    private const int MAX_NUMBER_CHOICES = 20;

    /**
     * @param array<string, mixed> $schema
     */
    public static function of(array $schema): self
    {
        $type = $schema['type'] ?? null;
        $format = $schema['format'] ?? null;
        $minimum = $schema['minimum'] ?? null;
        $maximum = $schema['maximum'] ?? null;
        $maxLength = $schema['maxLength'] ?? null;

        return match (true) {
            in_array($format, BlockSchema::MEDIA_FORMATS, true) => self::Media,
            ($schema['contentMediaType'] ?? null) === BlockSchema::HTML => self::Html,
            isset($schema['enum']) => self::Select,
            $type === 'boolean' => self::Checkbox,
            $type === 'array' => self::isBlockList($schema) ? self::Blocks : self::Items,
            $type === 'integer' && is_int($minimum) && is_int($maximum) && $maximum - $minimum < self::MAX_NUMBER_CHOICES => self::Select,
            $type === 'integer' || $type === 'number' => self::Number,
            $format === BlockSchema::FORMAT_EMAIL => self::Email,
            is_int($maxLength) && $maxLength > self::TEXTAREA_FROM => self::Textarea,
            default => self::Text,
        };
    }

    /**
     * The choices of a select: the enum's values, or the whole numbers from minimum to maximum.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<int|string>
     */
    public static function choices(array $schema): array
    {
        $enum = $schema['enum'] ?? null;
        if (is_array($enum)) {
            return array_values(array_filter($enum, static fn (mixed $value): bool => is_int($value) || is_string($value)));
        }
        $minimum = $schema['minimum'] ?? 0;
        $maximum = $schema['maximum'] ?? 0;

        return is_int($minimum) && is_int($maximum) && $maximum >= $minimum ? range($minimum, $maximum) : [];
    }

    /**
     * An array whose items are bare objects holds blocks (`{"type": "object"}`, as in the columns block).
     *
     * @param array<string, mixed> $schema
     */
    private static function isBlockList(array $schema): bool
    {
        $items = $schema['items'] ?? null;

        return is_array($items) && !isset($items['properties']) && ($items['type'] ?? null) === 'object';
    }
}
