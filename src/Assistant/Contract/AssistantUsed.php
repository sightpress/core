<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Contract;

/**
 * Someone used the AI assistant (or `site:assistant:test` tried the connection); the audit log records who, which
 * task, provider and model, the tokens and the outcome. Never the prompt or the answer.
 */
final readonly class AssistantUsed
{
    /**
     * @param non-empty-string $outcome `ok`, or why it failed
     */
    public function __construct(
        public ?int $actorId,
        public string $task,
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $outcome,
    ) {
    }
}
