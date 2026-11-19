<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Classification;

/**
 * A crawler or other program, by the name it gives in its user agent and its kind (see `resources/analytics/bots.php`).
 */
final readonly class Bot
{
    public const string AI_SEARCH = 'ai_search';
    public const string AI_TRAINING = 'ai_training';
    public const string SEARCH = 'search';
    public const string OTHER = 'other';

    public function __construct(
        public string $name,
        public string $kind,
    ) {
    }
}
