<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use Dom\HTMLDocument;
use SightPress\Core\Block\Html;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;

/**
 * Every link in rich text has text (WCAG 2.4.4, 4.1.2), and that text says where it goes.
 */
final class RichTextLinksRule implements A11yRule
{
    public function check(array $data): array
    {
        $html = Html::text($data, 'html');
        if (!str_contains($html, '<a')) {
            return [];
        }
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $empty = 0;
        $vague = [];
        foreach ($document->querySelectorAll('a[href]') as $link) {
            $text = trim((string) $link->textContent);
            if ($text === '') {
                ++$empty;
            } elseif (LinkText::isVague($text)) {
                $vague[] = $text;
            }
        }

        $issues = [];
        if ($empty > 0) {
            $issues[] = new A11yIssue('rich_text.link_empty', sprintf('%d %s no text. Give every link text that says where it goes.', $empty, $empty === 1 ? 'link has' : 'links have'), A11ySeverity::Error, '/html', ['2.4.4', '4.1.2']);
        }
        if ($vague !== []) {
            $issues[] = new A11yIssue('rich_text.link_vague', sprintf('Say where these links go: "%s".', implode('", "', array_unique($vague))), A11ySeverity::Warning, '/html', ['2.4.4']);
        }

        return $issues;
    }
}
