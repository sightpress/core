<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use SightPress\Core\Template\Message;
use SightPress\Core\Template\TextDirection;
use SightPress\Core\Template\Translations;

/**
 * Renders an {@see ErrorPage} as a complete, accessible HTML page (WCAG 2.2 AA): `lang`, a unique `<title>`, one
 * `<main>` landmark, one `<h1>`, system colours that follow light and dark mode, and a visible focus outline.
 *
 * It needs no template engine or site, so it still works when those fail. Its only inline style is allowed by a
 * hash in {@see contentSecurityPolicy()}.
 */
final readonly class HtmlErrorRenderer
{
    private const string STYLE = <<<'CSS'
        :root { color-scheme: light dark; }
        body { margin: 0; font-family: system-ui, sans-serif; font-size: 1.125rem; line-height: 1.6;
            background: Canvas; color: CanvasText; }
        main { max-width: 40rem; margin: 0 auto; padding: 3rem 1.5rem; }
        h1 { font-size: 2rem; line-height: 1.25; margin: 0 0 1rem; }
        a { color: LinkText; text-underline-offset: 0.2em; }
        a:focus-visible, summary:focus-visible { outline: 3px solid CanvasText; outline-offset: 3px; }
        pre { overflow-x: auto; white-space: pre-wrap; font-size: 0.875rem; }
        CSS;

    public function __construct(private string $applicationName = 'SightPress')
    {
    }

    /**
     * @param string|null       $details      technical details (debug mode only); shown in a disclosure
     * @param Translations|null $translations the page is in `$locale` when they are given, in en-GB otherwise
     */
    public function render(ErrorPage $page, ?string $details = null, ?Translations $translations = null, string $locale = Translations::SOURCE_LOCALE): string
    {
        $locale = $translations === null ? Translations::SOURCE_LOCALE : $locale;
        $t = static fn (string|Message $text): string => $translations === null ? (string) Message::of($text) : $translations->translate($text, [], $locale);
        $title = $this->escape($t($page->title));
        $paragraphs = '';
        foreach ($page->paragraphs as $paragraph) {
            $paragraphs .= '<p>' . $this->escape($t($paragraph)) . "</p>\n";
        }
        $paragraphs .= '<p>' . $this->escape($t(new Message('Error code: {code}', ['code' => (string) $page->status]))) . "</p>\n";
        if ($page->homeLink) {
            $paragraphs .= '<p><a href="/">' . $this->escape($t(new Message('Go to the home page'))) . "</a></p>\n";
        }
        foreach ($page->links as $href => $text) {
            $paragraphs .= '<p><a href="' . $this->escape($href) . '">' . $this->escape($t($text)) . "</a></p>\n";
        }
        if ($details !== null && $details !== '') {
            $paragraphs .= '<details>' . "\n<summary>" . $this->escape($t(new Message('Technical details'))) . "</summary>\n<pre>"
                . $this->escape($details) . "</pre>\n</details>\n";
        }
        $lang = $this->escape($locale);
        $dir = TextDirection::of($locale);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$lang}" dir="{$dir}">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$title} - {$this->escape($this->applicationName)}</title>
            <style>{$this->style()}</style>
            </head>
            <body>
            <main>
            <h1>{$title}</h1>
            {$paragraphs}</main>
            </body>
            </html>

            HTML;
    }

    /**
     * A strict policy for error pages: nothing may load, and only this page's own style may apply.
     */
    public function contentSecurityPolicy(): string
    {
        return sprintf(
            "default-src 'none'; style-src '%s'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'sha256-' . base64_encode(hash('sha256', $this->style(), true)),
        );
    }

    private function style(): string
    {
        return self::STYLE;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
