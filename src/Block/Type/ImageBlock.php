<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\ImageAltRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\StyledBlockType;
use SightPress\Module\Block\BlockVariant;

/**
 * A picture from the media library with its alt text (or marked as decorative) and an optional caption. The browser
 * gets every smaller copy to choose from, and the size, so the page doesn't jump while it loads.
 */
final class ImageBlock implements StyledBlockType
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
                'media' => ['title' => 'Image', 'description' => 'A picture from the media library.', 'type' => 'integer', 'format' => SchemaConstraints::FORMAT_IMAGE],
                'alt' => ['title' => 'Alternative text', 'description' => 'What the picture shows, for people who can\'t see it. Leave it empty only for a decorative picture.', 'type' => 'string', 'maxLength' => 1000],
                'decorative' => ['title' => 'The picture is only decoration: screen readers skip it', 'type' => 'boolean'],
                'caption' => ['title' => 'Caption', 'description' => 'Shown under the picture, for everyone.', 'type' => 'string', 'maxLength' => 2000],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([new BlockVariant('plain', 'Plain'), new BlockVariant('rounded', 'Rounded corners'), new BlockVariant('bordered', 'With a border')], [BlockOption::Width, BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $img = self::img($data, $context, '(max-width: 60rem) 100vw, 60rem');
        if ($img === null) {
            return '';
        }
        $caption = trim(Html::text($data, 'caption'));

        return '<figure class="block-image">' . $img
            . ($caption === '' ? '' : '<figcaption>' . Html::e($caption) . '</figcaption>')
            . '</figure>';
    }

    /**
     * The `<img>` for picture data (`media`, `alt`, `decorative`), or null when the file can't be shown. On a site,
     * only published pictures are shown, with every smaller copy and their size.
     *
     * @param array<string, mixed> $data
     */
    public static function img(array $data, BlockContext $context, string $sizes): ?string
    {
        $id = $data['media'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $image = $context instanceof SiteBlockContext ? $context->image($id) : null;
        $src = $context instanceof SiteBlockContext ? ($image['src'] ?? null) : $context->mediaUrl((string) $id);
        if ($src === null) {
            return null;
        }

        $attributes = ' src="' . Html::e($src) . '"';
        if (($image['srcset'] ?? '') !== '') {
            $attributes .= ' srcset="' . Html::e($image['srcset'] ?? '') . '" sizes="' . Html::e($sizes) . '"';
        }
        if (isset($image['width'], $image['height'])) {
            $attributes .= sprintf(' width="%d" height="%d"', $image['width'], $image['height']);
        }

        return '<img' . $attributes . ' alt="' . Html::e(self::alt($data)) . '" loading="lazy" decoding="async">';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return self::markdown($data, $context);
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return self::imageObject($data, $context);
    }

    /**
     * Markdown for picture data: the image with its alt text, and the caption.
     *
     * @param array<string, mixed> $data
     */
    public static function markdown(array $data, BlockContext $context): string
    {
        $id = $data['media'] ?? null;
        $url = is_int($id) ? $context->mediaUrl((string) $id) : null;
        if ($url === null) {
            return '';
        }
        $caption = trim(Html::text($data, 'caption'));
        $alt = self::alt($data);
        $image = $alt === '' ? '' : '![' . Html::markdown($alt) . '](' . $url . ')';

        return trim($image . ($caption === '' ? '' : "\n\n_" . Html::markdown($caption) . '_'));
    }

    /**
     * A schema.org `ImageObject` for picture data with alt text, or null.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function imageObject(array $data, BlockContext $context): ?array
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
