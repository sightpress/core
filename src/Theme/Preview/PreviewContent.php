<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

use JsonException;
use SightPress\Core\Theme\ThemeException;
use SightPress\Theme\Preview\PreviewPage;

/**
 * The preview page of `theme-interface` (`page.json`) filled in for one theme: the preview variables replaced by the
 * theme's values, the media roles (`@picture` …) by the ids of core's sample files, and each section turned into a
 * level-2 heading followed by its blocks, ready to be saved as a page.
 */
final readonly class PreviewContent
{
    /**
     * @param non-empty-string                                                     $siteName
     * @param non-empty-string                                                     $tagline
     * @param array{label: string, accessible_name: string, url: string}          $button   the hero's Download button
     * @param list<array{id: non-empty-string, heading: non-empty-string}>         $sections in order
     * @param list<array<string, mixed>>                                           $blocks   the page's blocks
     */
    public function __construct(
        public string $siteName,
        public string $tagline,
        public array $button,
        public array $sections,
        public array $blocks,
    ) {
    }

    /**
     * @param array<string, int> $media media id by role ({@see PreviewPage::MEDIA})
     *
     * @throws ThemeException when the page can't be read or names a variable or a role core doesn't know
     */
    public static function build(PreviewTheme $theme, array $media, ?string $file = null): self
    {
        $file ??= PreviewPage::file();
        $json = is_file($file) ? (string) file_get_contents($file) : '';
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException(sprintf('The preview page %s can\'t be read: %s.', $file, $exception->getMessage()), 0, $exception);
        }
        /** @var array{site: array{name: non-empty-string, tagline: non-empty-string}, hero: array{button: array{label: string, accessible_name: string, url: string}}, sections: list<array{id: non-empty-string, heading: non-empty-string, blocks: list<array<string, mixed>>}>} $page */
        $page = self::fill(is_array($data) ? $data : [], $theme, $media);

        $sections = [];
        $blocks = [];
        foreach ($page['sections'] as $section) {
            $sections[] = ['id' => $section['id'], 'heading' => $section['heading']];
            $blocks[] = ['type' => 'heading', 'version' => 1, 'data' => ['text' => $section['heading'], 'level' => 2]];
            array_push($blocks, ...$section['blocks']);
        }

        return new self($page['site']['name'], $page['site']['tagline'], $page['hero']['button'], $sections, $blocks);
    }

    /**
     * @param array<string, int> $media
     */
    private static function fill(mixed $value, PreviewTheme $theme, array $media): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::fill($item, $theme, $media), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^@([a-z_]+)$/', $value, $role) === 1) {
            return $media[$role[1]] ?? throw new ThemeException(sprintf('The preview page names the sample file "@%s", which core doesn\'t have.', $role[1]));
        }

        return (string) preg_replace_callback(
            '/\{\{\s*theme\.([a-z_]+)\s*\}\}/',
            static fn (array $match): string => $theme->variable($match[1]),
            $value,
        );
    }
}
