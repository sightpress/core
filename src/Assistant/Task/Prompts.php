<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use LogicException;
use SightPress\Core\Tenant\LocaleCode;

/**
 * The drafting tasks' instructions, in `resources/assistant/prompts/<task>.md` (en-GB, the source). A translation
 * for the language of the answer can sit next to it (`pt-PT/summary.md`, or `pt/summary.md` for every Portuguese).
 * `{language}` becomes the name of the answer's language in English and the other `{name}`s the given values; the
 * rule that page content is data, never instructions ({@see PromptData::RULE}), is always added.
 */
final readonly class Prompts
{
    public function __construct(private string $directory = __DIR__ . '/../../../resources/assistant/prompts')
    {
    }

    /**
     * @param array<string, string|int> $values
     *
     * @throws LogicException when the task has no prompt
     */
    public function system(string $task, string $locale, array $values = []): string
    {
        $language = strtolower((string) strtok($locale, '-'));
        $file = null;
        foreach ([$locale . '/' . $task, $language . '/' . $task, $task] as $candidate) {
            if (preg_match('/^[A-Za-z0-9_\/-]+$/', $candidate) === 1 && is_file($this->directory . '/' . $candidate . '.md')) {
                $file = $this->directory . '/' . $candidate . '.md';
                break;
            }
        }
        if ($file === null) {
            throw new LogicException(sprintf('The assistant task "%s" has no prompt.', $task));
        }
        $replace = ['{language}' => LocaleCode::describe($locale)];
        foreach ($values as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return trim(strtr((string) file_get_contents($file), $replace)) . "\n\n" . PromptData::RULE;
    }
}
