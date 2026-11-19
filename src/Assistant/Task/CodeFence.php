<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

/**
 * An answer without the Markdown code fence models sometimes put around HTML or JSON (```html … ```).
 */
final class CodeFence
{
    public static function strip(string $answer): string
    {
        $answer = trim($answer);

        return preg_match('/^```[a-z]*\s*\n(.*)\n```$/is', $answer, $match) === 1 ? $match[1] : $answer;
    }
}
