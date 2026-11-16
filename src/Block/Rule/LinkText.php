<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

/**
 * What makes link text useless out of context (WCAG 2.4.4): "click here", "read more", a bare address …
 */
final class LinkText
{
    private const array VAGUE = [
        'click', 'click here', 'here', 'more', 'read more', 'learn more', 'find out more', 'more info',
        'more information', 'link', 'this link', 'this', 'go', 'details', 'continue', 'see more',
    ];

    public static function isVague(string $text): bool
    {
        if (preg_match('~^(https?://|www\.)~i', trim($text)) === 1) {
            return true;
        }

        return in_array(mb_strtolower(trim((string) preg_replace('/[\s\p{P}]+/u', ' ', $text))), self::VAGUE, true);
    }
}
