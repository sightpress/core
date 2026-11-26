<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Contract\EmbedProviders;
use SightPress\Core\Block\Contract\HttpBlockContext;
use SightPress\Core\Block\Html;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\StyledBlockType;

/**
 * A video or map from an allow-listed provider ({@see EmbedProviders}: YouTube, Vimeo, OpenStreetMap) with a title
 * that names it. Nothing from the provider is loaded until the reader asks for it: the page shows a notice that says
 * who the provider is and what showing it shares, a button that loads the frame (with JavaScript) and a link to the
 * original, which also works without JavaScript. Markdown and feeds get the link.
 */
final readonly class EmbedBlock implements StyledBlockType
{
    public function __construct(private TemplateRenderer $templates)
    {
    }

    public function name(): string
    {
        return 'embed';
    }

    public function label(): string
    {
        return 'Video or map from another site';
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
            'required' => ['url', 'title'],
            'properties' => [
                'url' => ['title' => 'Link to the video or map', 'description' => 'A YouTube or Vimeo video, or an OpenStreetMap map, for example https://www.youtube.com/watch?v=… Nothing is loaded from it until the reader asks.', 'type' => 'string', 'maxLength' => 2000, 'pattern' => EmbedProviders::URL_PATTERN],
                'title' => ['title' => 'Title', 'description' => 'Says what it shows, for screen readers and for the link, for example "Map of the library".', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([], [BlockOption::Width, BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $url = Html::text($data, 'url');
        $title = Html::text($data, 'title');
        $embed = EmbedProviders::resolve($url);
        if ($embed === null || $title === '') {
            return '';
        }
        [$label, $kind, $privacy] = EmbedProviders::PROVIDERS[$embed['provider']];
        $request = $context instanceof HttpBlockContext ? $context->request() : null;
        if ($request === null) {
            return '<p class="block-embed"><a href="' . Html::e($url) . '">' . Html::e($title) . '</a> (' . $label . ')</p>';
        }

        return $this->templates->render($request, '@block/embed.html.twig', [
            'provider' => $embed['provider'],
            'label' => $label,
            'kind' => $kind,
            'privacy' => $privacy,
            'src' => $embed['src'],
            'url' => $url,
            'title' => $title,
        ]);
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $url = Html::text($data, 'url');
        $embed = EmbedProviders::resolve($url);

        return $embed === null ? '' : '[' . Html::markdown(Html::text($data, 'title')) . '](' . str_replace([' ', ')'], ['%20', '%29'], $url) . ') (' . EmbedProviders::PROVIDERS[$embed['provider']][0] . ')';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }
}
