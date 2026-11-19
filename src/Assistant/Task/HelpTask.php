<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Assistant\Help\HelpIndex;
use SightPress\Core\Assistant\Help\HelpIndexBuilder;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;

/**
 * `help`: answers a question about using SightPress from the documentation (A-5): the parts of the help index that
 * match the question and that the person may use ({@see HelpIndex}) go into the prompt as data, with the screen they
 * are on and their permissions, so answers only point to screens and commands they can use. Every answer ends with
 * at least one link to the documentation; without one, the best part's link is added.
 */
final readonly class HelpTask implements AssistantTask
{
    public const string ID = 'help';

    public const int MAX_QUESTION = 2000;

    public const int MAX_ANSWER = 6000;

    public function __construct(
        private Prompts $prompts,
        private HelpIndex $index,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Help with SightPress';
    }

    public function permission(): string
    {
        return AssistantModule::USE;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_QUESTION],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 100], 'maxItems' => 200],
            ],
            'required' => ['question'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $question = is_string($input['question'] ?? null) ? $input['question'] : '';
        $permissions = is_array($input['permissions'] ?? null) ? array_values(array_filter($input['permissions'], static fn (mixed $name): bool => is_string($name) && $context->can($name))) : [];
        $found = $this->found($question, $context);
        $parts = array_map(
            static fn (array $chunk): string => PromptData::block('documentation', $chunk['page'] . ($chunk['heading'] === '' ? '' : ' — ' . $chunk['heading']) . "\n" . $chunk['url'] . "\n\n" . $chunk['text']),
            $found,
        );

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), [
                'screen' => $context->screen() ?? 'admin',
                'permissions' => $permissions === [] ? 'use the admin panel' : implode(', ', $permissions),
            ])),
            AssistantMessage::user(($parts === [] ? PromptData::block('documentation', 'Nothing in the documentation matches this question.') : implode("\n\n", $parts)) . "\n\nTheir question:\n" . PromptData::block('question', $question)),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $answer = PlainText::of($response->text, self::MAX_ANSWER);
        $found = $this->found(is_string($input['question'] ?? null) ? $input['question'] : '', $context);
        $links = array_map(static fn (array $chunk): string => $chunk['url'], $found);
        if (!str_contains($answer, HelpIndexBuilder::BASE_URL)) {
            $answer .= "\n\n" . ($links[0] ?? HelpIndexBuilder::BASE_URL);
        }

        return new AssistantSuggestion($answer, ['links' => implode("\n", $links)]);
    }

    /**
     * @return list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>
     */
    private function found(string $question, AssistantContext $context): array
    {
        return $this->index->search($question, $context->can(...));
    }
}
