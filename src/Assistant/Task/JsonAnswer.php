<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use JsonException;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Exception\AssistantException;

/**
 * The JSON object in an answer, also when the model wrapped it in a Markdown code fence or a sentence. Output is
 * untrusted input (ADR-0010): anything that isn't one JSON object is refused.
 */
final class JsonAnswer
{
    /**
     * @return array<string, mixed>
     *
     * @throws AssistantException with {@see AssistantFailure::InvalidOutput}
     */
    public static function object(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The answer had no JSON object.');
        }
        try {
            $data = json_decode(substr($answer, $start, $end - $start + 1), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The answer\'s JSON couldn\'t be read.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The answer\'s JSON wasn\'t an object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * A string member, or a refusal.
     *
     * @param array<string, mixed> $data
     *
     * @throws AssistantException with {@see AssistantFailure::InvalidOutput}
     */
    public static function text(array $data, string $name): string
    {
        $value = $data[$name] ?? null;
        if (!is_string($value)) {
            throw new AssistantException(AssistantFailure::InvalidOutput, sprintf('The answer had no "%s".', $name));
        }

        return $value;
    }
}
