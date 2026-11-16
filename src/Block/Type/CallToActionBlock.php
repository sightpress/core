<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A link styled as a button that tells the reader what to do next ("Book a visit").
 */
final class CallToActionBlock implements BlockType
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
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'url' => ['type' => 'string', 'maxLength' => 2000, 'format' => SchemaConstraints::FORMAT_LINK],
                'style' => ['enum' => ['primary', 'secondary']],
            ],
        ];
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
