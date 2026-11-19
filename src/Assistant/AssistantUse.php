<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use DateTimeImmutable;
use SightPress\Module\Assistant\TokenUsage;

/**
 * One use of the assistant, as `assistant_usage` keeps it: no prompt and no answer.
 */
final readonly class AssistantUse
{
    public const string OK = 'ok';

    /**
     * @param non-empty-string $outcome {@see OK}, or the {@see \SightPress\Module\Assistant\AssistantFailure} value
     */
    public function __construct(
        public DateTimeImmutable $at,
        public ?int $userId,
        public string $task,
        public string $provider,
        public string $model,
        public TokenUsage $usage,
        public string $outcome,
    ) {
    }

    /**
     * The month the use counts towards (UTC), as `YYYY-MM`.
     */
    public function month(): string
    {
        return self::monthOf($this->at);
    }

    public static function monthOf(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
    }
}
