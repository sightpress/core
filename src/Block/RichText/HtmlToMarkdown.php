<?php

declare(strict_types=1);

namespace SightPress\Core\Block\RichText;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Turns sanitised rich text into Markdown for readers that prefer text: headings, paragraphs, emphasis, links
 * (made absolute), lists, quotes and code. Elements Markdown has no syntax for keep their text.
 */
final readonly class HtmlToMarkdown
{
    /**
     * @param Closure(string): string $absoluteUrl turns a link's `href` into an absolute URL
     */
    public function __construct(private Closure $absoluteUrl)
    {
    }

    public function convert(string $html): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $body = $document->body;

        return $body === null ? '' : trim((string) preg_replace("/\n{3,}/", "\n\n", $this->blocks($body)));
    }

    private function blocks(Node $parent, string $indent = ''): string
    {
        $out = '';
        $inline = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof Element && $this->isBlock($node)) {
                $out .= $this->flush($inline) . $this->block($node, $indent);
                $inline = '';
            } else {
                $inline .= $this->inline($node);
            }
        }

        return $out . $this->flush($inline);
    }

    private function flush(string $inline): string
    {
        $inline = trim($inline);

        return $inline === '' ? '' : $inline . "\n\n";
    }

    private function isBlock(Element $element): bool
    {
        return in_array($element->localName, ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'dl'], true);
    }

    private function block(Element $element, string $indent): string
    {
        $name = $element->localName;

        return match ($name) {
            'h2', 'h3', 'h4', 'h5', 'h6' => str_repeat('#', (int) $name[1]) . ' ' . $this->inlineChildren($element) . "\n\n",
            'ul', 'ol' => $this->listItems($element, $indent) . "\n",
            'blockquote' => implode("\n", array_map(
                static fn (string $line): string => rtrim('> ' . $line),
                explode("\n", trim($this->blocks($element))),
            )) . "\n\n",
            'pre' => "```\n" . trim((string) $element->textContent, "\n") . "\n```\n\n",
            'hr' => "---\n\n",
            'dl' => $this->definitions($element),
            default => $this->inlineChildren($element) . "\n\n",
        };
    }

    private function listItems(Element $list, string $indent): string
    {
        $ordered = $list->localName === 'ol';
        $number = $ordered ? max(1, (int) ($list->getAttribute('start') ?? 1)) : 1;
        $out = '';
        foreach ($list->children as $item) {
            if ($item->localName !== 'li') {
                continue;
            }
            $marker = $ordered ? $number++ . '. ' : '- ';
            $inline = '';
            $nested = '';
            foreach ($item->childNodes as $node) {
                if ($node instanceof Element && in_array($node->localName, ['ul', 'ol'], true)) {
                    $nested .= $this->listItems($node, $indent . str_repeat(' ', strlen($marker)));
                } else {
                    $inline .= $this->inline($node);
                }
            }
            $out .= $indent . $marker . trim($inline) . "\n" . $nested;
        }

        return $out;
    }

    private function definitions(Element $list): string
    {
        $out = '';
        foreach ($list->children as $item) {
            $text = $this->inlineChildren($item);
            $out .= $item->localName === 'dt' ? '**' . $text . "**\n" : ': ' . $text . "\n";
        }

        return $out . "\n";
    }

    private function inlineChildren(Node $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $node) {
            $out .= $this->inline($node);
        }

        return trim((string) preg_replace('/[ \t]+/', ' ', $out));
    }

    private function inline(Node $node): string
    {
        if ($node instanceof Text) {
            return self::escape((string) preg_replace('/\s+/', ' ', $node->data));
        }
        if (!$node instanceof Element) {
            return '';
        }
        $text = $this->inlineChildren($node);

        return match ($node->localName) {
            'strong', 'b' => $text === '' ? '' : '**' . $text . '**',
            'em', 'i', 'cite' => $text === '' ? '' : '_' . $text . '_',
            's' => $text === '' ? '' : '~~' . $text . '~~',
            'code', 'kbd' => '`' . str_replace('`', '\`', (string) $node->textContent) . '`',
            'br' => "\\\n",
            'q' => '"' . $text . '"',
            'a' => $this->link($node, $text),
            default => $text,
        };
    }

    private function link(Element $link, string $text): string
    {
        $href = $link->getAttribute('href');
        if ($href === null || $href === '') {
            return $text;
        }

        return '[' . $text . '](' . str_replace([' ', ')'], ['%20', '%29'], ($this->absoluteUrl)($href)) . ')';
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>]|^#)/', '\\\\$1', $text);
    }
}
