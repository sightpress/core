<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Exception\AssistantException;

/**
 * An assistant task for tests: echoes `text`, refuses answers that say "bad", and may need vision or a permission.
 */
final readonly class FakeTask implements AssistantTask
{
    public function __construct(
        private string $id = 'fake_task',
        private string $permission = 'assistant.use',
        private bool $vision = false,
    ) {
    }

    public function id(): string
    {
        return $this->id === '' ? 'fake_task' : $this->id;
    }

    public function label(): string
    {
        return 'Do the fake thing';
    }

    public function permission(): string
    {
        return $this->permission === '' ? 'assistant.use' : $this->permission;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50]], 'required' => ['text'], 'additionalProperties' => false];
    }

    public function needsVision(): bool
    {
        return $this->vision;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        return [AssistantMessage::system('Be fake in ' . $context->locale() . '.'), AssistantMessage::user(is_string($input['text'] ?? null) && $input['text'] !== '' ? $input['text'] : '?')];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        if (str_contains($response->text, 'bad')) {
            throw new AssistantException(AssistantFailure::InvalidOutput);
        }

        return new AssistantSuggestion($response->text, ['field' => $response->text]);
    }
}
