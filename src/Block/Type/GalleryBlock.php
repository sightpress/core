<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\GalleryAltRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * Several pictures shown together as a list, each with its own alt text (or marked as decorative) and caption, and
 * an optional caption for the whole gallery. The pictures wrap into as many columns as fit, in reading order.
 */
final class GalleryBlock implements BlockType
{
    public const int MAX_IMAGES = 50;

    public function name(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Gallery';
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
            'required' => ['images'],
            'properties' => [
                'images' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_IMAGES,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['media'],
                        'properties' => [
                            'media' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_IMAGE],
                            'alt' => ['type' => 'string', 'maxLength' => 1000],
                            'decorative' => ['type' => 'boolean'],
                            'caption' => ['type' => 'string', 'maxLength' => 2000],
                        ],
                    ],
                ],
                'caption' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $items = '';
        foreach (self::images($data) as $image) {
            $img = ImageBlock::img($image, $context, '(max-width: 40rem) 100vw, 20rem');
            if ($img === null) {
                continue;
            }
            $caption = trim(Html::text($image, 'caption'));
            $items .= '<li><figure>' . $img . ($caption === '' ? '' : '<figcaption>' . Html::e($caption) . '</figcaption>') . '</figure></li>';
        }
        if ($items === '') {
            return '';
        }
        $list = '<ul class="block-gallery__items" role="list">' . $items . '</ul>';
        $caption = trim(Html::text($data, 'caption'));

        return $caption === ''
            ? '<div class="block-gallery">' . $list . '</div>'
            : '<figure class="block-gallery">' . $list . '<figcaption>' . Html::e($caption) . '</figcaption></figure>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $parts = array_map(static fn (array $image): string => ImageBlock::markdown($image, $context), self::images($data));
        $caption = trim(Html::text($data, 'caption'));
        if ($caption !== '') {
            $parts[] = '_' . Html::markdown($caption) . '_';
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $images = [];
        foreach (self::images($data) as $image) {
            $node = ImageBlock::imageObject($image, $context);
            if ($node !== null) {
                $images[] = $node;
            }
        }
        if ($images === []) {
            return null;
        }
        $node = ['@type' => 'ImageGallery', 'image' => $images];
        $caption = trim(Html::text($data, 'caption'));
        if ($caption !== '') {
            $node['description'] = $caption;
        }

        return $node;
    }

    public function a11yRules(): array
    {
        return [new GalleryAltRule()];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function images(array $data): array
    {
        $images = [];
        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $image) {
            if (is_array($image)) {
                /** @var array<string, mixed> $image */
                $images[] = $image;
            }
        }

        return $images;
    }
}
