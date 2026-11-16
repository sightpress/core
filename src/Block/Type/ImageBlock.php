<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\ImageAltRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A picture from the media library with its alt text (or marked as decorative) and an optional caption. The browser
 * gets every smaller copy to choose from, and the size, so the page doesn't jump while it loads.
 */
final class ImageBlock implements BlockType
{
    public function name(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Image';
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
            'required' => ['media'],
            'properties' => [
                'media' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_IMAGE],
                'alt' => ['type' => 'string', 'maxLength' => 1000],
                'decorative' => ['type' => 'boolean'],
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
        $id = $data['media'] ?? null;
        if (!is_int($id)) {
            return '';
        }
        $image = $context instanceof SiteBlockContext ? $context->image($id) : null;
        $src = $context instanceof SiteBlockContext ? ($image['src'] ?? null) : $context->mediaUrl((string) $id);
        if ($src === null) {
            return '';
        }

        $attributes = ' src="' . Html::e($src) . '"';
        if (($image['srcset'] ?? '') !== '') {
            $attributes .= ' srcset="' . Html::e($image['srcset'] ?? '') . '" sizes="(max-width: 60rem) 100vw, 60rem"';
        }
        if (isset($image['width'], $image['height'])) {
            $attributes .= sprintf(' width="%d" height="%d"', $image['width'], $image['height']);
        }
        $attributes .= ' alt="' . Html::e(self::alt($data)) . '" loading="lazy" decoding="async"';
        $caption = trim(Html::text($data, 'caption'));

        return '<figure class="block-image"><img' . $attributes . '>'
            . ($caption === '' ? '' : '<figcaption>' . Html::e($caption) . '</figcaption>')
            . '</figure>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $id = $data['media'] ?? null;
        $url = is_int($id) ? $context->mediaUrl((string) $id) : null;
        if ($url === null) {
            return '';
        }
        $caption = trim(Html::text($data, 'caption'));
        $alt = self::alt($data);
        if ($alt === '' && $caption === '') {
            return '';
        }
        $image = $alt === '' ? '' : '![' . Html::markdown($alt) . '](' . $url . ')';

        return trim($image . ($caption === '' ? '' : "\n\n_" . Html::markdown($caption) . '_'));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $id = $data['media'] ?? null;
        $url = is_int($id) ? $context->mediaUrl((string) $id) : null;
        $alt = self::alt($data);
        if ($url === null || $alt === '') {
            return null;
        }
        $node = ['@type' => 'ImageObject', 'contentUrl' => $url, 'description' => $alt];
        $caption = trim(Html::text($data, 'caption'));
        if ($caption !== '') {
            $node['caption'] = $caption;
        }

        return $node;
    }

    public function a11yRules(): array
    {
        return [new ImageAltRule()];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function alt(array $data): string
    {
        return ($data['decorative'] ?? false) === true ? '' : trim(Html::text($data, 'alt'));
    }
}
