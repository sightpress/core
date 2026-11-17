<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\RichText\HtmlToMarkdown;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\Rule\RichTextLinksRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * Formatted text: paragraphs, lists, links, emphasis, quotes and subheadings. The HTML is cleaned with an
 * allow-list when it is saved ({@see RichTextSanitizer}), so it is output as it is.
 */
final class RichTextBlock implements BlockType
{
    public function name(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Text';
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
            'required' => ['html'],
            'properties' => [
                'html' => [
                    'title' => 'Text', 'description' => 'Written as HTML: paragraphs <p>, headings <h2> to <h6>, lists <ul>, <ol> and <li>, links <a href="…">, <strong> and <em>. Anything else is removed when you save.',
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => RichTextSanitizer::MAX_LENGTH,
                    'contentMediaType' => SchemaConstraints::HTML,
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
        $html = Html::text($data, 'html');

        return $html === '' ? '' : '<div class="block-rich-text">' . $html . '</div>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $converter = new HtmlToMarkdown(
            static fn (string $href): string => $context instanceof SiteBlockContext ? $context->linkUrl($href) : $href,
        );

        return $converter->convert(Html::text($data, 'html'));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [new RichTextLinksRule()];
    }
}
