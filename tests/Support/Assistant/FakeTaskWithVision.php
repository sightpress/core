<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * A {@see FakeTask} that needs a model that can see pictures (a class of its own, so tests can register both).
 */
final readonly class FakeTaskWithVision implements AssistantTask
{
    private FakeTask $task;

    public function __construct()
    {
        $this->task = new FakeTask('fake_vision_task', vision: true);
    }

    public function id(): string
    {
        return $this->task->id();
    }

    public function label(): string
    {
        return 'Look at the fake picture';
    }

    public function permission(): string
    {
        return $this->task->permission();
    }

    public function inputSchema(): array
    {
        return $this->task->inputSchema();
    }

    public function needsVision(): bool
    {
        return true;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        return $this->task->buildPrompt($input, $context);
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        return $this->task->validateOutput($response, $input, $context);
    }
}
