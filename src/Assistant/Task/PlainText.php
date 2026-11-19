<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Exception\AssistantException;

/**
 * Turns an answer into plain text for a suggestion: markup removed, white space tidied, and refused when it is empty
 * or longer than allowed. Output is untrusted input (ADR-0010).
 */
final class PlainText
{
    /**
     * @param positive-int $maxLength
     *
     * @return non-empty-string
     *
     * @throws AssistantException with {@see AssistantFailure::InvalidOutput}
     */
    public static function of(string $answer, int $maxLength): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $answer) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\r\n?/", "\n", $text) ?? '') ?? '');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        if ($text === '') {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The answer had no text.');
        }
        if (mb_strlen($text) > $maxLength) {
            throw new AssistantException(AssistantFailure::InvalidOutput, sprintf('The answer was longer than %d characters.', $maxLength));
        }

        return $text;
    }
}
