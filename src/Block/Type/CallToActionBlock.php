<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\StyledBlockType;

/**
 * A link styled as a button that tells the reader what to do next ("Book a visit").
 */
final class CallToActionBlock implements StyledBlockType
{
    public function name(): string
    {
        return 'call_to_action';
    }

    public function label(): string
    {
        return 'Call to action';
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
            'required' => ['text', 'url'],
            'properties' => [
                'text' => ['title' => 'Button text', 'description' => 'Say what happens, for example "Book a visit".', 'type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'url' => ['title' => 'Link address', 'description' => 'A page of this site, such as /contact, or a full address such as https://example.org.', 'type' => 'string', 'maxLength' => 2000, 'format' => SchemaConstraints::FORMAT_LINK],
                'style' => ['title' => 'Style', 'description' => 'Primary for the main action of the page, secondary for others.', 'enum' => ['primary', 'secondary']],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([], [BlockOption::Align, BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $class = ($data['style'] ?? 'primary') === 'secondary' ? 'button button--secondary' : 'button';

        return sprintf(
            '<p class="block-call-to-action"><a class="%s" href="%s">%s</a></p>',
            $class,
            Html::e(Html::text($data, 'url')),
            Html::e(Html::text($data, 'text')),
        );
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $url = Html::text($data, 'url');
        if ($context instanceof SiteBlockContext) {
            $url = $context->linkUrl($url);
        }

        return '[' . Html::markdown(Html::text($data, 'text')) . '](' . str_replace([' ', ')'], ['%20', '%29'], $url) . ')';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [new LinkTextRule('call_to_action.text_vague', 'text')];
    }
}
