<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

/**
 * A suggestion without the quotation marks models like to put around it ("…", “…”, «…»).
 */
final class Unquoted
{
    /**
     * @param non-empty-string $text
     *
     * @return non-empty-string
     */
    public static function of(string $text): string
    {
        if (preg_match('/^(["“«„\'‘])(.+)(["”»“\'’])$/us', $text, $match) === 1 && preg_match('/["“”«»„]/u', $match[2]) === 0) {
            $inner = trim($match[2]);

            return $inner === '' ? $text : $inner;
        }

        return $text;
    }
}
