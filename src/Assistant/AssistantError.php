<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use RuntimeException;
use SightPress\Core\Template\Message;
use SightPress\Module\Assistant\AssistantFailure;

/**
 * Why the assistant gave no suggestion, in plain words for the person who asked ({@see $reason}): it isn't set up,
 * they may not use it, its limits are reached, or the AI service failed ({@see $failure}). The exception message is
 * the same text in en-GB; neither ever holds the key, the prompt or the answer. {@see $detail} says more for
 * developers (in en-GB, for example which input field was wrong).
 */
final class AssistantError extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';
    public const string NOT_ALLOWED = 'not_allowed';
    public const string UNKNOWN_TASK = 'unknown_task';
    public const string INVALID_INPUT = 'invalid_input';
    public const string NO_VISION = 'no_vision';
    public const string TOO_MANY = 'too_many';
    public const string BUDGET = 'budget';

    /**
     * @param non-empty-string $outcome one of the constants above, or the {@see AssistantFailure} value
     */
    public function __construct(
        public readonly string $outcome,
        public readonly Message $reason,
        public readonly ?AssistantFailure $failure = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct(self::english($reason));
    }

    /**
     * The message in en-GB, with its values, for the console and the log.
     */
    public static function english(Message $message): string
    {
        $parameters = array_map(static fn (mixed $value): string|int|float|null => is_int($value) || is_float($value) || is_string($value) ? $value : null, $message->parameters);

        return Message::format($message->text, $parameters, 'en-GB');
    }

    public static function failed(AssistantFailure $failure): self
    {
        return new self($failure->value, self::explain($failure), $failure);
    }

    /**
     * What a provider failure means for the person who asked, and what they or an admin can do.
     */
    public static function explain(AssistantFailure $failure): Message
    {
        return new Message(match ($failure) {
            AssistantFailure::InvalidKey => 'The AI service didn’t accept the API key. An admin can replace it on the AI assistant screen.',
            AssistantFailure::QuotaExceeded => 'The account with the AI service has no credit left, or has reached its spending limit. An admin can check it with the AI service.',
            AssistantFailure::RateLimited => 'The AI service is getting too many requests right now. Try again in a minute.',
            AssistantFailure::Timeout => 'The AI service took too long to answer. Try again, perhaps with less text.',
            AssistantFailure::ModelUnavailable => 'The AI service doesn’t offer the chosen model any more. An admin can choose another one on the AI assistant screen.',
            AssistantFailure::Refused => 'The AI service declined to help with this request. Try asking in another way.',
            AssistantFailure::InvalidOutput => 'The suggestion couldn’t be used: it wasn’t in the expected form, or had something in it that isn’t allowed. Try again.',
            AssistantFailure::Unavailable => 'The AI service can’t be reached right now. Try again later.',
        });
    }
}
