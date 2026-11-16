<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Publish;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Locale;
use SightPress\Module\Block\A11ySeverity;

/**
 * Checks the HTML a block renders to, whatever type it is: headings (the page title is the only `h1`, levels don't
 * skip), pictures without `alt`, language codes (`lang`) and the contrast of colours set in the content, against the
 * theme's colours in every colour mode.
 */
final class HtmlChecks
{
    /**
     * @param int                                                    $heading level of the heading before this block
     *                                                                        (1 for the page title); updated
     * @param array<string, array{text: string, background: string}> $modes   theme colours by colour mode name
     *
     * @return list<array{rule: string, message: string, severity: A11ySeverity, wcag: list<string>}>
     */
    public static function check(string $html, int &$heading, array $modes): array
    {
        if (trim($html) === '') {
            return [];
        }
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $issues = [];
        foreach ($document->querySelectorAll('h1, h2, h3, h4, h5, h6, img:not([alt]), [lang]') as $element) {
            $issues = [...$issues, ...self::element($element, $heading)];
        }
        $seen = [];
        if ($document->body !== null) {
            self::colours($document->body, null, null, $modes, $issues, $seen);
        }

        return $issues;
    }

    /**
     * @return list<array{rule: string, message: string, severity: A11ySeverity, wcag: list<string>}>
     */
    private static function element(Element $element, int &$heading): array
    {
        $issues = [];
        $name = $element->localName;
        if (preg_match('/^h([1-6])$/', $name, $match) === 1) {
            $level = (int) $match[1];
            if ($level === 1) {
                $issues[] = self::issue('page.heading_h1', 'Only the page title is a level 1 heading. Use level 2 here.', ['1.3.1']);
            } elseif ($level > $heading + 1) {
                $issues[] = self::issue('page.heading_skipped', sprintf(
                    'This heading jumps from level %d to level %d. Use level %d, or add the missing level before it.',
                    $heading,
                    $level,
                    $heading + 1,
                ), ['1.3.1']);
            }
            $heading = $level;
        }
        if ($name === 'img' && !$element->hasAttribute('alt')) {
            $issues[] = self::issue('page.image_alt', 'A picture has no alt text. Describe it, or mark it as decorative.', ['1.1.1']);
        }
        $lang = $element->getAttribute('lang');
        if ($lang !== null && !self::isLanguage($lang)) {
            $issues[] = self::issue('page.lang_invalid', sprintf('"%s" is not a language code. Use a code such as "fr" or "pt-BR".', $lang), ['3.1.2']);
        }
        return $issues;
    }

    public static function isLanguage(string $tag): bool
    {
        if (preg_match('/^([a-zA-Z]{2,3})(-[a-zA-Z0-9]{2,8})*$/', $tag, $match) !== 1) {
            return false;
        }
        $primary = strtolower($match[1]);
        $name = Locale::getDisplayLanguage($primary, 'en');

        return is_string($name) && $name !== '' && strtolower($name) !== $primary;
    }

    /**
     * Walks the text with the colours it inherits from `style` attributes; each text colour on a background is
     * checked once.
     *
     * @param array<string, array{text: string, background: string}>                  $modes
     * @param list<array{rule: string, message: string, severity: A11ySeverity, wcag: list<string>}> $issues
     * @param array<string, true>                                                        $seen
     */
    private static function colours(Element $element, ?string $text, ?string $background, array $modes, array &$issues, array &$seen): void
    {
        $text = self::colour($element, 'color') ?? $text;
        $background = self::colour($element, 'background-color') ?? $background;
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                self::colours($child, $text, $background, $modes, $issues, $seen);
            } elseif ($child instanceof Text && trim($child->data) !== '' && ($text !== null || $background !== null) && !isset($seen[$text . '|' . $background])) {
                $seen[$text . '|' . $background] = true;
                $issue = self::contrast($text, $background, $modes);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }
    }

    /**
     * @param array<string, array{text: string, background: string}> $modes
     *
     * @return array{rule: string, message: string, severity: A11ySeverity, wcag: list<string>}|null
     */
    private static function contrast(?string $text, ?string $background, array $modes): ?array
    {
        $worst = null;
        foreach ($modes as $mode => $colours) {
            $foreground = $text ?? $colours['text'];
            $behind = $background ?? $colours['background'];
            $ratio = Contrast::ratio($foreground, $behind);
            if ($ratio < Contrast::TEXT && ($worst === null || $ratio < $worst[0])) {
                $worst = [$ratio, $foreground, $behind, $text !== null && $background !== null ? null : $mode];
            }
        }
        if ($worst === null) {
            return null;
        }
        [$ratio, $foreground, $behind, $mode] = $worst;

        return self::issue('page.contrast', sprintf(
            'The text colour %s on %s has a contrast of %s:1%s; it needs at least 4.5:1. Choose another colour, add a background colour that goes with it, or remove it.',
            $foreground,
            $behind,
            number_format(floor($ratio * 100) / 100, 2),
            $mode === null ? '' : ' in ' . $mode,
        ), ['1.4.3']);
    }

    /**
     * A colour property set in the element's own `style`.
     */
    private static function colour(Element $element, string $property): ?string
    {
        foreach (explode(';', (string) $element->getAttribute('style')) as $declaration) {
            [$name, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $colour = strtolower(trim($name)) === $property ? Contrast::normalise($value) : null;
            if ($colour !== null) {
                return $colour;
            }
        }

        return null;
    }

    /**
     * @param list<string> $wcag
     *
     * @return array{rule: string, message: string, severity: A11ySeverity, wcag: list<string>}
     */
    private static function issue(string $rule, string $message, array $wcag): array
    {
        return ['rule' => $rule, 'message' => $message, 'severity' => A11ySeverity::Error, 'wcag' => $wcag];
    }
}
