<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

use SightPress\Core\Page\Contract\PublicPageRendering;

/**
 * The preview page's opening band: on the home page of the throwaway preview site, the `hero` block of the theme's
 * home layout gets the Download button, under the page's `<h1>` (the theme's name) and lead (its description). Its
 * accessible name starts with its visible label (WCAG 2.5.3). Uses the markup the planned `hero` block will have,
 * which replaces it when it lands.
 */
final readonly class PreviewHero
{
    /** The template variable that holds the HTML (`@page/public.html.twig` prints it in the layout's `hero` block). */
    public const string VARIABLE = 'hero';

    /**
     * @param array{label: string, accessible_name: string, url: string} $button
     */
    public function __construct(private array $button)
    {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        if ($event->home) {
            $event->set(self::VARIABLE, $this->html());
        }
    }

    public function html(): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return sprintf(
            '<p class="hero__actions"><a class="button" href="%s" aria-label="%s">%s</a></p>',
            $e($this->button['url']),
            $e($this->button['accessible_name']),
            $e($this->button['label']),
        );
    }
}
