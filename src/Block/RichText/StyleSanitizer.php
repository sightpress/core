<?php

declare(strict_types=1);

namespace SightPress\Core\Block\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps only text and background colours in `style` attributes of rich text (`color: #1a1a1a;
 * background-color: #ffeb3b`), written as hex, so the publish check can test their contrast. Anything else in a
 * style is dropped; a style with nothing left is removed.
 */
final class StyleSanitizer implements AttributeSanitizerInterface
{
    private const string DECLARATION = '/^\s*(color|background-color)\s*:\s*(#[0-9a-f]{3}|#[0-9a-f]{6})\s*$/i';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            if (preg_match(self::DECLARATION, $declaration, $match) === 1) {
                $kept[strtolower($match[1])] = strtolower($match[2]);
            }
        }
        if ($kept === []) {
            return null;
        }
        ksort($kept);

        return implode('; ', array_map(static fn (string $property, string $colour): string => $property . ': ' . $colour, array_keys($kept), $kept));
    }
}
