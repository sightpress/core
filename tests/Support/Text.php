<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;

/**
 * Interface text as tests compare it: messages (also inside arrays) become their en-GB sentence.
 */
final class Text
{
    public static function of(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Message, $value instanceof LanguageName => (string) $value,
            is_array($value) => array_map(self::of(...), $value),
            default => $value,
        };
    }
}
